<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use LogicException;
use SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Reparametrization\SimplexSoftmaxReparametrization;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

/**
 * Vector layout, fixed for the lifetime of one instance, built up left to right from whichever pieces
 * below are actually FREE (a piece the caller pinned via one of the constructor's `$fixed*` parameters is
 * omitted from the vector entirely, not merely clamped — same treatment the whole specificity block
 * already got when disabled, generalized here to every individual scalar):
 * - `relevanceWeight`, when free: box-bounded to a trust region around the value it had when this mapper
 *   was built (see {@see SearchRankingOptimizerConfig::getRelevanceWeightTrustRegionMaxDistance()}),
 *   clipped to [0;1]. Omitted when the caller passed `$fixedRelevanceWeight`; every candidate configuration
 *   this mapper produces then simply carries that fixed value unchanged.
 * - The free z values feeding {@see SimplexSoftmaxReparametrization} for the OPTIMIZABLE metrics (metric 0
 *   is the pinned reference weight, never a free dimension) — omitted entirely when there are 0 or 1
 *   optimizable metrics, since a simplex of size <= 1 has no real degrees of freedom (0 metrics: nothing
 *   to weight at all; 1 metric: its weight is trivially always the full remaining budget). A metric the
 *   caller excluded via $fixedMetricWeights (whether because its formula is non-deterministic, or because a
 *   human chose to pin it at a specific value) is held constant here exactly like before — this part of the
 *   layout is unchanged.
 * - The 4 specificity-aware relevance weighting knobs (`specificityCurveExponent`, `specificityWeightExponent`,
 *   `specificityWeightShiftMagnitude`, `specificityBlendWeight`), present ONLY when `search-ranking`'s
 *   specificity-aware relevance weighting is actually enabled (see the constructor's
 *   `$specificityWeightingEnabled`) AND the caller did not individually pin that one knob via its own
 *   `$fixed*` parameter. Each free one is its own independent box-bounded value (a trust region around its
 *   OWN value at run start, clipped to its own absolute bounds), not part of any simplex. A knob that's
 *   fixed (either the whole block is disabled, or this one knob alone was pinned) carries a fixed value
 *   through unchanged on every candidate this mapper produces instead of consuming vector budget.
 *
 * relevanceSaturationPoint/specificitySaturationPoint are deliberately never part of this vector at all --
 * see {@see ParameterVectorMapperInterface} and this package's own README/memory for why (Calibration's
 * own, already-solved concern).
 */
class ParameterVectorMapper implements ParameterVectorMapperInterface
{
    /**
     * @var array<int, string>
     */
    protected const SPECIFICITY_DIMENSION_KEYS = [
        'specificityCurveExponent',
        'specificityWeightExponent',
        'specificityWeightShiftMagnitude',
        'specificityBlendWeight',
    ];

    /**
     * @var array<int, array{idSearchRankingMetric: int, name: string}>
     */
    protected array $metrics;

    /**
     * @var array<string, float>
     */
    protected array $fixedMetricWeights;

    protected float $fixedWeightBudget;

    protected float $relevanceWeightLowerBound;

    protected float $relevanceWeightUpperBound;

    /**
     * Null means free (the normal case) -- a non-null value pins relevanceWeight at exactly that number,
     * omitting it from the vector entirely.
     */
    protected ?float $fixedRelevanceWeight;

    /**
     * @var array<string, float> Keyed by {@see SPECIFICITY_DIMENSION_KEYS}.
     */
    protected array $specificityAtRunStart;

    /**
     * @var array<string, float|null> Keyed by {@see SPECIFICITY_DIMENSION_KEYS}. Null means free for that
     *   one knob (subject to $specificityWeightingEnabled still gating the whole block); a non-null value
     *   pins that knob at exactly that number, omitting it from the vector entirely.
     */
    protected array $specificityFixedValue;

    /**
     * @var array<string, float> Keyed by {@see SPECIFICITY_DIMENSION_KEYS}.
     */
    protected array $specificityLowerBound = [];

