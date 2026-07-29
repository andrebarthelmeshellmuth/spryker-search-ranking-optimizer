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
 * - Indices 1..(n-1), where n = the number of active metrics: the free z values feeding
 *   {@see SimplexSoftmaxReparametrization} (metric 0 is the pinned reference weight, never a free
 *   dimension) — omitted entirely when there are 0 or 1 active metrics, since a simplex of size <= 1 has
 *   no real degrees of freedom (0 metrics: nothing to weight at all; 1 metric: its weight is trivially
 *   always 1.0).
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
     * @param array<int, array{idSearchRankingMetric: int, name: string}> $metrics The active metrics this
     *   optimization run covers — same plain shape as
     *   {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface::getActiveMetrics()}.
     *   Order matters: metrics[0] becomes the simplex's pinned reference weight.
     * @param float $relevanceWeightAtRunStart The relevanceWeight value to center this run's trust region
     *   on — normally the LIVE value at the moment the run starts, read once and fixed for the whole run.
     * @param \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Reparametrization\SimplexSoftmaxReparametrization|null $simplexSoftmaxReparametrization
     */
    public function __construct(
        array $metrics,
        float $relevanceWeightAtRunStart,
        ?SimplexSoftmaxReparametrization $simplexSoftmaxReparametrization = null,
    ) {
        $this->metrics = array_values($metrics);
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
        if (count($this->metrics) === 0) {
            return [];
        }

        if (count($this->metrics) === 1) {
            return [$this->metrics[0]['name'] => 1.0];
        }

        $weights = $this->simplexSoftmaxReparametrization->toSimplex($freeZ);
        $metricWeightsByName = [];

        foreach ($this->metrics as $index => $metric) {
            $metricWeightsByName[$metric['name']] = $weights[$index];
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
