<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\GroundTruth\SearchRankingOptimizer;

use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig as SharedSearchRankingConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;

/**
 * Delegates every method to a real, live `search-ranking` facade bridge except {@see isSpecificityWeightingEnabled()},
 * which is forced `true` -- see {@see AbstractGroundTruthTest::runRealOptimizationWithSpecificityForcedEnabled()}'s
 * own docblock for why this is necessary at all (the real flag is a hardcoded `return false;` with no
 * project override actually wired up anywhere).
 */
class SpecificityForcedEnabledFacadeDecorator implements SearchRankingOptimizerToSearchRankingFacadeInterface
{
    protected SearchRankingOptimizerToSearchRankingFacadeInterface $realFacade;

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $realFacade
     */
    public function __construct(SearchRankingOptimizerToSearchRankingFacadeInterface $realFacade)
    {
        $this->realFacade = $realFacade;
    }

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function getRelevanceSaturationPoint(string $storeName, string $localeName): float
    {
        return $this->realFacade->getRelevanceSaturationPoint($storeName, $localeName);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $relevanceSaturationPoint
     */
    public function saveRelevanceSaturationPoint(string $storeName, string $localeName, float $relevanceSaturationPoint): void
    {
        $this->realFacade->saveRelevanceSaturationPoint($storeName, $localeName, $relevanceSaturationPoint);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function getRelevanceWeight(string $storeName, string $localeName): float
    {
        return $this->realFacade->getRelevanceWeight($storeName, $localeName);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $relevanceWeight
     */
    public function saveRelevanceWeight(string $storeName, string $localeName, float $relevanceWeight): void
    {
        $this->realFacade->saveRelevanceWeight($storeName, $localeName, $relevanceWeight);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function getSpecificitySaturationPoint(string $storeName, string $localeName): float
    {
        return $this->realFacade->getSpecificitySaturationPoint($storeName, $localeName);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificitySaturationPoint
     */
    public function saveSpecificitySaturationPoint(string $storeName, string $localeName, float $specificitySaturationPoint): void
    {
        $this->realFacade->saveSpecificitySaturationPoint($storeName, $localeName, $specificitySaturationPoint);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function getSpecificityBlendWeight(string $storeName, string $localeName): float
    {
        return $this->realFacade->getSpecificityBlendWeight($storeName, $localeName);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificityBlendWeight
     */
    public function saveSpecificityBlendWeight(string $storeName, string $localeName, float $specificityBlendWeight): void
    {
        $this->realFacade->saveSpecificityBlendWeight($storeName, $localeName, $specificityBlendWeight);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function getSpecificityWeightExponent(string $storeName, string $localeName): float
    {
        return $this->realFacade->getSpecificityWeightExponent($storeName, $localeName);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificityWeightExponent
     */
    public function saveSpecificityWeightExponent(string $storeName, string $localeName, float $specificityWeightExponent): void
    {
        $this->realFacade->saveSpecificityWeightExponent($storeName, $localeName, $specificityWeightExponent);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function getSpecificityWeightShiftMagnitude(string $storeName, string $localeName): float
    {
        return $this->realFacade->getSpecificityWeightShiftMagnitude($storeName, $localeName);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificityWeightShiftMagnitude
     */
    public function saveSpecificityWeightShiftMagnitude(string $storeName, string $localeName, float $specificityWeightShiftMagnitude): void
    {
        $this->realFacade->saveSpecificityWeightShiftMagnitude($storeName, $localeName, $specificityWeightShiftMagnitude);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function getSpecificityCurveExponent(string $storeName, string $localeName): float
    {
        return $this->realFacade->getSpecificityCurveExponent($storeName, $localeName);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificityCurveExponent
     */
    public function saveSpecificityCurveExponent(string $storeName, string $localeName, float $specificityCurveExponent): void
    {
        $this->realFacade->saveSpecificityCurveExponent($storeName, $localeName, $specificityCurveExponent);
    }

    /**
     * The one deliberately overridden method -- see this class's own docblock.
     */
    public function isSpecificityWeightingEnabled(): bool
    {
        return true;
    }

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<int, array{idSearchRankingMetric: int, name: string, weight: float}>
     */
    public function getMetricWeights(string $storeName, string $localeName): array
    {
        return $this->realFacade->getMetricWeights($storeName, $localeName);
    }

    /**
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     * @param float $weight
     * @param string $changeSource
     */
    public function saveMetricWeight(int $idSearchRankingMetric, string $storeName, string $localeName, float $weight, string $changeSource): bool
    {
        return $this->realFacade->saveMetricWeight($idSearchRankingMetric, $storeName, $localeName, $weight, $changeSource);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<int, array{idSearchRankingMetric: int, name: string}>
     */
    public function getActiveMetrics(string $storeName, string $localeName): array
    {
        return $this->realFacade->getActiveMetrics($storeName, $localeName);
    }

    public function getRandomMetricName(): string
    {
        return $this->realFacade->getRandomMetricName();
    }

    /**
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     */
    public function evaluateCurrentMetricFit(int $idSearchRankingMetric, string $storeName, string $localeName): ?float
    {
        return $this->realFacade->evaluateCurrentMetricFit($idSearchRankingMetric, $storeName, $localeName);
    }

    /**
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     *
     * @return array{idSearchRankingMetric: int, name: string, formula: string, isHigherBetter: bool, shape: string|null}|null
     */
    public function findMetricDetail(int $idSearchRankingMetric, string $storeName, string $localeName): ?array
    {
        return $this->realFacade->findMetricDetail($idSearchRankingMetric, $storeName, $localeName);
    }

    /**
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<int, array{shape: string, formula: string, rSquared: float, isWinner: bool}>
     */
    public function getFitCandidates(int $idSearchRankingMetric, string $storeName, string $localeName): array
    {
        return $this->realFacade->getFitCandidates($idSearchRankingMetric, $storeName, $localeName);
    }

    /**
     * @param int $idSearchRankingMetric
     * @param string $formula
     * @param string $storeName
     * @param string $localeName
     * @param string $changeSource
     */
    public function saveMetricFormula(
        int $idSearchRankingMetric,
        string $formula,
        string $storeName,
        string $localeName,
        string $changeSource = SharedSearchRankingConfig::CHANGE_SOURCE_AUTO_TUNE,
    ): bool {
        return $this->realFacade->saveMetricFormula($idSearchRankingMetric, $formula, $storeName, $localeName, $changeSource);
    }

    /**
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     */
    public function recordMetricCheckOnly(int $idSearchRankingMetric, string $storeName, string $localeName): bool
    {
        return $this->realFacade->recordMetricCheckOnly($idSearchRankingMetric, $storeName, $localeName);
    }
}
