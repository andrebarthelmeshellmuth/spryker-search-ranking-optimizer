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
 * - Final 3 indices, present ONLY when `search-ranking`'s entropy-aware relevance weighting is actually
 *   enabled (see the constructor's `$entropyWeightingEnabled`): `entropyWeightExponent`,
 *   `entropyWeightShiftMagnitude`, `entropyProbeResultSize` — each its own independent box-bounded value (a
 *   trust region around its OWN value at run start, clipped to its own absolute bounds), not part of any
 *   simplex. `entropyProbeResultSize` is rounded to the nearest integer (and re-clamped) when read back off
 *   the vector — this package's optimizer treats every dimension as a plain continuous float, per
 *   `blackbox-optimizer`'s own documented `ParameterType::Integer` caveat (declared, not enforced by any
 *   shipped algorithm). When disabled, these 3 knobs are omitted from the vector entirely (not merely
 *   fixed, like an excluded metric — a disabled feature has no live effect to preserve a budget for) and
 *   every candidate configuration this mapper produces simply carries the values captured at run start,
 *   unchanged.
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
     * @var float
     */
    protected float $entropyWeightExponentLowerBound;

    /**
     * @var float
     */
    protected float $entropyWeightExponentUpperBound;

    /**
     * @var float
     */
    protected float $entropyWeightShiftMagnitudeLowerBound;

    /**
     * @var float
     */
    protected float $entropyWeightShiftMagnitudeUpperBound;

    /**
     * @var float
     */
    protected float $entropyProbeResultSizeLowerBound;

    /**
     * @var float
     */
    protected float $entropyProbeResultSizeUpperBound;

    /**
     * @var bool
     */
    protected bool $entropyWeightingEnabled;

    /**
     * @var float
     */
    protected float $entropyWeightExponentAtRunStart;

    /**
     * @var float
     */
    protected float $entropyWeightShiftMagnitudeAtRunStart;

    /**
     * @var int
     */
    protected int $entropyProbeResultSizeAtRunStart;

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
     * @param float $entropyWeightExponentAtRunStart Same "center this run's trust region on the live value"
     *   treatment as $relevanceWeightAtRunStart, for `entropyWeightExponent` — also the value every
     *   candidate carries unchanged when $entropyWeightingEnabled is false.
     * @param float $entropyWeightShiftMagnitudeAtRunStart Same, for `entropyWeightShiftMagnitude`.
     * @param int $entropyProbeResultSizeAtRunStart Same, for `entropyProbeResultSize`.
     * @param bool $entropyWeightingEnabled `SprykerCommunity\Shared\SearchRanking\SearchRankingConfig::isEntropyWeightingEnabled()`
     *   at the moment this run starts — when false, the 3 entropy dimensions are omitted from the search
     *   vector entirely rather than searched: a disabled feature has no live effect for the optimizer to
     *   improve, so spending search budget on it would be pure waste (this mirrors, but is distinct from,
     *   $fixedMetricWeights above — that budget must still sum into the simplex; a disabled entropy knob
     *   has no budget to preserve at all).
     * @param \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Reparametrization\SimplexSoftmaxReparametrization|null $simplexSoftmaxReparametrization
     */
    public function __construct(
        array $metrics,
        array $fixedMetricWeights,
        float $relevanceWeightAtRunStart,
        float $entropyWeightExponentAtRunStart,
        float $entropyWeightShiftMagnitudeAtRunStart,
        int $entropyProbeResultSizeAtRunStart,
        bool $entropyWeightingEnabled,
        ?SimplexSoftmaxReparametrization $simplexSoftmaxReparametrization = null,
    ) {
        $this->metrics = array_values($metrics);
        $this->fixedMetricWeights = $fixedMetricWeights;
        $this->fixedWeightBudget = array_sum($fixedMetricWeights);
        $this->simplexSoftmaxReparametrization = $simplexSoftmaxReparametrization ?? new SimplexSoftmaxReparametrization();
        $this->entropyWeightingEnabled = $entropyWeightingEnabled;
        $this->entropyWeightExponentAtRunStart = $entropyWeightExponentAtRunStart;
        $this->entropyWeightShiftMagnitudeAtRunStart = $entropyWeightShiftMagnitudeAtRunStart;
        $this->entropyProbeResultSizeAtRunStart = $entropyProbeResultSizeAtRunStart;

        $maxDistance = SearchRankingOptimizerConfig::getRelevanceWeightTrustRegionMaxDistance();
        $this->relevanceWeightLowerBound = max(0.0, $relevanceWeightAtRunStart - $maxDistance);
        $this->relevanceWeightUpperBound = min(1.0, $relevanceWeightAtRunStart + $maxDistance);

        $exponentMaxDistance = SearchRankingOptimizerConfig::getEntropyWeightExponentTrustRegionMaxDistance();
        $this->entropyWeightExponentLowerBound = max(
            SearchRankingOptimizerConfig::getEntropyWeightExponentLowerBound(),
            $entropyWeightExponentAtRunStart - $exponentMaxDistance,
        );
        $this->entropyWeightExponentUpperBound = min(
            SearchRankingOptimizerConfig::getEntropyWeightExponentUpperBound(),
            $entropyWeightExponentAtRunStart + $exponentMaxDistance,
        );

        $shiftMaxDistance = SearchRankingOptimizerConfig::getEntropyWeightShiftMagnitudeTrustRegionMaxDistance();
        $this->entropyWeightShiftMagnitudeLowerBound = max(
            SearchRankingOptimizerConfig::getEntropyWeightShiftMagnitudeLowerBound(),
            $entropyWeightShiftMagnitudeAtRunStart - $shiftMaxDistance,
        );
        $this->entropyWeightShiftMagnitudeUpperBound = min(
            SearchRankingOptimizerConfig::getEntropyWeightShiftMagnitudeUpperBound(),
            $entropyWeightShiftMagnitudeAtRunStart + $shiftMaxDistance,
        );

        $probeSizeMaxDistance = SearchRankingOptimizerConfig::getEntropyProbeResultSizeTrustRegionMaxDistance();
        $this->entropyProbeResultSizeLowerBound = max(
            SearchRankingOptimizerConfig::getEntropyProbeResultSizeLowerBound(),
            $entropyProbeResultSizeAtRunStart - $probeSizeMaxDistance,
        );
        $this->entropyProbeResultSizeUpperBound = min(
            SearchRankingOptimizerConfig::getMaxEntropyProbeResultSize(),
            $entropyProbeResultSizeAtRunStart + $probeSizeMaxDistance,
        );
    }

    /**
     * @return int
     */
    public function getDimensionCount(): int
    {
        return 1 + $this->getFreeMetricWeightDimensionCount() + $this->getEntropyDimensionCount();
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

        if ($this->entropyWeightingEnabled) {
            $bounds[] = $this->entropyWeightExponentLowerBound;
            $bounds[] = $this->entropyWeightShiftMagnitudeLowerBound;
            $bounds[] = $this->entropyProbeResultSizeLowerBound;
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

        if ($this->entropyWeightingEnabled) {
            $bounds[] = $this->entropyWeightExponentUpperBound;
            $bounds[] = $this->entropyWeightShiftMagnitudeUpperBound;
            $bounds[] = $this->entropyProbeResultSizeUpperBound;
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
        // Clamped to this run's own trust-region bounds -- the SAME bounds getLowerBounds()/
        // getUpperBounds() declared as this dimension's box constraint. Every shipped algorithm already
        // clamps its own candidates there before this method ever sees them, so this is a defensive second
        // line, not the primary enforcement -- but this mapper has no way to verify that guarantee holds
        // for every current AND future caller/algorithm, and a raw out-of-range value here would otherwise
        // flow straight into a persisted (and potentially live-applied) configuration unclamped.
        $relevanceWeight = min($this->relevanceWeightUpperBound, max($this->relevanceWeightLowerBound, $vector[0]));
        $freeDimensionCount = $this->getFreeMetricWeightDimensionCount();
        $freeZ = array_slice($vector, 1, $freeDimensionCount);

        $metricWeights = $this->buildMetricWeightsByName($freeZ);

        if ($this->entropyWeightingEnabled) {
            $entropyWeightExponent = min(
                $this->entropyWeightExponentUpperBound,
                max($this->entropyWeightExponentLowerBound, $vector[1 + $freeDimensionCount]),
            );
            $entropyWeightShiftMagnitude = min(
                $this->entropyWeightShiftMagnitudeUpperBound,
                max($this->entropyWeightShiftMagnitudeLowerBound, $vector[1 + $freeDimensionCount + 1]),
            );
            $entropyProbeResultSize = (int)min(
                SearchRankingOptimizerConfig::getMaxEntropyProbeResultSize(),
                max(
                    SearchRankingOptimizerConfig::getEntropyProbeResultSizeLowerBound(),
                    round($vector[1 + $freeDimensionCount + 2]),
                ),
            );
        } else {
            $entropyWeightExponent = $this->entropyWeightExponentAtRunStart;
            $entropyWeightShiftMagnitude = $this->entropyWeightShiftMagnitudeAtRunStart;
            $entropyProbeResultSize = $this->entropyProbeResultSizeAtRunStart;
        }

        return (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight($relevanceWeight)
            ->setRelevanceSaturationPoint($relevanceSaturationPoint)
            ->setMetricWeights($metricWeights)
            ->setEntropyWeightExponent($entropyWeightExponent)
            ->setEntropyWeightShiftMagnitude($entropyWeightShiftMagnitude)
            ->setEntropyProbeResultSize($entropyProbeResultSize);
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

        if ($this->entropyWeightingEnabled) {
            $vector[] = (float)($configurationTransfer->getEntropyWeightExponent() ?? 1.0);
            $vector[] = (float)($configurationTransfer->getEntropyWeightShiftMagnitude() ?? 0.0);
            $vector[] = (float)($configurationTransfer->getEntropyProbeResultSize() ?? 10);
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

    /**
     * @return int
     */
    protected function getEntropyDimensionCount(): int
    {
        return $this->entropyWeightingEnabled ? 3 : 0;
    }
}
