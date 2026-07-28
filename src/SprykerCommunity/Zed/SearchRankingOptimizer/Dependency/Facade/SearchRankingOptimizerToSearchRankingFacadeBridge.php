<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade;

class SearchRankingOptimizerToSearchRankingFacadeBridge implements SearchRankingOptimizerToSearchRankingFacadeInterface
{
    /**
     * @var \SprykerCommunity\Zed\SearchRanking\Business\SearchRankingFacadeInterface
     */
    protected $searchRankingFacade;

    /**
     * @param \SprykerCommunity\Zed\SearchRanking\Business\SearchRankingFacadeInterface $searchRankingFacade
     */
    public function __construct($searchRankingFacade)
    {
        $this->searchRankingFacade = $searchRankingFacade;
    }

    /**
     * @return float
     */
    public function getRelevanceSaturationPoint(): float
    {
        return $this->searchRankingFacade->getRelevanceSaturationPoint();
    }

    /**
     * @param float $relevanceSaturationPoint
     *
     * @return void
     */
    public function saveRelevanceSaturationPoint(float $relevanceSaturationPoint): void
    {
        $this->searchRankingFacade->saveRelevanceSaturationPoint($relevanceSaturationPoint);
    }

    /**
     * @return float
     */
    public function getRelevanceWeight(): float
    {
        return $this->searchRankingFacade->getRelevanceWeight();
    }

    /**
     * @param float $relevanceWeight
     *
     * @return void
     */
    public function saveRelevanceWeight(float $relevanceWeight): void
    {
        $this->searchRankingFacade->saveRelevanceWeight($relevanceWeight);
    }

    /**
     * @return int
     */
    public function getEntropyProbeResultSize(): int
    {
        return $this->searchRankingFacade->getEntropyProbeResultSize();
    }

    /**
     * @param int $entropyProbeResultSize
     *
     * @return void
     */
    public function saveEntropyProbeResultSize(int $entropyProbeResultSize): void
    {
        $this->searchRankingFacade->saveEntropyProbeResultSize($entropyProbeResultSize);
    }

    /**
     * @return float
     */
    public function getEntropyWeightExponent(): float
    {
        return $this->searchRankingFacade->getEntropyWeightExponent();
    }

    /**
     * @param float $entropyWeightExponent
     *
     * @return void
     */
    public function saveEntropyWeightExponent(float $entropyWeightExponent): void
    {
        $this->searchRankingFacade->saveEntropyWeightExponent($entropyWeightExponent);
    }

    /**
     * @return float
     */
    public function getEntropyWeightShiftMagnitude(): float
    {
        return $this->searchRankingFacade->getEntropyWeightShiftMagnitude();
    }

    /**
     * @param float $entropyWeightShiftMagnitude
     *
     * @return void
     */
    public function saveEntropyWeightShiftMagnitude(float $entropyWeightShiftMagnitude): void
    {
        $this->searchRankingFacade->saveEntropyWeightShiftMagnitude($entropyWeightShiftMagnitude);
    }

    /**
     * @return bool
     */
    public function isEntropyWeightingEnabled(): bool
    {
        return $this->searchRankingFacade->isEntropyWeightingEnabled();
    }

    /**
     * @return array<int, array{idSearchRankingMetric: int, name: string, weight: float}>
     */
    public function getMetricWeights(): array
    {
        $metricWeights = [];

        foreach ($this->searchRankingFacade->getMetricCollection()->getMetrics() as $metricTransfer) {
            $metricWeights[] = [
                'idSearchRankingMetric' => $metricTransfer->getIdSearchRankingMetricOrFail(),
                'name' => $metricTransfer->getNameOrFail(),
                'weight' => $metricTransfer->getWeightOrFail(),
            ];
        }

        return $metricWeights;
    }

    /**
     * @param int $idSearchRankingMetric
     * @param float $weight
     *
     * @return bool
     */
    public function saveMetricWeight(int $idSearchRankingMetric, float $weight): bool
    {
        $metricTransfer = $this->searchRankingFacade->findMetricById($idSearchRankingMetric);

        if ($metricTransfer === null) {
            return false;
        }

        $this->searchRankingFacade->saveMetric($metricTransfer->setWeight($weight));

        return true;
    }
}
