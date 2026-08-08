<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade;

use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig as SharedSearchRankingConfig;

interface SearchRankingOptimizerToSearchRankingFacadeInterface
{
    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function getRelevanceSaturationPoint(string $storeName, string $localeName): float;

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $relevanceSaturationPoint
     */
    public function saveRelevanceSaturationPoint(string $storeName, string $localeName, float $relevanceSaturationPoint): void;

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function getRelevanceWeight(string $storeName, string $localeName): float;

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $relevanceWeight
     */
    public function saveRelevanceWeight(string $storeName, string $localeName, float $relevanceWeight): void;

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function getSpecificitySaturationPoint(string $storeName, string $localeName): float;

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificitySaturationPoint
     */
    public function saveSpecificitySaturationPoint(string $storeName, string $localeName, float $specificitySaturationPoint): void;

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function getSpecificityBlendWeight(string $storeName, string $localeName): float;

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificityBlendWeight
     */
    public function saveSpecificityBlendWeight(string $storeName, string $localeName, float $specificityBlendWeight): void;

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function getSpecificityCurveExponent(string $storeName, string $localeName): float;

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificityCurveExponent
     */
    public function saveSpecificityCurveExponent(string $storeName, string $localeName, float $specificityCurveExponent): void;

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function getSpecificityWeightExponent(string $storeName, string $localeName): float;

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificityWeightExponent
     */
    public function saveSpecificityWeightExponent(string $storeName, string $localeName, float $specificityWeightExponent): void;

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function getSpecificityWeightShiftMagnitude(string $storeName, string $localeName): float;

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $specificityWeightShiftMagnitude
     */
    public function saveSpecificityWeightShiftMagnitude(string $storeName, string $localeName, float $specificityWeightShiftMagnitude): void;

    /**
     * Whether specificity-aware relevance weighting is active right now — a pure code-level project flag,
     * not Zed-editable. A checkpoint captures the 3 specificity knobs above regardless of this value
     * (cheap, and keeps a checkpoint a complete snapshot even if the feature gets enabled later), but
     * records this flag alongside them so history honestly shows whether those numbers were actually live
     * at the time, rather than silently implying they always were.
     */
    public function isSpecificityWeightingEnabled(): bool;

    /**
     * Deliberately returns a plain array, not `search-ranking`'s own
     * `SearchRankingMetricTransfer`/`SearchRankingMetricCollectionTransfer` — keeps this interface free of
     * any compile-time reference to a class that only exists when `search-ranking` is actually installed
     * (see the Bridge implementation for where that real coupling lives). Weight is store+locale scoped
     * on `search-ranking`'s own side; the returned weight is for the given scope (0.0 if none saved yet).
     * `isLocaleScoped=false` means this metric is store-wide on `search-ranking`'s own side — writing its
     * weight for ANY locale of this store fans out to every real locale of the store (see
     * {@see resolveEffectiveWeightLocales()}); a consumer here must not treat that weight as an
     * independent per-locale value.
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<int, array{idSearchRankingMetric: int, name: string, weight: float, isLocaleScoped: bool}>
     */
    public function getMetricWeights(string $storeName, string $localeName): array;

    /**
     * Every locale name a {@see saveMetricWeight()} call for this metric/store/locale would actually write
     * to: just $localeName when the metric is locale-scoped (the normal case), or every real locale of
     * $storeName when it isn't (`isLocaleScoped=false`) — lets a caller know the real blast radius of a
     * weight write before committing it. A metric that no longer exists resolves to just [$localeName].
     *
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<string>
     */
    public function resolveEffectiveWeightLocales(int $idSearchRankingMetric, string $storeName, string $localeName): array;

    /**
     * Writes a single metric's weight for the given store+locale through `search-ranking`'s own facade.
     *
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     * @param float $weight
     * @param string $changeSource One of `SprykerCommunity\Shared\SearchRanking\SearchRankingConfig::CHANGE_SOURCE_*`
     * — no default, since this bridge has no single sensible one: today's two callers
     * ({@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationApplier} and
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRestorer})
     * need CHANGE_SOURCE_OPTIMIZER_APPLY and CHANGE_SOURCE_CHECKPOINT_RESTORE respectively.
     *
     * @return bool True if the metric still existed and was updated; false if it no longer exists (a safe
     * no-op — a checkpoint restore may reference a metric deleted since the checkpoint was taken).
     */
    public function saveMetricWeight(int $idSearchRankingMetric, string $storeName, string $localeName, float $weight, string $changeSource): bool;

