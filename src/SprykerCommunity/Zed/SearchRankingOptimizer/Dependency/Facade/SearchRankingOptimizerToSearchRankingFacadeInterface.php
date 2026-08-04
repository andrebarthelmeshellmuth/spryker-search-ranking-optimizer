<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade;

interface SearchRankingOptimizerToSearchRankingFacadeInterface
{
    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return float
     */
    public function getRelevanceSaturationPoint(string $storeName, string $localeName): float;

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $relevanceSaturationPoint
     *
     * @return void
     */
    public function saveRelevanceSaturationPoint(string $storeName, string $localeName, float $relevanceSaturationPoint): void;

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return float
     */
    public function getRelevanceWeight(string $storeName, string $localeName): float;

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $relevanceWeight
     *
     * @return void
     */
    public function saveRelevanceWeight(string $storeName, string $localeName, float $relevanceWeight): void;

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return float
     */
    public function getSpecificitySaturationPoint(string $storeName, string $localeName): float;

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificitySaturationPoint
     *
     * @return void
     */
    public function saveSpecificitySaturationPoint(string $storeName, string $localeName, float $specificitySaturationPoint): void;

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return float
     */
    public function getSpecificityBlendWeight(string $storeName, string $localeName): float;

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificityBlendWeight
     *
     * @return void
     */
    public function saveSpecificityBlendWeight(string $storeName, string $localeName, float $specificityBlendWeight): void;

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return float
     */
    public function getSpecificityWeightExponent(string $storeName, string $localeName): float;

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificityWeightExponent
     *
     * @return void
     */
    public function saveSpecificityWeightExponent(string $storeName, string $localeName, float $specificityWeightExponent): void;

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return float
     */
    public function getSpecificityWeightShiftMagnitude(string $storeName, string $localeName): float;

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificityWeightShiftMagnitude
     *
     * @return void
     */
    public function saveSpecificityWeightShiftMagnitude(string $storeName, string $localeName, float $specificityWeightShiftMagnitude): void;

    /**
     * Whether specificity-aware relevance weighting is active right now — a pure code-level project flag,
     * not Zed-editable. A checkpoint captures the 3 specificity knobs above regardless of this value
     * (cheap, and keeps a checkpoint a complete snapshot even if the feature gets enabled later), but
     * records this flag alongside them so history honestly shows whether those numbers were actually live
     * at the time, rather than silently implying they always were.
     *
     * @return bool
     */
    public function isSpecificityWeightingEnabled(): bool;

    /**
     * Deliberately returns a plain array, not `search-ranking`'s own
     * `SearchRankingMetricTransfer`/`SearchRankingMetricCollectionTransfer` — keeps this interface free of
     * any compile-time reference to a class that only exists when `search-ranking` is actually installed
     * (see the Bridge implementation for where that real coupling lives). Weight is store+locale scoped
     * on `search-ranking`'s own side; the returned weight is for the given scope (0.0 if none saved yet).
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<int, array{idSearchRankingMetric: int, name: string, weight: float}>
     */
    public function getMetricWeights(string $storeName, string $localeName): array;

    /**
     * Writes a single metric's weight for the given store+locale through `search-ranking`'s own facade.
     *
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     * @param float $weight
     *
     * @return bool True if the metric still existed and was updated; false if it no longer exists (a safe
     * no-op — a checkpoint restore may reference a metric deleted since the checkpoint was taken).
     */
    public function saveMetricWeight(int $idSearchRankingMetric, string $storeName, string $localeName, float $weight): bool;

    /**
     * Deliberately returns a plain array, not `search-ranking`'s own
     * `SearchRankingMetricTransfer`/`SearchRankingMetricCollectionTransfer` — same discipline as
     * {@see getMetricWeights()}, keeps this interface free of any compile-time reference to a class that
     * only exists when `search-ranking` is actually installed. Not store/locale scoped — `isActive` and
     * `name` are both global identity fields on `search-ranking`'s own side.
     *
     * @return array<int, array{idSearchRankingMetric: int, name: string}>
     */
    public function getActiveMetrics(): array;

    /**
     * Evaluates how well $idSearchRankingMetric's OWN CONFIGURED formula fits its digest RIGHT NOW — a
     * fresh, side-effect-free read. Returns null when the metric doesn't exist, or has no digest yet.
     *
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     *
     * @return float|null
     */
    public function evaluateCurrentMetricFit(int $idSearchRankingMetric, string $storeName, string $localeName): ?float;

    /**
     * Deliberately returns a plain array, not `search-ranking`'s own `SearchRankingMetricTransfer` — same
     * discipline as {@see getMetricWeights()}. Returns null when the metric no longer exists. Not
     * store/locale scoped — every field returned (name/formula/isHigherBetter/shape) is a global identity
     * field on `search-ranking`'s own side.
     *
     * @param int $idSearchRankingMetric
     *
     * @return array{idSearchRankingMetric: int, name: string, formula: string, isHigherBetter: bool, shape: string|null}|null
     */
    public function findMetricDetail(int $idSearchRankingMetric): ?array;

    /**
     * Fresh closed-form curve-fit candidates for $idSearchRankingMetric's own digest, ranked and flagged
     * the same way `search-ranking`'s own normalization-authoring GUI preview is — deliberately a plain
     * array, not `SearchRankingCurveFitCandidateTransfer`, same discipline as every other method here.
     * Empty when the metric doesn't exist or has no digest yet.
     *
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<int, array{shape: string, formula: string, rSquared: float, isWinner: bool}>
     */
    public function getFitCandidates(int $idSearchRankingMetric, string $storeName, string $localeName): array;

    /**
     * Writes a single metric's formula through `search-ranking`'s own facade, preserving every other
     * global identity field (name/isActive/isHigherBetter). Not store/locale scoped — a formula is a
     * global identity field on `search-ranking`'s own side.
     *
     * @param int $idSearchRankingMetric
     * @param string $formula
     *
     * @return bool True if the metric still existed and was updated; false if it no longer exists.
     */
    public function saveMetricFormula(int $idSearchRankingMetric, string $formula): bool;

    /**
     * Appends an `isChange=false` audit row for $idSearchRankingMetric's CURRENT (unmodified) config,
     * weight (at the given store+locale), and digest — see `search-ranking`'s own `recordCheckOnly()` for
     * the full specification. A safe no-op (returns false) if the metric no longer exists.
     *
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     *
     * @return bool
     */
    public function recordMetricCheckOnly(int $idSearchRankingMetric, string $storeName, string $localeName): bool;
}
