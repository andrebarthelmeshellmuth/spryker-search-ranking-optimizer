<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint;

use Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;

class WeightCheckpointRecorder implements WeightCheckpointRecorderInterface
{
    protected SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade;

    protected SearchRankingOptimizerEntityManagerInterface $entityManager;

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     */
    public function __construct(
        SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
        SearchRankingOptimizerEntityManagerInterface $entityManager,
    ) {
        $this->searchRankingFacade = $searchRankingFacade;
        $this->entityManager = $entityManager;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $source
     * @param string $storeName
     * @param string $localeName
     */
    public function record(string $source, string $storeName, string $localeName): SearchRankingWeightCheckpointTransfer
    {
        $weightCheckpointTransfer = (new SearchRankingWeightCheckpointTransfer())
            ->setSource($source)
            ->setStoreName($storeName)
            ->setLocaleName($localeName)
            ->setRelevanceWeight($this->searchRankingFacade->getRelevanceWeight($storeName, $localeName))
            ->setSpecificityBlendWeight($this->searchRankingFacade->getSpecificityBlendWeight($storeName, $localeName))
            ->setSpecificityCurveExponent($this->searchRankingFacade->getSpecificityCurveExponent($storeName, $localeName))
            ->setSpecificityWeightExponent($this->searchRankingFacade->getSpecificityWeightExponent($storeName, $localeName))
            ->setSpecificityWeightShiftMagnitude($this->searchRankingFacade->getSpecificityWeightShiftMagnitude($storeName, $localeName))
            ->setIsSpecificityWeightingEnabled($this->searchRankingFacade->isSpecificityWeightingEnabled());

        foreach ($this->searchRankingFacade->getMetricWeights($storeName, $localeName) as $metricWeight) {
            $weightCheckpointTransfer->addMetricWeight(
                (new SearchRankingWeightCheckpointMetricWeightTransfer())
                    ->setIdSearchRankingMetric($metricWeight['idSearchRankingMetric'])
                    ->setName($metricWeight['name'])
                    ->setWeight($metricWeight['weight']),
            );
        }

        return $this->entityManager->createWeightCheckpoint($weightCheckpointTransfer);
    }
}