    /**
     * @var array<string, float> Keyed by {@see SPECIFICITY_DIMENSION_KEYS}.
     */
    protected array $specificityUpperBound = [];

    protected bool $specificityWeightingEnabled;

    /**
     * @param array<int, array{idSearchRankingMetric: int, name: string}> $metrics The OPTIMIZABLE active
     *   metrics this run's simplex searches over — same plain shape as
     *   {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface::getActiveMetrics()},
     *   already filtered to exclude any metric a {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismCheckerInterface}
     *   found non-deterministic, OR that a human chose to pin. Order matters: metrics[0] becomes the
     *   simplex's pinned reference weight.
     * @param array<string, float> $fixedMetricWeights Active metrics EXCLUDED from the search (a
     *   non-deterministic formula, e.g. a placeholder/noise metric — optimizing a weight against pure
     *   noise is meaningless; OR a human explicitly chose to pin this metric) — name => the weight to hold
     *   constant for the whole run (not necessarily its live weight — a human-chosen pin can be any value).
     *   Reserves that much of the [0;1] simplex budget up front; the optimizable metrics' own simplex is
     *   scaled to fill exactly what's left, so the full set (optimizable + fixed) still sums to 1 on every
     *   candidate this mapper produces.
     * @param float $relevanceWeightAtRunStart The relevanceWeight value to center this run's trust region
     *   on when it's free — normally the LIVE value at the moment the run starts, read once and fixed for
     *   the whole run. Still consulted even when $fixedRelevanceWeight pins the dimension (cheap to compute,
     *   keeps this constructor's own logic uniform), but has no effect on any candidate this mapper produces
     *   in that case.
     * @param float $specificityCurveExponentAtRunStart Same "center this run's trust region on the live
     *   value" treatment as $relevanceWeightAtRunStart, for `specificityCurveExponent` — also the value
     *   every candidate carries unchanged when $specificityWeightingEnabled is false.
     * @param float $specificityWeightExponentAtRunStart Same, for `specificityWeightExponent`.
     * @param float $specificityWeightShiftMagnitudeAtRunStart Same, for `specificityWeightShiftMagnitude`.
     * @param float $specificityBlendWeightAtRunStart Same, for `specificityBlendWeight`.
     * @param bool $specificityWeightingEnabled `SprykerCommunity\Shared\SearchRanking\SearchRankingConfig::isSpecificityWeightingEnabled()`
     *   at the moment this run starts — when false, the 4 specificity dimensions are omitted from the
     *   search vector entirely rather than searched: a disabled feature has no live effect for the
     *   optimizer to improve, so spending search budget on it would be pure waste (this mirrors, but is
     *   distinct from, $fixedMetricWeights above — that budget must still sum into the simplex; a disabled
     *   specificity knob has no budget to preserve at all). Any of the 4 $fixedSpecificity* parameters below
     *   is meaningless (ignored) when this is false.
     * @param float|null $fixedRelevanceWeight Null (the default): relevanceWeight is free, same as before
     *   this parameter existed. Non-null: a human chose to pin relevanceWeight at exactly this value —
     *   omitted from the vector, every candidate carries it through unchanged.
     * @param float|null $fixedSpecificityCurveExponent Same pin/free semantics as $fixedRelevanceWeight, for
     *   `specificityCurveExponent`. Ignored when $specificityWeightingEnabled is false.
     * @param float|null $fixedSpecificityWeightExponent Same, for `specificityWeightExponent`.
     * @param float|null $fixedSpecificityWeightShiftMagnitude Same, for `specificityWeightShiftMagnitude`.
     * @param float|null $fixedSpecificityBlendWeight Same, for `specificityBlendWeight`.
     * @param \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Reparametrization\SimplexSoftmaxReparametrization $simplexSoftmaxReparametrization
     */
    public function __construct(
        array $metrics,
        array $fixedMetricWeights,
        float $relevanceWeightAtRunStart,
        float $specificityCurveExponentAtRunStart,
        float $specificityWeightExponentAtRunStart,
        float $specificityWeightShiftMagnitudeAtRunStart,
        float $specificityBlendWeightAtRunStart,
        bool $specificityWeightingEnabled,
        ?float $fixedRelevanceWeight = null,
        ?float $fixedSpecificityCurveExponent = null,
        ?float $fixedSpecificityWeightExponent = null,
        ?float $fixedSpecificityWeightShiftMagnitude = null,
        ?float $fixedSpecificityBlendWeight = null,
        protected SimplexSoftmaxReparametrization $simplexSoftmaxReparametrization = new SimplexSoftmaxReparametrization(),
    ) {
        $this->metrics = array_values($metrics);
        $this->fixedMetricWeights = $fixedMetricWeights;
        $this->fixedWeightBudget = array_sum($fixedMetricWeights);
        $this->specificityWeightingEnabled = $specificityWeightingEnabled;
        $this->fixedRelevanceWeight = $fixedRelevanceWeight;

        $this->specificityAtRunStart = [
            'specificityCurveExponent' => $specificityCurveExponentAtRunStart,
            'specificityWeightExponent' => $specificityWeightExponentAtRunStart,
            'specificityWeightShiftMagnitude' => $specificityWeightShiftMagnitudeAtRunStart,
            'specificityBlendWeight' => $specificityBlendWeightAtRunStart,
        ];
        $this->specificityFixedValue = [
            'specificityCurveExponent' => $fixedSpecificityCurveExponent,
            'specificityWeightExponent' => $fixedSpecificityWeightExponent,
            'specificityWeightShiftMagnitude' => $fixedSpecificityWeightShiftMagnitude,
            'specificityBlendWeight' => $fixedSpecificityBlendWeight,
        ];

        $maxDistance = SearchRankingOptimizerConfig::getRelevanceWeightTrustRegionMaxDistance();
        $this->relevanceWeightLowerBound = max(0.0, $relevanceWeightAtRunStart - $maxDistance);
        $this->relevanceWeightUpperBound = min(1.0, $relevanceWeightAtRunStart + $maxDistance);

        $curveExponentMaxDistance = SearchRankingOptimizerConfig::getSpecificityCurveExponentTrustRegionMaxDistance();
        $this->specificityLowerBound['specificityCurveExponent'] = max(
            SearchRankingOptimizerConfig::getSpecificityCurveExponentLowerBound(),
            $specificityCurveExponentAtRunStart - $curveExponentMaxDistance,
        );
        $this->specificityUpperBound['specificityCurveExponent'] = min(
            SearchRankingOptimizerConfig::getSpecificityCurveExponentUpperBound(),
            $specificityCurveExponentAtRunStart + $curveExponentMaxDistance,
        );

        $exponentMaxDistance = SearchRankingOptimizerConfig::getSpecificityWeightExponentTrustRegionMaxDistance();
        $this->specificityLowerBound['specificityWeightExponent'] = max(
            SearchRankingOptimizerConfig::getSpecificityWeightExponentLowerBound(),
            $specificityWeightExponentAtRunStart - $exponentMaxDistance,
        );
        $this->specificityUpperBound['specificityWeightExponent'] = min(
            SearchRankingOptimizerConfig::getSpecificityWeightExponentUpperBound(),
            $specificityWeightExponentAtRunStart + $exponentMaxDistance,
        );

        $shiftMaxDistance = SearchRankingOptimizerConfig::getSpecificityWeightShiftMagnitudeTrustRegionMaxDistance();
        $this->specificityLowerBound['specificityWeightShiftMagnitude'] = max(
            SearchRankingOptimizerConfig::getSpecificityWeightShiftMagnitudeLowerBound(),
            $specificityWeightShiftMagnitudeAtRunStart - $shiftMaxDistance,
        );
        $this->specificityUpperBound['specificityWeightShiftMagnitude'] = min(
            SearchRankingOptimizerConfig::getSpecificityWeightShiftMagnitudeUpperBound(),
            $specificityWeightShiftMagnitudeAtRunStart + $shiftMaxDistance,
        );

        $blendWeightMaxDistance = SearchRankingOptimizerConfig::getSpecificityBlendWeightTrustRegionMaxDistance();
        $this->specificityLowerBound['specificityBlendWeight'] = max(
            SearchRankingOptimizerConfig::getSpecificityBlendWeightLowerBound(),
            $specificityBlendWeightAtRunStart - $blendWeightMaxDistance,
        );
        $this->specificityUpperBound['specificityBlendWeight'] = min(
            SearchRankingOptimizerConfig::getSpecificityBlendWeightUpperBound(),
            $specificityBlendWeightAtRunStart + $blendWeightMaxDistance,
        );
    }

