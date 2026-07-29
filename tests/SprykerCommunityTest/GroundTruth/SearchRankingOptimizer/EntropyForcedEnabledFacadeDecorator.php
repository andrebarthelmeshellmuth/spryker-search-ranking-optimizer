<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\GroundTruth\SearchRankingOptimizer;

use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;

/**
 * Delegates every method to a real, live `search-ranking` facade bridge except {@see isEntropyWeightingEnabled()},
 * which is forced `true` -- see {@see AbstractGroundTruthTest::runRealOptimizationWithEntropyForcedEnabled()}'s
 * own docblock for why this is necessary at all (the real flag is a hardcoded `return false;` with no
 * project override actually wired up anywhere).
 */
class EntropyForcedEnabledFacadeDecorator implements SearchRankingOptimizerToSearchRankingFacadeInterface
{
    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface
     */
    protected SearchRankingOptimizerToSearchRankingFacadeInterface $realFacade;

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $realFacade
     */
    public function __construct(SearchRankingOptimizerToSearchRankingFacadeInterface $realFacade)
    {
        $this->realFacade = $realFacade;
    }

    /**
     * @return float
     */
    public function getRelevanceSaturationPoint(): float
    {
        return $this->realFacade->getRelevanceSaturationPoint();
    }

    /**
     * @param float $relevanceSaturationPoint
     *
     * @return void
     */
    public function saveRelevanceSaturationPoint(float $relevanceSaturationPoint): void
    {
        $this->realFacade->saveRelevanceSaturationPoint($relevanceSaturationPoint);
    }

    /**
     * @return float
     */
    public function getRelevanceWeight(): float
    {
        return $this->realFacade->getRelevanceWeight();
    }

    /**
     * @param float $relevanceWeight
     *
     * @return void
     */
    public function saveRelevanceWeight(float $relevanceWeight): void
    {
        $this->realFacade->saveRelevanceWeight($relevanceWeight);
    }

    /**
     * @return int
     */
    public function getEntropyProbeResultSize(): int
    {
        return $this->realFacade->getEntropyProbeResultSize();
    }

    /**
     * @param int $entropyProbeResultSize
     *
     * @return void
     */
    public function saveEntropyProbeResultSize(int $entropyProbeResultSize): void
    {
        $this->realFacade->saveEntropyProbeResultSize($entropyProbeResultSize);
    }

    /**
     * @return float
     */
    public function getEntropyWeightExponent(): float
    {
        return $this->realFacade->getEntropyWeightExponent();
    }

    /**
     * @param float $entropyWeightExponent
     *
     * @return void
     */
    public function saveEntropyWeightExponent(float $entropyWeightExponent): void
    {
        $this->realFacade->saveEntropyWeightExponent($entropyWeightExponent);
    }

    /**
     * @return float
     */
    public function getEntropyWeightShiftMagnitude(): float
    {
        return $this->realFacade->getEntropyWeightShiftMagnitude();
    }

    /**
     * @param float $entropyWeightShiftMagnitude
     *
     * @return void
     */
    public function saveEntropyWeightShiftMagnitude(float $entropyWeightShiftMagnitude): void
    {
        $this->realFacade->saveEntropyWeightShiftMagnitude($entropyWeightShiftMagnitude);
    }

    /**
     * The one deliberately overridden method -- see this class's own docblock.
     *
     * @return bool
     */
    public function isEntropyWeightingEnabled(): bool
    {
        return true;
    }

    /**
     * @return array<int, array{idSearchRankingMetric: int, name: string, weight: float}>
     */
    public function getMetricWeights(): array
    {
        return $this->realFacade->getMetricWeights();
    }

    /**
     * @param int $idSearchRankingMetric
     * @param float $weight
     *
     * @return bool
     */
    public function saveMetricWeight(int $idSearchRankingMetric, float $weight): bool
    {
        return $this->realFacade->saveMetricWeight($idSearchRankingMetric, $weight);
    }

    /**
     * @return array<int, array{idSearchRankingMetric: int, name: string}>
     */
    public function getActiveMetrics(): array
    {
        return $this->realFacade->getActiveMetrics();
    }

    /**
     * @param int $idSearchRankingMetric
     *
     * @return float|null
     */
    public function evaluateCurrentMetricFit(int $idSearchRankingMetric): ?float
    {
        return $this->realFacade->evaluateCurrentMetricFit($idSearchRankingMetric);
    }

    /**
     * @param int $idSearchRankingMetric
     *
     * @return array{idSearchRankingMetric: int, name: string, formula: string, isHigherBetter: bool, shape: string|null}|null
     */
    public function findMetricDetail(int $idSearchRankingMetric): ?array
    {
        return $this->realFacade->findMetricDetail($idSearchRankingMetric);
    }

    /**
     * @param int $idSearchRankingMetric
     *
     * @return array<int, array{shape: string, formula: string, rSquared: float, isWinner: bool}>
     */
    public function getFitCandidates(int $idSearchRankingMetric): array
    {
        return $this->realFacade->getFitCandidates($idSearchRankingMetric);
    }

    /**
     * @param int $idSearchRankingMetric
     * @param string $formula
     *
     * @return bool
     */
    public function saveMetricFormula(int $idSearchRankingMetric, string $formula): bool
    {
        return $this->realFacade->saveMetricFormula($idSearchRankingMetric, $formula);
    }

    /**
     * @param int $idSearchRankingMetric
     *
     * @return bool
     */
    public function recordMetricCheckOnly(int $idSearchRankingMetric): bool
    {
        return $this->realFacade->recordMetricCheckOnly($idSearchRankingMetric);
    }
}