    /**
     * Deliberately returns a plain array, not `search-ranking`'s own
     * `SearchRankingMetricTransfer`/`SearchRankingMetricCollectionTransfer` — same discipline as
     * {@see getMetricWeights()}, keeps this interface free of any compile-time reference to a class that
     * only exists when `search-ranking` is actually installed. `isActive` IS store-scoped on
     * `search-ranking`'s own side (its store-scoped-formula migration, see that package's own project
     * memory) — pass the real scope you mean to check, `name` is the only field here that's still global.
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<int, array{idSearchRankingMetric: int, name: string, isLocaleScoped: bool}>
     */
    public function getActiveMetrics(string $storeName, string $localeName): array;

    /**
     * The name of `search-ranking`'s own configured random-tie-breaker metric — lets a consumer here
     * recognize and exclude it (e.g. from a fit-quality/auto-tune settings list, where a formula that is
     * `random()` by design has no meaningful R² and nothing to auto-tune) without hardcoding a name that
     * a project could reconfigure on `search-ranking`'s own side.
     */
    public function getRandomMetricName(): string;

    /**
     * Evaluates how well $idSearchRankingMetric's OWN CONFIGURED formula fits its digest RIGHT NOW — a
     * fresh, side-effect-free read. Returns null when the metric doesn't exist, or has no digest yet.
     *
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     */
    public function evaluateCurrentMetricFit(int $idSearchRankingMetric, string $storeName, string $localeName): ?float;

    /**
     * Same fit check as {@see evaluateCurrentMetricFit()}, run once per real locale of $storeName —
     * `search-ranking` now supports a genuinely per-locale formula (`isLocaleScoped=true`, rare; most
     * metrics stay store-wide), so this is the evidence a curator uses to decide whether a metric should
     * flip that flag, as well as the diagnostic for whether a store-wide formula still fits every locale's
     * own real data comparably well. Keyed by locale name; a locale with no digest yet maps to null.
     *
     * @param int $idSearchRankingMetric
     * @param string $storeName
     *
     * @return array<string, float|null>
     */
    public function evaluateCurrentMetricFitAcrossLocales(int $idSearchRankingMetric, string $storeName): array;

    /**
     * Deliberately returns a plain array, not `search-ranking`'s own `SearchRankingMetricTransfer` — same
     * discipline as {@see getMetricWeights()}. Returns null when the metric no longer exists.
     * formula/isActive/shape ARE store-scoped on `search-ranking`'s own side (its store-scoped-formula
     * migration) — this returns the values for the given $storeName specifically, not a global fact.
     * name/isHigherBetter are the only fields here still genuinely global.
     *
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     *
     * @return array{idSearchRankingMetric: int, name: string, formula: string, isHigherBetter: bool, shape: string|null, isLocaleScoped: bool}|null
     */
    public function findMetricDetail(int $idSearchRankingMetric, string $storeName, string $localeName): ?array;

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
     * field already on the transfer this reads first (name/isActive/isHigherBetter). formula is
     * store-scoped on `search-ranking`'s own side — writes for $storeName specifically, not globally.
     *
     * @param int $idSearchRankingMetric
     * @param string $formula
     * @param string $storeName
     * @param string $localeName
     * @param string $changeSource One of `SprykerCommunity\Shared\SearchRanking\SearchRankingConfig::CHANGE_SOURCE_*`
     * — defaults to CHANGE_SOURCE_AUTO_TUNE, this bridge method's only caller today
     * ({@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunner}).
     *
     * @return bool True if the metric still existed and was updated; false if it no longer exists.
     */
    public function saveMetricFormula(
        int $idSearchRankingMetric,
        string $formula,
        string $storeName,
        string $localeName,
        string $changeSource = SharedSearchRankingConfig::CHANGE_SOURCE_AUTO_TUNE,
    ): bool;

    /**
     * Appends an `isChange=false` audit row for $idSearchRankingMetric's CURRENT (unmodified) config,
     * weight (at the given store+locale), and digest — see `search-ranking`'s own `recordCheckOnly()` for
     * the full specification. A safe no-op (returns false) if the metric no longer exists.
     *
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     */
    public function recordMetricCheckOnly(int $idSearchRankingMetric, string $storeName, string $localeName): bool;

    /**
     * True if the given store has any explicitly-saved metric store-config row — lets
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunner} skip a
     * configured store that has never actually had `search-ranking` set up for it, rather than treating
     * an empty/default state as "nothing to refit".
     *
     * @param string $storeName
     */
    public function hasStoreConfiguration(string $storeName): bool;
}
