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
    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface
     */
    protected SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade;

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface
     */
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
     *
     * @return \Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer
     */
    public function record(string $source): SearchRankingWeightCheckpointTransfer
    {
        $weightCheckpointTransfer = (new SearchRankingWeightCheckpointTransfer())
            ->setSource($source)
            ->setRelevanceWeight($this->searchRankingFacade->getRelevanceWeight())
            ->setEntropyProbeResultSize($this->searchRankingFacade->getEntropyProbeResultSize())
            ->setEntropyWeightExponent($this->searchRankingFacade->getEntropyWeightExponent())
            ->setEntropyWeightShiftMagnitude($this->searchRankingFacade->getEntropyWeightShiftMagnitude())
            ->setIsEntropyWeightingEnabled($this->searchRankingFacade->isEntropyWeightingEnabled());

        foreach ($this->searchRankingFacade->getMetricWeights() as $metricWeight) {
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
