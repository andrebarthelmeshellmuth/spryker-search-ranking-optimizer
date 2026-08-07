<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune;

use Generated\Shared\Transfer\MailRecipientTransfer;
use Generated\Shared\Transfer\MailSenderTransfer;
use Generated\Shared\Transfer\MailTemplateTransfer;
use Generated\Shared\Transfer\MailTransfer;
use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Generated\Shared\Transfer\SearchRankingAutoTuneMetricResultTransfer;
use Generated\Shared\Transfer\SearchRankingAutoTuneResultTransfer;
use Generated\Shared\Transfer\StoreTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismCheckerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToStoreFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSymfonyMailerFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;
use Throwable;

class AutoTuneRunner implements AutoTuneRunnerInterface
{
    /**
     * @var string
     */
    protected const MAIL_TEMPLATE_NAME = 'SearchRankingOptimizer/Mail/auto-tune-summary.html.twig';

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToStoreFacadeInterface $storeFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolverInterface $recipientResolver
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSymfonyMailerFacadeInterface $symfonyMailerFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismCheckerInterface $formulaDeterminismChecker
     */
    public function __construct(
        protected SearchRankingOptimizerRepositoryInterface $repository,
        protected SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
        protected SearchRankingOptimizerToStoreFacadeInterface $storeFacade,
        protected AutoTuneNotificationRecipientResolverInterface $recipientResolver,
        protected SearchRankingOptimizerToSymfonyMailerFacadeInterface $symfonyMailerFacade,
        protected FormulaDeterminismCheckerInterface $formulaDeterminismChecker,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * Genuinely per-store: every real store returned by {@see SearchRankingOptimizerToStoreFacadeInterface::getAllStores()}
     * gets its own independent pass over ITS OWN threshold-set metric configs — a store that has never had
     * `search-ranking` set up for it ({@see SearchRankingOptimizerToSearchRankingFacadeInterface::hasStoreConfiguration()}
     * returns false) is skipped entirely rather than evaluated against empty/default state. One combined
     * summary email still covers every store's results from this single run, not one email per store.
     */
    public function run(): SearchRankingAutoTuneResultTransfer
    {
        $resultTransfer = new SearchRankingAutoTuneResultTransfer();
        $metricResultsToNotify = [];

        foreach ($this->storeFacade->getAllStores() as $storeTransfer) {
            $storeName = $storeTransfer->getNameOrFail();

            if (!$this->searchRankingFacade->hasStoreConfiguration($storeName)) {
                continue;
            }

            foreach ($this->repository->findAutoTuneMetricConfigsWithThresholdSet($storeName) as $autoTuneMetricConfigTransfer) {
                $metricResultTransfer = $this->processMetricSafely($autoTuneMetricConfigTransfer, $storeTransfer);

                if ($metricResultTransfer === null) {
                    continue;
                }

                $resultTransfer->addMetricResult($metricResultTransfer);

                if ($metricResultTransfer->getWasThresholdMet() || !$autoTuneMetricConfigTransfer->getIsNotifyEnabledOrFail()) {
                    continue;
                }

                $metricResultsToNotify[] = $metricResultTransfer;
            }
        }

        $notifiedEmailCount = $metricResultsToNotify === [] ? 0 : $this->sendSummaryEmail($metricResultsToNotify);

        return $resultTransfer->setNotifiedEmailCount($notifiedEmailCount);
    }

    /**
     * One metric's `evaluateCurrentMetricFit()`/`getFitCandidates()`/`saveMetricFormula()` calls all reach
     * out to search-ranking's own facade (ultimately Elasticsearch/Propel) -- an unexpected failure there
     * (e.g. ES temporarily unreachable) must never abort the whole run: every OTHER metric with a
     * threshold set still deserves its check this month. Caught here, at the single call site, rather than
     * inside {@see processMetric()} itself, so that method's own early-return control flow stays a plain
     * "safe, silent skip vs. real result" shape, uncomplicated by exception handling.
     *
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     * @param \Generated\Shared\Transfer\StoreTransfer $storeTransfer
     */
    protected function processMetricSafely(
        SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer,
        StoreTransfer $storeTransfer,
    ): ?SearchRankingAutoTuneMetricResultTransfer {
        try {
            return $this->processMetric($autoTuneMetricConfigTransfer, $storeTransfer);
        } catch (Throwable $throwable) {
            return (new SearchRankingAutoTuneMetricResultTransfer())
                ->setIdSearchRankingMetric($autoTuneMetricConfigTransfer->getIdSearchRankingMetricOrFail())
                ->setStoreName($storeTransfer->getNameOrFail())
                ->setMetricName(sprintf('metric #%d', $autoTuneMetricConfigTransfer->getIdSearchRankingMetricOrFail()))
                ->setErrorMessage($throwable->getMessage());
        }
    }

    /**
     * Returns null when the metric has been deleted since its config was set, or has no digest yet —
     * both safe, silent skips, never an error.
     *
     * The store to check is $storeTransfer, resolved by the caller from every real configured store —
     * formula/shape are store-only on `search-ranking`'s own side (not locale-scoped), but the digest
     * fit computation still needs A locale, so this uses the store's own configured default
     * ({@see \Generated\Shared\Transfer\StoreTransfer::getDefaultLocaleIsoCodeOrFail()}) rather than
     * re-deriving one from project config.
     *
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     * @param \Generated\Shared\Transfer\StoreTransfer $storeTransfer
     */
    protected function processMetric(
        SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer,
        StoreTransfer $storeTransfer,
    ): ?SearchRankingAutoTuneMetricResultTransfer {
        $idSearchRankingMetric = $autoTuneMetricConfigTransfer->getIdSearchRankingMetricOrFail();
        $storeName = $storeTransfer->getNameOrFail();
        $localeName = $storeTransfer->getDefaultLocaleIsoCodeOrFail();

        $metric = $this->searchRankingFacade->findMetricDetail($idSearchRankingMetric, $storeName, $localeName);

        if ($metric === null) {
            return null;
        }

        $currentFitRSquared = $this->searchRankingFacade->evaluateCurrentMetricFit($idSearchRankingMetric, $storeName, $localeName);

        if ($currentFitRSquared === null) {
            return null;
        }

        $metricResultTransfer = (new SearchRankingAutoTuneMetricResultTransfer())
            ->setIdSearchRankingMetric($idSearchRankingMetric)
            ->setStoreName($storeName)
            ->setMetricName($metric['name'])
            ->setBeforeFormula($metric['formula'])
            ->setBeforeFitRSquared($currentFitRSquared)
            ->setWasSkippedNonDeterministic(false);

        if ($currentFitRSquared >= $autoTuneMetricConfigTransfer->getAutoTuneThresholdOrFail()) {
            $this->searchRankingFacade->recordMetricCheckOnly($idSearchRankingMetric, $storeName, $localeName);

            return $metricResultTransfer->setWasThresholdMet(true)->setWasApplied(false);
        }

        // A non-deterministic formula (e.g. a placeholder/noise metric) still gets checked and shows up
        // in history/the summary email with its real (likely persistently bad) fit -- that observation is
        // legitimate. What it never gets is a refit: fitting a "better" curve to noise would just overfit
        // to whatever randomness happened to be in THIS digest snapshot, then silently swap in a formula
        // that looks like a real fit but carries no more signal than random() did.
        if (!$this->formulaDeterminismChecker->isDeterministic($metric['formula'])) {
            $this->searchRankingFacade->recordMetricCheckOnly($idSearchRankingMetric, $storeName, $localeName);

            return $metricResultTransfer->setWasThresholdMet(false)->setWasSkippedNonDeterministic(true)->setWasApplied(false);
        }

        return $this->refit($metricResultTransfer, $metric, $autoTuneMetricConfigTransfer, $storeName, $localeName);
    }

    // phpcs:disable Spryker.Commenting.DocBlockParamAllowDefaultValue.Typehint -- misreads this shaped array docblock as a default-value typehint check

    /**
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricResultTransfer $metricResultTransfer
     * @param array{idSearchRankingMetric: int, name: string, formula: string, isHigherBetter: bool, shape: string|null} $metric
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     * @param string $storeName
     * @param string $localeName
     */
    protected function refit(
        SearchRankingAutoTuneMetricResultTransfer $metricResultTransfer,
        array $metric,
        SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer,
        string $storeName,
        string $localeName,
    ): SearchRankingAutoTuneMetricResultTransfer {
        $idSearchRankingMetric = $metric['idSearchRankingMetric'];
        $metricResultTransfer->setWasThresholdMet(false);

        $candidates = $this->searchRankingFacade->getFitCandidates($idSearchRankingMetric, $storeName, $localeName);
        $chosenCandidate = $this->chooseCandidate($candidates, $autoTuneMetricConfigTransfer->getAutoUpdateScopeOrFail(), $metric['shape']);

        if ($chosenCandidate === null) {
            return $metricResultTransfer->setWasApplied(false);
        }

        $metricResultTransfer
            ->setAfterFormula($chosenCandidate['formula'])
            ->setAfterShape($chosenCandidate['shape'])
            ->setAfterFitRSquared($chosenCandidate['rSquared']);

        $wasApplied = false;

        if ($autoTuneMetricConfigTransfer->getIsAutoUpdateEnabledOrFail()) {
            $wasApplied = $this->searchRankingFacade->saveMetricFormula(
                $idSearchRankingMetric,
                $chosenCandidate['formula'],
                $storeName,
                $localeName,
            );
        }

        if (!$wasApplied) {
            $this->searchRankingFacade->recordMetricCheckOnly($idSearchRankingMetric, $storeName, $localeName);
        }

        return $metricResultTransfer->setWasApplied($wasApplied);
    }

    // phpcs:enable Spryker.Commenting.DocBlockParamAllowDefaultValue.Typehint

    /**
     * PARAMETERS_ONLY looks for a candidate matching the metric's own current `shape`; falls back to the
     * overall best-fitting candidate (program's-choice behavior) when the metric has no known shape
     * (a freeform/custom formula) or no candidate matches it — a metric with a custom formula still gets
     * a real refit instead of silently doing nothing.
     *
     * @param array<int, array{shape: string, formula: string, rSquared: float, isWinner: bool}> $candidates
     * @param string $autoUpdateScope
     * @param string|null $currentShape
     *
     * @return array{shape: string, formula: string, rSquared: float, isWinner: bool}|null
     */
    protected function chooseCandidate(array $candidates, string $autoUpdateScope, ?string $currentShape): ?array
    {
        if ($candidates === []) {
            return null;
        }

        if ($autoUpdateScope === SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY && $currentShape !== null) {
            foreach ($candidates as $candidate) {
                if ($candidate['shape'] === $currentShape) {
                    return $candidate;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate['isWinner']) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /**
     * @param array<\Generated\Shared\Transfer\SearchRankingAutoTuneMetricResultTransfer> $metricResultTransfers
     */
    protected function sendSummaryEmail(array $metricResultTransfers): int
    {
        $recipientEmails = $this->recipientResolver->resolve();

        if ($recipientEmails === []) {
            return 0;
        }

        $anyApplied = false;

        foreach ($metricResultTransfers as $metricResultTransfer) {
            if (!$metricResultTransfer->getWasApplied()) {
                continue;
            }

            $anyApplied = true;
        }

        $mailTransfer = (new MailTransfer())
            ->setSender(new MailSenderTransfer())
            ->setSubject(sprintf('Search ranking auto-tune: %d metric(s) need attention', count($metricResultTransfers)))
            ->addTemplate((new MailTemplateTransfer())->setName(static::MAIL_TEMPLATE_NAME)->setIsHtml(true))
            ->setAutoTuneAnyApplied($anyApplied);

        foreach ($metricResultTransfers as $metricResultTransfer) {
            $mailTransfer->addAutoTuneMetricResult($metricResultTransfer);
        }

        foreach ($recipientEmails as $email) {
            $mailTransfer->addRecipient((new MailRecipientTransfer())->setEmail($email));
        }

        $this->symfonyMailerFacade->send($mailTransfer);

        return count($recipientEmails);
    }
}
