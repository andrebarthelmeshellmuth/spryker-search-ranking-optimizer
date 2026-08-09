<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismCheckerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;

class OptimizableParameterLister implements OptimizableParameterListerInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismCheckerInterface $formulaDeterminismChecker
     */
    public function __construct(
        protected SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
        protected FormulaDeterminismCheckerInterface $formulaDeterminismChecker,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return array{
     *     relevanceWeight: float,
     *     isSpecificityWeightingEnabled: bool,
     *     specificityCurveExponent: float,
     *     specificityWeightExponent: float,
     *     specificityWeightShiftMagnitude: float,
     *     specificityBlendWeight: float,
     *     metrics: array<int, array{idSearchRankingMetric: int, name: string, weight: float, isDeterministic: bool}>,
     * }
     */
    public function list(string $storeName, string $localeName): array
    {
        return [
            'relevanceWeight' => $this->searchRankingFacade->getRelevanceWeight($storeName, $localeName),
            'isSpecificityWeightingEnabled' => $this->searchRankingFacade->isSpecificityWeightingEnabled(),
            'specificityCurveExponent' => $this->searchRankingFacade->getSpecificityCurveExponent($storeName, $localeName),
            'specificityWeightExponent' => $this->searchRankingFacade->getSpecificityWeightExponent($storeName, $localeName),
            'specificityWeightShiftMagnitude' => $this->searchRankingFacade->getSpecificityWeightShiftMagnitude($storeName, $localeName),
            'specificityBlendWeight' => $this->searchRankingFacade->getSpecificityBlendWeight($storeName, $localeName),
            'metrics' => $this->listMetrics($storeName, $localeName),
        ];
    }

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<int, array{idSearchRankingMetric: int, name: string, weight: float, isDeterministic: bool}>
     */
    protected function listMetrics(string $storeName, string $localeName): array
    {
        $weightsByName = [];

        foreach ($this->searchRankingFacade->getMetricWeights($storeName, $localeName) as $metricWeight) {
            $weightsByName[$metricWeight['name']] = $metricWeight['weight'];
        }

        $metrics = [];

        foreach ($this->searchRankingFacade->getActiveMetrics($storeName, $localeName) as $metric) {
            $metricDetail = $this->searchRankingFacade->findMetricDetail($metric['idSearchRankingMetric'], $storeName, $localeName);
            $isDeterministic = $metricDetail === null || $this->formulaDeterminismChecker->isDeterministic($metricDetail['formula']);

            $metrics[] = [
                'idSearchRankingMetric' => $metric['idSearchRankingMetric'],
                'name' => $metric['name'],
                'weight' => (float)($weightsByName[$metric['name']] ?? 0.0),
                'isDeterministic' => $isDeterministic,
            ];
        }

        return $metrics;
    }
}
