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

            $configsByMetricAndLocale = $this->groupConfigsByMetric(
                $this->repository->findAutoTuneMetricConfigsWithThresholdSet($storeName),
            );

            foreach ($configsByMetricAndLocale as $autoTuneMetricConfigsByLocale) {
                foreach ($this->processMetricSafely($autoTuneMetricConfigsByLocale, $storeTransfer) as $metricResultTransfer) {
                    $resultTransfer->addMetricResult($metricResultTransfer);

                    $autoTuneMetricConfigTransfer = $autoTuneMetricConfigsByLocale[$metricResultTransfer->getLocaleNameOrFail()] ?? null;

                    if ($metricResultTransfer->getWasThresholdMet() || $autoTuneMetricConfigTransfer === null || !$autoTuneMetricConfigTransfer->getIsNotifyEnabledOrFail()) {
                        continue;
                    }

                    $metricResultsToNotify[] = $metricResultTransfer;
                }
            }
        }

        $notifiedEmailCount = $metricResultsToNotify === [] ? 0 : $this->sendSummaryEmail($metricResultsToNotify);

        return $resultTransfer->setNotifiedEmailCount($notifiedEmailCount);
    }

    /**
     * {@see SearchRankingOptimizerRepositoryInterface::findAutoTuneMetricConfigsWithThresholdSet()} returns
     * one row per (metric, locale) with a threshold set, flat across the whole store — grouped back up
     * here by metric, keyed by locale within each group, so {@see processMetric()} can look up exactly the
     * locale it's currently checking instead of assuming a single config applies everywhere.
     *
     * @phpstan-return array<int, non-empty-array<string, \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer>>
     *
     * @param array<\Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer> $autoTuneMetricConfigTransfers
     *
     * @return array<int, array<string, \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer>>
     */
    protected function groupConfigsByMetric(array $autoTuneMetricConfigTransfers): array
    {
        $configsByMetricAndLocale = [];

        foreach ($autoTuneMetricConfigTransfers as $autoTuneMetricConfigTransfer) {
            $configsByMetricAndLocale[$autoTuneMetricConfigTransfer->getIdSearchRankingMetricOrFail()][$autoTuneMetricConfigTransfer->getLocaleNameOrFail()] = $autoTuneMetricConfigTransfer;
        }

        return $configsByMetricAndLocale;
    }

    /**
     * One metric's `evaluateCurrentMetricFitAcrossLocales()`/`getFitCandidates()`/`saveMetricFormula()`
     * calls all reach out to search-ranking's own facade (ultimately Elasticsearch/Propel) -- an
     * unexpected failure there (e.g. ES temporarily unreachable) must never abort the whole run: every
     * OTHER metric with a threshold set still deserves its check this month. Caught here, at the single
     * call site, rather than inside {@see processMetric()} itself, so that method's own control flow stays
     * a plain "safe, silent skip vs. real results" shape, uncomplicated by exception handling. The error
     * result is anchored to the store's own default locale — a failure this early (e.g. the very first
     * facade call) means isLocaleScoped isn't known yet, so there is no real per-locale fan-out to report
     * against; a single error entry is enough to surface the failure without inventing scope it doesn't
     * have. $autoTuneMetricConfigsByLocale is never empty ({@see groupConfigsByMetric()} only ever creates
     * non-empty groups), so `reset()` always finds a real config to read the metric id from.
     *
     * @phpstan-param non-empty-array<string, \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer> $autoTuneMetricConfigsByLocale
     *
     * @param array<string, \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer> $autoTuneMetricConfigsByLocale
     * @param \Generated\Shared\Transfer\StoreTransfer $storeTransfer
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingAutoTuneMetricResultTransfer>
     */
    protected function processMetricSafely(
        array $autoTuneMetricConfigsByLocale,
        StoreTransfer $storeTransfer,
    ): array {
        try {
            return $this->processMetric($autoTuneMetricConfigsByLocale, $storeTransfer);
        } catch (Throwable $throwable) {
            $idSearchRankingMetric = reset($autoTuneMetricConfigsByLocale)->getIdSearchRankingMetricOrFail();

            return [
                (new SearchRankingAutoTuneMetricResultTransfer())
                    ->setIdSearchRankingMetric($idSearchRankingMetric)
                    ->setStoreName($storeTransfer->getNameOrFail())
                    ->setLocaleName($storeTransfer->getDefaultLocaleIsoCodeOrFail())
                    ->setMetricName(sprintf('metric #%d', $idSearchRankingMetric))
                    ->setErrorMessage($throwable->getMessage()),
            ];
        }
    }

    /**
     * Returns an empty array when the metric has been deleted since its config was set, or has no digest
     * anywhere yet — both safe, silent skips, never an error.
     *
     * For a store-wide metric (`isLocaleScoped=false`, the common case), returns exactly ONE result, at
     * the store's own default locale — a refit/apply for it fans out to every real locale of the store on
     * search-ranking's own side regardless of which one locale was checked here, so checking every locale
     * independently would be redundant work, AND actively wrong: an independent refit per locale would
     * refit against each locale's own digest and re-fan-out on every iteration, leaving whichever locale
     * was processed LAST to silently overwrite every earlier one — an order-dependent, non-deterministic
     * final formula, not a real improvement. Its config is read from $autoTuneMetricConfigsByLocale's own
     * default-locale entry — guaranteed present if the metric is in this group at all, since
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneMetricConfigWriterInterface}
     * always fans a store-wide metric's save out to every real locale, default included.
     *
     * For a genuinely locale-scoped metric (`isLocaleScoped=true`, rare), returns one result per REAL
     * locale of the store that BOTH has a digest AND has its own config in $autoTuneMetricConfigsByLocale —
     * each checked/refit/applied fully independently, since a save for one locale never touches another on
     * search-ranking's own side any more, and neither does an auto-tune config save now. A locale with no
     * digest yet, or no config of its own (never individually opted in — same "absence means opted out"
     * contract $autoTuneMetricConfigsByLocale's own presence-per-metric already relies on, deliberately NOT
     * falling back to the default locale's config here), is skipped for that locale only, not treated as a
     * reason to skip the whole metric.
     *
     * @phpstan-param non-empty-array<string, \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer> $autoTuneMetricConfigsByLocale
     *
     * @param array<string, \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer> $autoTuneMetricConfigsByLocale
     * @param \Generated\Shared\Transfer\StoreTransfer $storeTransfer
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingAutoTuneMetricResultTransfer>
     */
    protected function processMetric(
        array $autoTuneMetricConfigsByLocale,
        StoreTransfer $storeTransfer,
    ): array {
        $idSearchRankingMetric = reset($autoTuneMetricConfigsByLocale)->getIdSearchRankingMetricOrFail();
        $storeName = $storeTransfer->getNameOrFail();
        $defaultLocaleName = $storeTransfer->getDefaultLocaleIsoCodeOrFail();

        $metric = $this->searchRankingFacade->findMetricDetail($idSearchRankingMetric, $storeName, $defaultLocaleName);

        if ($metric === null) {
            return [];
        }

        // The single source of "current fit" from here on, for BOTH branches below — evaluated once per
        // real locale of the store, never recomputed per locale a second time. For the store-wide branch
        // this map is also exactly the divergence diagnostic {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerAutoTuneConsole::reportLocaleDivergence()}
        // surfaces — the evidence for whether this metric should become locale-scoped in the first place.
        $fitRSquaredByLocale = $this->searchRankingFacade->evaluateCurrentMetricFitAcrossLocales($idSearchRankingMetric, $storeName);

        if (!$metric['isLocaleScoped']) {
            $currentFitRSquared = $fitRSquaredByLocale[$defaultLocaleName] ?? null;
            $autoTuneMetricConfigTransfer = $autoTuneMetricConfigsByLocale[$defaultLocaleName] ?? null;

            if ($currentFitRSquared === null || $autoTuneMetricConfigTransfer === null) {
                return [];
            }

            return [
                $this->processMetricAtScope(
                    $autoTuneMetricConfigTransfer,
                    $metric,
                    $storeName,
                    $defaultLocaleName,
                    $currentFitRSquared,
                    $fitRSquaredByLocale,
                ),
            ];
        }

        $metricResultTransfers = [];

        foreach ($fitRSquaredByLocale as $localeName => $currentFitRSquared) {
            if ($currentFitRSquared === null) {
                continue;
            }

            $autoTuneMetricConfigTransfer = $autoTuneMetricConfigsByLocale[$localeName] ?? null;

            if ($autoTuneMetricConfigTransfer === null) {
                continue;
            }

            // Re-fetched per locale (not reused from the default-locale $metric above) -- for a genuinely
            // locale-scoped metric, formula/shape are each locale's own independent value, not the default
            // locale's fanned-out one.
            $localeMetric = $localeName === $defaultLocaleName
                ? $metric
                : $this->searchRankingFacade->findMetricDetail($idSearchRankingMetric, $storeName, $localeName);

            if ($localeMetric === null) {
                continue;
            }

            // fitRSquaredByLocale is deliberately NOT attached per row here (unlike the store-wide branch
            // above) -- every real locale already gets its own full result row, so repeating the same
            // cross-locale map on each one would be redundant, and reportLocaleDivergence()'s own
            // "count < 2" guard already skips a divergence warning wherever this is left unset (empty).
            $metricResultTransfers[] = $this->processMetricAtScope(
                $autoTuneMetricConfigTransfer,
                $localeMetric,
                $storeName,
                $localeName,
                $currentFitRSquared,
                [],
            );
        }

        return $metricResultTransfers;
    }

    // phpcs:disable Spryker.Commenting.DocBlockParamAllowDefaultValue.Typehint -- misreads this shaped array docblock as a default-value typehint check

    /**
     * The threshold-check / determinism-check / refit body shared by every (metric, store, locale) this
     * runner ever produces a result for — extracted so {@see processMetric()}'s own store-wide-vs-fan-out
     * branching stays free of this method's own unrelated complexity.
     *
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     * @param array{idSearchRankingMetric: int, name: string, formula: string, isHigherBetter: bool, shape: string|null, isLocaleScoped: bool} $metric
     * @param string $storeName
     * @param string $localeName
     * @param float $currentFitRSquared
     * @param array<string, float|null> $fitRSquaredByLocale
     */
    protected function processMetricAtScope(
        SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer,
        array $metric,
        string $storeName,
        string $localeName,
        float $currentFitRSquared,
        array $fitRSquaredByLocale,
    ): SearchRankingAutoTuneMetricResultTransfer {
        $idSearchRankingMetric = $metric['idSearchRankingMetric'];

        $metricResultTransfer = (new SearchRankingAutoTuneMetricResultTransfer())
            ->setIdSearchRankingMetric($idSearchRankingMetric)
            ->setStoreName($storeName)
            ->setLocaleName($localeName)
            ->setMetricName($metric['name'])
            ->setBeforeFormula($metric['formula'])
            ->setBeforeFitRSquared($currentFitRSquared)
            ->setFitRSquaredByLocale($fitRSquaredByLocale)
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

    // phpcs:enable Spryker.Commenting.DocBlockParamAllowDefaultValue.Typehint

    // phpcs:disable Spryker.Commenting.DocBlockParamAllowDefaultValue.Typehint -- misreads this shaped array docblock as a default-value typehint check

    /**
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricResultTransfer $metricResultTransfer
     * @param array{idSearchRankingMetric: int, name: string, formula: string, isHigherBetter: bool, shape: string|null, isLocaleScoped: bool} $metric
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