    public function getDimensionCount(): int
    {
        return ($this->isRelevanceWeightFree() ? 1 : 0)
            + $this->getFreeMetricWeightDimensionCount()
            + count($this->getFreeSpecificityKeys());
    }

    /**
     * @return array<int, float>
     */
    public function getLowerBounds(): array
    {
        $bounds = [];

        if ($this->isRelevanceWeightFree()) {
            $bounds[] = $this->relevanceWeightLowerBound;
        }

        $freeDimensionCount = $this->getFreeMetricWeightDimensionCount();
        $zSpaceBound = SearchRankingOptimizerConfig::getMetricWeightZSpaceBound();

        for ($i = 0; $i < $freeDimensionCount; $i++) {
            $bounds[] = -$zSpaceBound;
        }

        foreach ($this->getFreeSpecificityKeys() as $key) {
            $bounds[] = $this->specificityLowerBound[$key];
        }

        return $bounds;
    }

    /**
     * @return array<int, float>
     */
    public function getUpperBounds(): array
    {
        $bounds = [];

        if ($this->isRelevanceWeightFree()) {
            $bounds[] = $this->relevanceWeightUpperBound;
        }

        $freeDimensionCount = $this->getFreeMetricWeightDimensionCount();
        $zSpaceBound = SearchRankingOptimizerConfig::getMetricWeightZSpaceBound();

        for ($i = 0; $i < $freeDimensionCount; $i++) {
            $bounds[] = $zSpaceBound;
        }

        foreach ($this->getFreeSpecificityKeys() as $key) {
            $bounds[] = $this->specificityUpperBound[$key];
        }

        return $bounds;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int, float> $vector
     * @param float $relevanceSaturationPoint
     */
    public function mapVectorToConfiguration(array $vector, float $relevanceSaturationPoint): SearchRankingConfigurationStorageTransfer
    {
        $vector = array_values($vector);
        $offset = 0;

        // Clamped to this run's own trust-region bounds -- the SAME bounds getLowerBounds()/
        // getUpperBounds() declared as this dimension's box constraint. Every shipped algorithm already
        // clamps its own candidates there before this method ever sees them, so this is a defensive second
        // line, not the primary enforcement -- but this mapper has no way to verify that guarantee holds
        // for every current AND future caller/algorithm, and a raw out-of-range value here would otherwise
        // flow straight into a persisted (and potentially live-applied) configuration unclamped.
        if ($this->isRelevanceWeightFree()) {
            $relevanceWeight = min($this->relevanceWeightUpperBound, max($this->relevanceWeightLowerBound, $vector[$offset]));
            $offset++;
        } else {
            $relevanceWeight = $this->fixedRelevanceWeight;
        }

        $freeDimensionCount = $this->getFreeMetricWeightDimensionCount();
        $freeZ = array_slice($vector, $offset, $freeDimensionCount);
        $offset += $freeDimensionCount;

        $metricWeights = $this->buildMetricWeightsByName($freeZ);

        $specificityValues = [];

        foreach (static::SPECIFICITY_DIMENSION_KEYS as $key) {
            if (!$this->specificityWeightingEnabled) {
                $specificityValues[$key] = $this->specificityAtRunStart[$key];

                continue;
            }

            if ($this->specificityFixedValue[$key] !== null) {
                $specificityValues[$key] = $this->specificityFixedValue[$key];

                continue;
            }

            $specificityValues[$key] = min($this->specificityUpperBound[$key], max($this->specificityLowerBound[$key], $vector[$offset]));
            $offset++;
        }

        return (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight($relevanceWeight)
            ->setRelevanceSaturationPoint($relevanceSaturationPoint)
            ->setMetricWeights($metricWeights)
            ->setSpecificityCurveExponent($specificityValues['specificityCurveExponent'])
            ->setSpecificityWeightExponent($specificityValues['specificityWeightExponent'])
            ->setSpecificityWeightShiftMagnitude($specificityValues['specificityWeightShiftMagnitude'])
            ->setSpecificityBlendWeight($specificityValues['specificityBlendWeight']);
    }

    /**
     * {@inheritDoc}
     *
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer $configurationTransfer
     *
     * @throws \LogicException
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

        $vector = [];

        if ($this->isRelevanceWeightFree()) {
            $vector[] = (float)$configurationTransfer->getRelevanceWeight();
        }

        if ($this->getFreeMetricWeightDimensionCount() > 0) {
            $vector = array_merge($vector, $this->simplexSoftmaxReparametrization->toFreeZ($orderedWeights));
        }

        foreach ($this->getFreeSpecificityKeys() as $key) {
            $vector[] = (float)match ($key) {
                'specificityCurveExponent' => $configurationTransfer->getSpecificityCurveExponent() ?? 1.0,
                'specificityWeightExponent' => $configurationTransfer->getSpecificityWeightExponent() ?? 1.0,
                'specificityWeightShiftMagnitude' => $configurationTransfer->getSpecificityWeightShiftMagnitude() ?? 0.0,
                'specificityBlendWeight' => $configurationTransfer->getSpecificityBlendWeight() ?? 0.7,
                default => throw new LogicException(sprintf('Unknown specificity dimension key "%s" -- SPECIFICITY_DIMENSION_KEYS and this match must stay in sync.', $key)),
            };
        }

        return $vector;
    }

    protected function isRelevanceWeightFree(): bool
    {
        return $this->fixedRelevanceWeight === null;
    }

    /**
     * @return array<int, string>
     */
    protected function getFreeSpecificityKeys(): array
    {
        if (!$this->specificityWeightingEnabled) {
            return [];
        }

        return array_values(array_filter(
            static::SPECIFICITY_DIMENSION_KEYS,
            fn (string $key): bool => $this->specificityFixedValue[$key] === null,
        ));
    }

    /**
     * @param array<int, float> $freeZ
     *
     * @return array<string, float>
     */
    protected function buildMetricWeightsByName(array $freeZ): array
    {
        $metricWeightsByName = $this->fixedMetricWeights;

        if ($this->fixedWeightBudget > 1.0) {
            // The caller's own fixed weights already exceed the full [0;1] budget on their own (live
            // weights that were never renormalized, floating-point drift across many small weights) --
            // every optimizable metric correctly gets zero either way, but simply flooring available
            // budget at 0 without ALSO rescaling the fixed weights themselves would leave them summing to
            // MORE than 1, silently violating the one invariant this whole mapper exists to guarantee.
            $scale = 1.0 / $this->fixedWeightBudget;

            foreach ($metricWeightsByName as $name => $weight) {
                $metricWeightsByName[$name] = $weight * $scale;
            }

            $availableBudget = 0.0;
        } else {
            $availableBudget = 1.0 - $this->fixedWeightBudget;
        }

        if ($this->metrics === []) {
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

    protected function getFreeMetricWeightDimensionCount(): int
    {
        return max(0, count($this->metrics) - 1);
    }
}
