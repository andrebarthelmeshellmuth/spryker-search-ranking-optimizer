<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Reparametrization\SimplexSoftmaxReparametrization;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

/**
 * Vector layout, fixed for the lifetime of one instance:
 * - Index 0: `relevanceWeight`, box-bounded to a trust region around the value it had when this mapper
 *   was built (see {@see SearchRankingOptimizerConfig::getRelevanceWeightTrustRegionMaxDistance()}),
 *   clipped to [0;1].
 * - Indices 1..(n-1), where n = the number of OPTIMIZABLE metrics (excluding any fixed-weight ones, see
 *   $fixedMetricWeights below): the free z values feeding {@see SimplexSoftmaxReparametrization} (metric 0
 *   is the pinned reference weight, never a free dimension) — omitted entirely when there are 0 or 1
 *   optimizable metrics, since a simplex of size <= 1 has no real degrees of freedom (0 metrics: nothing
 *   to weight at all; 1 metric: its weight is trivially always the full remaining budget).
 *
 * relevanceSaturationPoint is deliberately never part of this vector at all -- see
 * {@see ParameterVectorMapperInterface} and this package's own README/memory for why (Calibration's own,
 * already-solved concern).
 */
class ParameterVectorMapper implements ParameterVectorMapperInterface
{
    /**
     * @var array<int, array{idSearchRankingMetric: int, name: string}>
     */
    protected array $metrics;

    /**
     * @var array<string, float>
     */
    protected array $fixedMetricWeights;

    /**
     * @var float
     */
    protected float $fixedWeightBudget;

    /**
     * @var float
     */
    protected float $relevanceWeightLowerBound;

    /**
     * @var float
     */
    protected float $relevanceWeightUpperBound;

    /**
     * @var \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Reparametrization\SimplexSoftmaxReparametrization
     */
    protected SimplexSoftmaxReparametrization $simplexSoftmaxReparametrization;

    /**
     * @param array<int, array{idSearchRankingMetric: int, name: string}> $metrics The OPTIMIZABLE active
     *   metrics this run's simplex searches over — same plain shape as
     *   {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface::getActiveMetrics()},
     *   already filtered to exclude any metric a {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismCheckerInterface}
     *   found non-deterministic. Order matters: metrics[0] becomes the simplex's pinned reference weight.
     * @param array<string, float> $fixedMetricWeights Active metrics EXCLUDED from the search (a
     *   non-deterministic formula, e.g. a placeholder/noise metric — optimizing a weight against pure
     *   noise is meaningless) — name => current live weight, held constant for the whole run. Reserves
     *   that much of the [0;1] simplex budget up front; the optimizable metrics' own simplex is scaled to
     *   fill exactly what's left, so the full set (optimizable + fixed) still sums to 1 on every candidate
     *   this mapper produces.
     * @param float $relevanceWeightAtRunStart The relevanceWeight value to center this run's trust region
     *   on — normally the LIVE value at the moment the run starts, read once and fixed for the whole run.
     * @param \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Reparametrization\SimplexSoftmaxReparametrization|null $simplexSoftmaxReparametrization
     */
    public function __construct(
        array $metrics,
        array $fixedMetricWeights,
        float $relevanceWeightAtRunStart,
        ?SimplexSoftmaxReparametrization $simplexSoftmaxReparametrization = null,
    ) {
        $this->metrics = array_values($metrics);
        $this->fixedMetricWeights = $fixedMetricWeights;
        $this->fixedWeightBudget = array_sum($fixedMetricWeights);
        $this->simplexSoftmaxReparametrization = $simplexSoftmaxReparametrization ?? new SimplexSoftmaxReparametrization();

        $maxDistance = SearchRankingOptimizerConfig::getRelevanceWeightTrustRegionMaxDistance();
        $this->relevanceWeightLowerBound = max(0.0, $relevanceWeightAtRunStart - $maxDistance);
        $this->relevanceWeightUpperBound = min(1.0, $relevanceWeightAtRunStart + $maxDistance);
    }

    /**
     * @return int
     */
    public function getDimensionCount(): int
    {
        return 1 + $this->getFreeMetricWeightDimensionCount();
    }

    /**
     * @return array<int, float>
     */
    public function getLowerBounds(): array
    {
        $bounds = [$this->relevanceWeightLowerBound];
        $freeDimensionCount = $this->getFreeMetricWeightDimensionCount();
        $zSpaceBound = SearchRankingOptimizerConfig::getMetricWeightZSpaceBound();

        for ($i = 0; $i < $freeDimensionCount; $i++) {
            $bounds[] = -$zSpaceBound;
        }

        return $bounds;
    }

    /**
     * @return array<int, float>
     */
    public function getUpperBounds(): array
    {
        $bounds = [$this->relevanceWeightUpperBound];
        $freeDimensionCount = $this->getFreeMetricWeightDimensionCount();
        $zSpaceBound = SearchRankingOptimizerConfig::getMetricWeightZSpaceBound();

        for ($i = 0; $i < $freeDimensionCount; $i++) {
            $bounds[] = $zSpaceBound;
        }

        return $bounds;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int, float> $vector
     * @param float $relevanceSaturationPoint
     *
     * @return \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer
     */
    public function mapVectorToConfiguration(array $vector, float $relevanceSaturationPoint): SearchRankingConfigurationStorageTransfer
    {
        $vector = array_values($vector);
        $relevanceWeight = $vector[0];
        $freeZ = array_slice($vector, 1);

        $metricWeights = $this->buildMetricWeightsByName($freeZ);

        return (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight($relevanceWeight)
            ->setRelevanceSaturationPoint($relevanceSaturationPoint)
            ->setMetricWeights($metricWeights);
    }

    /**
     * {@inheritDoc}
     *
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer $configurationTransfer
     *
     * @return array<int, float>
     */
    public function mapConfigurationToVector(SearchRankingConfigurationStorageTransfer $configurationTransfer): array
    {
        $metricWeightsByName = $configurationTransfer->getMetricWeights();
        $orderedWeights = [];

        foreach ($this->metrics as $metric) {
            $orderedWeights[] = (float)($metricWeightsByName[$metric['name']] ?? 0.0);
        }

        $vector = [(float)$configurationTransfer->getRelevanceWeight()];

        if ($this->getFreeMetricWeightDimensionCount() > 0) {
            $vector = array_merge($vector, $this->simplexSoftmaxReparametrization->toFreeZ($orderedWeights));
        }

        return $vector;
    }

    /**
     * @param array<int, float> $freeZ
     *
     * @return array<string, float>
     */
    protected function buildMetricWeightsByName(array $freeZ): array
    {
        $metricWeightsByName = $this->fixedMetricWeights;
        $availableBudget = max(0.0, 1.0 - $this->fixedWeightBudget);

        if (count($this->metrics) === 0) {
            return $metricWeightsByName;
        }

        if (count($this->metrics) === 1) {
            $metricWeightsByName[$this->metrics[0]['name']] = $availableBudget;

            return $metricWeightsByName;
        }

        $weights = $this->simplexSoftmaxReparametrization->toSimplex($freeZ);

        foreach ($this->metrics as $index => $metric) {
            $metricWeightsByName[$metric['name']] = $weights[$index] * $availableBudget;
        }

        return $metricWeightsByName;
    }

    /**
     * @return int
     */
    protected function getFreeMetricWeightDimensionCount(): int
    {
        return max(0, count($this->metrics) - 1);
    }
}
