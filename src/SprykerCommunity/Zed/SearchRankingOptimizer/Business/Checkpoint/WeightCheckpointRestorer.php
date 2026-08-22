<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint;

use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig as SharedSearchRankingConfig;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\MetricNoLongerExistsException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

class WeightCheckpointRestorer implements WeightCheckpointRestorerInterface
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
     * @param int $idSearchRankingWeightCheckpoint
     * @param string $storeName
     * @param string $localeName
     */
    public function restore(int $idSearchRankingWeightCheckpoint, string $storeName, string $localeName): ?SearchRankingWeightCheckpointTransfer
    {
        $weightCheckpointTransfer = $this->repository->findWeightCheckpointById($idSearchRankingWeightCheckpoint);

        if ($weightCheckpointTransfer === null) {
            return null;
        }

        try {
            return $this->entityManager->getTransactionHandler()->handleTransaction(
                fn () => $this->restoreWithinTransaction($weightCheckpointTransfer, $storeName, $localeName),
            );
        } catch (MetricNoLongerExistsException) {
            return null;
        }
    }

    /**
     * Runs entirely inside {@see restore()}'s transaction — all-or-nothing, same posture
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationApplier} already
     * takes for the identical write sequence: a metric this checkpoint wants to restore a weight for that
     * no longer exists throws rather than being silently skipped, rolling back every write already made
     * (the relevanceWeight/specificity knobs and any earlier metric weight in this same checkpoint) rather
     * than leaving the target scope in a partially-restored state with no indication anything was
     * incomplete.
     *
     * @param \Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer $weightCheckpointTransfer
     * @param string $storeName
     * @param string $localeName
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\MetricNoLongerExistsException
     */
    protected function restoreWithinTransaction(
        SearchRankingWeightCheckpointTransfer $weightCheckpointTransfer,
        string $storeName,
        string $localeName,
    ): SearchRankingWeightCheckpointTransfer {
        $this->searchRankingFacade->saveRelevanceWeight($storeName, $localeName, $weightCheckpointTransfer->getRelevanceWeightOrFail());
        $this->searchRankingFacade->saveSpecificityBlendWeight($storeName, $localeName, $weightCheckpointTransfer->getSpecificityBlendWeightOrFail());
        $this->searchRankingFacade->saveSpecificityCurveExponent($storeName, $localeName, $weightCheckpointTransfer->getSpecificityCurveExponentOrFail());
        $this->searchRankingFacade->saveSpecificityWeightExponent($storeName, $localeName, $weightCheckpointTransfer->getSpecificityWeightExponentOrFail());
        $this->searchRankingFacade->saveSpecificityWeightShiftMagnitude($storeName, $localeName, $weightCheckpointTransfer->getSpecificityWeightShiftMagnitudeOrFail());

        foreach ($weightCheckpointTransfer->getMetricWeights() as $metricWeightTransfer) {
            $idSearchRankingMetric = $metricWeightTransfer->getIdSearchRankingMetricOrFail();
            $wasSaved = $this->searchRankingFacade->saveMetricWeight(
                $idSearchRankingMetric,
                $storeName,
                $localeName,
                $metricWeightTransfer->getWeightOrFail(),
                SharedSearchRankingConfig::CHANGE_SOURCE_CHECKPOINT_RESTORE,
            );

            if (!$wasSaved) {
                throw new MetricNoLongerExistsException(sprintf(
                    'Cannot restore checkpoint #%d: metric #%d no longer exists.',
                    $weightCheckpointTransfer->getIdSearchRankingWeightCheckpointOrFail(),
                    $idSearchRankingMetric,
                ));
            }
        }

        return $this->recorder->record(SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_MANUAL, $storeName, $localeName);
    }
}
