<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint;

use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

class WeightCheckpointRestorer implements WeightCheckpointRestorerInterface
{
    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface
     */
    protected SearchRankingOptimizerRepositoryInterface $repository;

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface
     */
    protected SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade;

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorderInterface
     */
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
     *
     * @return \Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer|null
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
            );
        }

        return $this->recorder->record(SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_MANUAL, $storeName, $localeName);
    }
}
