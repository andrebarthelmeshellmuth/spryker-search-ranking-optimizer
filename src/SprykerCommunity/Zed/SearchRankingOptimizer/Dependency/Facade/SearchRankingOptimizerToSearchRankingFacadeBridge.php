<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade;

use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig as SharedSearchRankingConfig;

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
     * @param string $storeName
     * @param string $localeName
     *
     * @return float
     */
    public function getRelevanceSaturationPoint(string $storeName, string $localeName): float
    {
        return $this->searchRankingFacade->getRelevanceSaturationPoint($storeName, $localeName);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $relevanceSaturationPoint
     *
     * @return void
     */
    public function saveRelevanceSaturationPoint(string $storeName, string $localeName, float $relevanceSaturationPoint): void
    {
        $this->searchRankingFacade->saveRelevanceSaturationPoint($storeName, $localeName, $relevanceSaturationPoint);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return float
     */
    public function getRelevanceWeight(string $storeName, string $localeName): float
    {
        return $this->searchRankingFacade->getRelevanceWeight($storeName, $localeName);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $relevanceWeight
     *
     * @return void
     */
    public function saveRelevanceWeight(string $storeName, string $localeName, float $relevanceWeight): void
    {
        $this->searchRankingFacade->saveRelevanceWeight($storeName, $localeName, $relevanceWeight);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return float
     */
    public function getSpecificitySaturationPoint(string $storeName, string $localeName): float
    {
        return $this->searchRankingFacade->getSpecificitySaturationPoint($storeName, $localeName);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificitySaturationPoint
     *
     * @return void
     */
    public function saveSpecificitySaturationPoint(string $storeName, string $localeName, float $specificitySaturationPoint): void
    {
        $this->searchRankingFacade->saveSpecificitySaturationPoint($storeName, $localeName, $specificitySaturationPoint);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return float
     */
    public function getSpecificityBlendWeight(string $storeName, string $localeName): float
    {
        return $this->searchRankingFacade->getSpecificityBlendWeight($storeName, $localeName);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificityBlendWeight
     *
     * @return void
     */
    public function saveSpecificityBlendWeight(string $storeName, string $localeName, float $specificityBlendWeight): void
    {
        $this->searchRankingFacade->saveSpecificityBlendWeight($storeName, $localeName, $specificityBlendWeight);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return float
     */
    public function getSpecificityWeightExponent(string $storeName, string $localeName): float
    {
        return $this->searchRankingFacade->getSpecificityWeightExponent($storeName, $localeName);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificityWeightExponent
     *
     * @return void
     */
    public function saveSpecificityWeightExponent(string $storeName, string $localeName, float $specificityWeightExponent): void
    {
        $this->searchRankingFacade->saveSpecificityWeightExponent($storeName, $localeName, $specificityWeightExponent);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return float
     */
    public function getSpecificityWeightShiftMagnitude(string $storeName, string $localeName): float
    {
        return $this->searchRankingFacade->getSpecificityWeightShiftMagnitude($storeName, $localeName);
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificityWeightShiftMagnitude
     *
     * @return void
     */
    public function saveSpecificityWeightShiftMagnitude(string $storeName, string $localeName, float $specificityWeightShiftMagnitude): void
    {
        $this->searchRankingFacade->saveSpecificityWeightShiftMagnitude($storeName, $localeName, $specificityWeightShiftMagnitude);
    }

    /**
     * @return bool
     */
    public function isSpecificityWeightingEnabled(): bool
    {
        return $this->searchRankingFacade->isSpecificityWeightingEnabled();
    }

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<int, array{idSearchRankingMetric: int, name: string, weight: float}>
     */
    public function getMetricWeights(string $storeName, string $localeName): array
    {
        $metricWeights = [];

        foreach ($this->searchRankingFacade->getMetricCollection($storeName, $localeName)->getMetrics() as $metricTransfer) {
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
     * @param string $storeName
     * @param string $localeName
     * @param float $weight
     *
     * @return bool
     */
    public function saveMetricWeight(int $idSearchRankingMetric, string $storeName, string $localeName, float $weight): bool
    {
        $metricTransfer = $this->searchRankingFacade->findMetricById($idSearchRankingMetric, $storeName, $localeName);

        if ($metricTransfer === null) {
            return false;
        }

        $this->searchRankingFacade->saveMetricWeight($idSearchRankingMetric, $storeName, $localeName, $weight);

        return true;
    }

    /**
     * @return array<int, array{idSearchRankingMetric: int, name: string}>
     */
    public function getActiveMetrics(): array
    {
        $metrics = [];

        foreach ($this->searchRankingFacade->getActiveMetricCollection(
            SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME,
            SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME,
        )->getMetrics() as $metricTransfer) {
            $metrics[] = [
                'idSearchRankingMetric' => $metricTransfer->getIdSearchRankingMetricOrFail(),
                'name' => $metricTransfer->getNameOrFail(),
            ];
        }

        return $metrics;
    }

    /**
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     *
     * @return float|null
     */
    public function evaluateCurrentMetricFit(int $idSearchRankingMetric, string $storeName, string $localeName): ?float
    {
        return $this->searchRankingFacade->evaluateCurrentMetricFit($idSearchRankingMetric, $storeName, $localeName);
    }

    /**
     * @param int $idSearchRankingMetric
     *
     * @return array{idSearchRankingMetric: int, name: string, formula: string, isHigherBetter: bool, shape: string|null}|null
     */
    public function findMetricDetail(int $idSearchRankingMetric): ?array
    {
        $metricTransfer = $this->searchRankingFacade->findMetricById(
            $idSearchRankingMetric,
            SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME,
            SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME,
        );

        if ($metricTransfer === null) {
            return null;
        }

        return [
            'idSearchRankingMetric' => $metricTransfer->getIdSearchRankingMetricOrFail(),
            'name' => $metricTransfer->getNameOrFail(),
            'formula' => $metricTransfer->getFormulaOrFail(),
            'isHigherBetter' => $metricTransfer->getIsHigherBetter() ?? true,
            'shape' => $metricTransfer->getShape(),
        ];
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
        $metricTransfer = $this->searchRankingFacade->findMetricById($idSearchRankingMetric, $storeName, $localeName);

        if ($metricTransfer === null) {
            return [];
        }

        $previewTransfer = $this->searchRankingFacade->previewFormula(
            $idSearchRankingMetric,
            $metricTransfer->getFormulaOrFail(),
            $metricTransfer->getIsHigherBetter() ?? true,
            $storeName,
            $localeName,
        );

        $candidates = [];

        foreach ($previewTransfer->getCandidates() as $candidateTransfer) {
            $candidates[] = [
                'shape' => $candidateTransfer->getShapeOrFail(),
                'formula' => $candidateTransfer->getFormulaOrFail(),
                'rSquared' => $candidateTransfer->getRSquaredOrFail(),
                'isWinner' => $candidateTransfer->getIsWinner() ?? false,
            ];
        }

        return $candidates;
    }

    /**
     * @param int $idSearchRankingMetric
     * @param string $formula
     *
     * @return bool
     */
    public function saveMetricFormula(int $idSearchRankingMetric, string $formula): bool
    {
        $metricTransfer = $this->searchRankingFacade->findMetricById(
            $idSearchRankingMetric,
            SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME,
            SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME,
        );

        if ($metricTransfer === null) {
            return false;
        }

        $this->searchRankingFacade->saveMetric($metricTransfer->setFormula($formula));

        return true;
    }

    /**
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     *
     * @return bool
     */
    public function recordMetricCheckOnly(int $idSearchRankingMetric, string $storeName, string $localeName): bool
    {
        $metricTransfer = $this->searchRankingFacade->findMetricById($idSearchRankingMetric, $storeName, $localeName);

        if ($metricTransfer === null) {
            return false;
        }

        $this->searchRankingFacade->recordCheckOnly($metricTransfer, $storeName, $localeName);

        return true;
    }
}
