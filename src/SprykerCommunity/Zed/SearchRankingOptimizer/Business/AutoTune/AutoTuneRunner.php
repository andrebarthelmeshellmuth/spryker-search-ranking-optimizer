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
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSymfonyMailerFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

class AutoTuneRunner implements AutoTuneRunnerInterface
{
    /**
     * @var string
     */
    protected const MAIL_TEMPLATE_NAME = 'SearchRankingOptimizer/Mail/auto-tune-summary.html.twig';

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolverInterface $recipientResolver
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSymfonyMailerFacadeInterface $symfonyMailerFacade
     */
    public function __construct(
        protected SearchRankingOptimizerRepositoryInterface $repository,
        protected SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
        protected AutoTuneNotificationRecipientResolverInterface $recipientResolver,
        protected SearchRankingOptimizerToSymfonyMailerFacadeInterface $symfonyMailerFacade,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @return \Generated\Shared\Transfer\SearchRankingAutoTuneResultTransfer
     */
    public function run(): SearchRankingAutoTuneResultTransfer
    {
        $resultTransfer = new SearchRankingAutoTuneResultTransfer();
        $metricResultsToNotify = [];

        foreach ($this->repository->findAutoTuneMetricConfigsWithThresholdSet() as $autoTuneMetricConfigTransfer) {
            $metricResultTransfer = $this->processMetric($autoTuneMetricConfigTransfer);

            if ($metricResultTransfer === null) {
                continue;
            }

            $resultTransfer->addMetricResult($metricResultTransfer);

            if (!$metricResultTransfer->getWasThresholdMet() && $autoTuneMetricConfigTransfer->getIsNotifyEnabledOrFail()) {
                $metricResultsToNotify[] = $metricResultTransfer;
            }
        }

        $notifiedEmailCount = $metricResultsToNotify === [] ? 0 : $this->sendSummaryEmail($metricResultsToNotify);

        return $resultTransfer->setNotifiedEmailCount($notifiedEmailCount);
    }

    /**
     * Returns null when the metric has been deleted since its config was set, or has no digest yet —
     * both safe, silent skips, never an error.
     *
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingAutoTuneMetricResultTransfer|null
     */
    protected function processMetric(
        SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer,
    ): ?SearchRankingAutoTuneMetricResultTransfer {
        $idSearchRankingMetric = $autoTuneMetricConfigTransfer->getIdSearchRankingMetricOrFail();
        $metric = $this->searchRankingFacade->findMetricDetail($idSearchRankingMetric);

        if ($metric === null) {
            return null;
        }

        $currentFitRSquared = $this->searchRankingFacade->evaluateCurrentMetricFit($idSearchRankingMetric);

        if ($currentFitRSquared === null) {
            return null;
        }

        $metricResultTransfer = (new SearchRankingAutoTuneMetricResultTransfer())
            ->setIdSearchRankingMetric($idSearchRankingMetric)
            ->setMetricName($metric['name'])
            ->setBeforeFormula($metric['formula'])
            ->setBeforeFitRSquared($currentFitRSquared);

        if ($currentFitRSquared >= $autoTuneMetricConfigTransfer->getAutoTuneThresholdOrFail()) {
            $this->searchRankingFacade->recordMetricCheckOnly($idSearchRankingMetric);

            return $metricResultTransfer->setWasThresholdMet(true)->setWasApplied(false);
        }

        return $this->refit($metricResultTransfer, $metric, $autoTuneMetricConfigTransfer);
    }

    // phpcs:disable Spryker.Commenting.DocBlockParamAllowDefaultValue.Typehint -- misreads this shaped array docblock as a default-value typehint check

    /**
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricResultTransfer $metricResultTransfer
     * @param array{idSearchRankingMetric: int, name: string, formula: string, isHigherBetter: bool, shape: string|null} $metric
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingAutoTuneMetricResultTransfer
     */
    protected function refit(
        SearchRankingAutoTuneMetricResultTransfer $metricResultTransfer,
        array $metric,
        SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer,
    ): SearchRankingAutoTuneMetricResultTransfer {
        $idSearchRankingMetric = $metric['idSearchRankingMetric'];
        $metricResultTransfer->setWasThresholdMet(false);

        $candidates = $this->searchRankingFacade->getFitCandidates($idSearchRankingMetric);
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
            $wasApplied = $this->searchRankingFacade->saveMetricFormula($idSearchRankingMetric, $chosenCandidate['formula']);
        }

        if (!$wasApplied) {
            $this->searchRankingFacade->recordMetricCheckOnly($idSearchRankingMetric);
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
     *
     * @return int
     */
    protected function sendSummaryEmail(array $metricResultTransfers): int
    {
        $recipientEmails = $this->recipientResolver->resolve();

        if ($recipientEmails === []) {
            return 0;
        }

        $anyApplied = false;

        foreach ($metricResultTransfers as $metricResultTransfer) {
            if ($metricResultTransfer->getWasApplied()) {
                $anyApplied = true;
            }
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
