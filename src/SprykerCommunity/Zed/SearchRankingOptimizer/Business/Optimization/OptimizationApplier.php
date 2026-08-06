<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig as SharedSearchRankingConfig;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorderInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\MetricNoLongerExistsException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

class OptimizationApplier implements OptimizationApplierInterface
{
    protected SearchRankingOptimizerRepositoryInterface $repository;

    protected SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade;

    protected WeightCheckpointRecorderInterface $recorder;

    protected SearchRankingOptimizerEntityManagerInterface $entityManager;

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorderInterface $recorder
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     */
    public function __construct(
        SearchRankingOptimizerRepositoryInterface $repository,
        SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
        WeightCheckpointRecorderInterface $recorder,
        SearchRankingOptimizerEntityManagerInterface $entityManager,
    ) {
        $this->repository = $repository;
        $this->searchRankingFacade = $searchRankingFacade;
        $this->recorder = $recorder;
        $this->entityManager = $entityManager;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $idSearchRankingOptimizerRun
     */
    public function apply(int $idSearchRankingOptimizerRun): ?SearchRankingOptimizerRunTransfer
    {
        $optimizerRunTransfer = $this->repository->findOptimizerRunById($idSearchRankingOptimizerRun);

        if ($optimizerRunTransfer === null || $optimizerRunTransfer->getStatus() !== SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE) {
            return null;
        }

        try {
            $this->entityManager->getTransactionHandler()->handleTransaction(
                fn () => $this->applyWithinTransaction($optimizerRunTransfer, $idSearchRankingOptimizerRun),
            );
        } catch (MetricNoLongerExistsException) {
            return null;
        }

        return $this->repository->findOptimizerRunById($idSearchRankingOptimizerRun);
    }

    /**
     * Runs entirely inside {@see apply()}'s transaction: the checkpoint is recorded FIRST, snapshotting
     * the real pre-apply state as an actual way back, before anything about this run's candidate values
     * touches a live setting. If any metric this run wants to write no longer exists, throws rather than
     * silently skipping it — the whole transaction (checkpoint included) rolls back, so a shop never ends
     * up with live metric weights that no longer sum to 1 with `appliedAt` claiming success anyway.
     *
     * @param \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer $optimizerRunTransfer
     * @param int $idSearchRankingOptimizerRun
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\MetricNoLongerExistsException
     */
    protected function applyWithinTransaction(
        SearchRankingOptimizerRunTransfer $optimizerRunTransfer,
        int $idSearchRankingOptimizerRun,
    ): void {
        $storeName = $optimizerRunTransfer->getStoreNameOrFail();
        $localeName = $optimizerRunTransfer->getLocaleNameOrFail();

        $this->recorder->record(SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_OPTIMIZER, $storeName, $localeName);

        $this->searchRankingFacade->saveRelevanceWeight($storeName, $localeName, $optimizerRunTransfer->getBestRelevanceWeightOrFail());
        $this->searchRankingFacade->saveSpecificityBlendWeight($storeName, $localeName, $optimizerRunTransfer->getBestSpecificityBlendWeightOrFail());
        $this->searchRankingFacade->saveSpecificityCurveExponent($storeName, $localeName, $optimizerRunTransfer->getBestSpecificityCurveExponentOrFail());
        $this->searchRankingFacade->saveSpecificityWeightExponent($storeName, $localeName, $optimizerRunTransfer->getBestSpecificityWeightExponentOrFail());
        $this->searchRankingFacade->saveSpecificityWeightShiftMagnitude($storeName, $localeName, $optimizerRunTransfer->getBestSpecificityWeightShiftMagnitudeOrFail());

        foreach ($optimizerRunTransfer->getBestMetricWeights() as $metricWeightTransfer) {
            $idSearchRankingMetric = $metricWeightTransfer->getIdSearchRankingMetricOrFail();
            $wasSaved = $this->searchRankingFacade->saveMetricWeight(
                $idSearchRankingMetric,
                $storeName,
                $localeName,
                $metricWeightTransfer->getWeightOrFail(),
                SharedSearchRankingConfig::CHANGE_SOURCE_OPTIMIZER_APPLY,
            );

            if (!$wasSaved) {
                throw new MetricNoLongerExistsException(sprintf(
                    'Cannot apply run #%d: metric #%d no longer exists.',
                    $idSearchRankingOptimizerRun,
                    $idSearchRankingMetric,
                ));
            }
        }

        $this->entityManager->markOptimizerRunApplied($idSearchRankingOptimizerRun);
    }
}
