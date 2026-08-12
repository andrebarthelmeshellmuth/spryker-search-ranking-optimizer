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
     *     metrics: array<int, array{idSearchRankingMetric: int, name: string, weight: float}>,
     * }
     */
    public function list(string $storeName, string $localeName): array
    {
        // Deliberately NOT just handed back as the transfer itself: the run form's checklist needs each
        // metric's idSearchRankingMetric (to post a per-metric pin back), which
        // SearchRankingConfigurationStorageTransfer models only as a name => weight map, and it needs
        // isSpecificityWeightingEnabled, which is a code-level project flag rather than per-scope config.
        $configurationTransfer = $this->searchRankingFacade->getConfiguration($storeName, $localeName);

        return [
            'relevanceWeight' => $configurationTransfer->getRelevanceWeightOrFail(),
            'isSpecificityWeightingEnabled' => $this->searchRankingFacade->isSpecificityWeightingEnabled(),
            'specificityCurveExponent' => $configurationTransfer->getSpecificityCurveExponentOrFail(),
            'specificityWeightExponent' => $configurationTransfer->getSpecificityWeightExponentOrFail(),
            'specificityWeightShiftMagnitude' => $configurationTransfer->getSpecificityWeightShiftMagnitudeOrFail(),
            'specificityBlendWeight' => $configurationTransfer->getSpecificityBlendWeightOrFail(),
            'metrics' => $this->listMetrics($configurationTransfer->getMetricWeights(), $storeName, $localeName),
        ];
    }

    /**
     * Omits any metric whose formula is non-deterministic entirely -- {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationRunner}
     * holds those fixed at their live weight unconditionally, a human's checklist choice can never change
     * that, so listing them as a dead, uncheckable row here would only be noise, not a real option.
     *
     * @param array<string, float> $weightsByName This scope's live active-metric weights, from
     *   {@see list()}'s single configuration read — active is exactly the set iterated below, so no
     *   second weight lookup is needed.
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<int, array{idSearchRankingMetric: int, name: string, weight: float}>
     */
    protected function listMetrics(array $weightsByName, string $storeName, string $localeName): array
    {
        $metrics = [];

        foreach ($this->searchRankingFacade->getActiveMetrics($storeName, $localeName) as $metric) {
            $metricDetail = $this->searchRankingFacade->findMetricDetail($metric['idSearchRankingMetric'], $storeName, $localeName);
            $isDeterministic = $metricDetail === null || $this->formulaDeterminismChecker->isDeterministic($metricDetail['formula']);

            if (!$isDeterministic) {
                continue;
            }

            $metrics[] = [
                'idSearchRankingMetric' => $metric['idSearchRankingMetric'],
                'name' => $metric['name'],
                'weight' => (float)($weightsByName[$metric['name']] ?? 0.0),
            ];
        }

        return $metrics;
    }
}
