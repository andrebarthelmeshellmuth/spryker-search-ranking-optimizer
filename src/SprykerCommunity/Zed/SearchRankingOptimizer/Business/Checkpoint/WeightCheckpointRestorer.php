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
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

class WeightCheckpointRestorer implements WeightCheckpointRestorerInterface
{
    protected SearchRankingOptimizerRepositoryInterface $repository;

    protected SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade;

    protected WeightCheckpointRecorderInterface $recorder;

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorderInterface $recorder
     */
    public function __construct(
        SearchRankingOptimizerRepositoryInterface $repository,
        SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
        WeightCheckpointRecorderInterface $recorder,
    ) {
        $this->repository = $repository;
        $this->searchRankingFacade = $searchRankingFacade;
        $this->recorder = $recorder;
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

        $this->searchRankingFacade->saveRelevanceWeight($storeName, $localeName, $weightCheckpointTransfer->getRelevanceWeightOrFail());
        $this->searchRankingFacade->saveSpecificityBlendWeight($storeName, $localeName, $weightCheckpointTransfer->getSpecificityBlendWeightOrFail());
        $this->searchRankingFacade->saveSpecificityWeightExponent($storeName, $localeName, $weightCheckpointTransfer->getSpecificityWeightExponentOrFail());
        $this->searchRankingFacade->saveSpecificityWeightShiftMagnitude($storeName, $localeName, $weightCheckpointTransfer->getSpecificityWeightShiftMagnitudeOrFail());

        foreach ($weightCheckpointTransfer->getMetricWeights() as $metricWeightTransfer) {
            $this->searchRankingFacade->saveMetricWeight(
                $metricWeightTransfer->getIdSearchRankingMetricOrFail(),
                $storeName,
                $localeName,
                $metricWeightTransfer->getWeightOrFail(),
                SharedSearchRankingConfig::CHANGE_SOURCE_CHECKPOINT_RESTORE,
            );
        }

        return $this->recorder->record(SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_MANUAL, $storeName, $localeName);
    }
}
