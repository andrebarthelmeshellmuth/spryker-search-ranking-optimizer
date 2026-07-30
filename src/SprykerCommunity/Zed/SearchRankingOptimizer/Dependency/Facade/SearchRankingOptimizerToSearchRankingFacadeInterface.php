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
     * @return float
     */
    public function getRelevanceSaturationPoint(): float;

    /**
     * @param float $relevanceSaturationPoint
     *
     * @return void
     */
    public function saveRelevanceSaturationPoint(float $relevanceSaturationPoint): void;

    /**
     * @return float
     */
    public function getRelevanceWeight(): float;

    /**
     * @param float $relevanceWeight
     *
     * @return void
     */
    public function saveRelevanceWeight(float $relevanceWeight): void;

    /**
     * @return int
     */
    public function getEntropyProbeResultSize(): int;

    /**
     * @param int $entropyProbeResultSize
     *
     * @return void
     */
    public function saveEntropyProbeResultSize(int $entropyProbeResultSize): void;

    /**
     * @return float
     */
    public function getEntropyWeightExponent(): float;

    /**
     * @param float $entropyWeightExponent
     *
     * @return void
     */
    public function saveEntropyWeightExponent(float $entropyWeightExponent): void;

    /**
     * @return float
     */
    public function getEntropyWeightShiftMagnitude(): float;

    /**
     * @param float $entropyWeightShiftMagnitude
     *
     * @return void
     */
    public function saveEntropyWeightShiftMagnitude(float $entropyWeightShiftMagnitude): void;

    /**
     * Whether entropy-aware relevance weighting is active right now — a pure code-level project flag, not
     * Zed-editable. A checkpoint captures the 3 entropy knobs above regardless of this value (cheap, and
     * keeps a checkpoint a complete snapshot even if the feature gets enabled later), but records this
     * flag alongside them so history honestly shows whether those numbers were actually live at the time,
     * rather than silently implying they always were.
     *
     * @return bool
     */
    public function isEntropyWeightingEnabled(): bool;

    /**
     * Deliberately returns a plain array, not `search-ranking`'s own
     * `SearchRankingMetricTransfer`/`SearchRankingMetricCollectionTransfer` — keeps this interface free of
     * any compile-time reference to a class that only exists when `search-ranking` is actually installed
     * (see the Bridge implementation for where that real coupling lives).
     *
     * @return array<int, array{idSearchRankingMetric: int, name: string, weight: float}>
     */
    public function getMetricWeights(): array;

    /**
     * Writes a single metric's weight through `search-ranking`'s own facade, preserving every other field
     * on that metric (name/formula/isActive/isHigherBetter) — reads the current metric first, mutates
     * only `weight`, then saves the full record back.
     *
     * @param int $idSearchRankingMetric
     * @param float $weight
     *
     * @return bool True if the metric still existed and was updated; false if it no longer exists (a safe
     * no-op — a checkpoint restore may reference a metric deleted since the checkpoint was taken).
     */
    public function saveMetricWeight(int $idSearchRankingMetric, float $weight): bool;

    /**
     * Deliberately returns a plain array, not `search-ranking`'s own
     * `SearchRankingMetricTransfer`/`SearchRankingMetricCollectionTransfer` — same discipline as
     * {@see getMetricWeights()}, keeps this interface free of any compile-time reference to a class that
     * only exists when `search-ranking` is actually installed.
     *
     * @return array<int, array{idSearchRankingMetric: int, name: string}>
     */
    public function getActiveMetrics(): array;

    /**
     * Evaluates how well $idSearchRankingMetric's OWN CONFIGURED formula fits its digest RIGHT NOW — a
     * fresh, side-effect-free read. Returns null when the metric doesn't exist, or has no digest yet.
     *
     * @param int $idSearchRankingMetric
     *
     * @return float|null
     */
    public function evaluateCurrentMetricFit(int $idSearchRankingMetric): ?float;

    /**
     * Deliberately returns a plain array, not `search-ranking`'s own `SearchRankingMetricTransfer` — same
     * discipline as {@see getMetricWeights()}. Returns null when the metric no longer exists.
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
     *
     * @return array<int, array{shape: string, formula: string, rSquared: float, isWinner: bool}>
     */
    public function getFitCandidates(int $idSearchRankingMetric): array;

    /**
     * Writes a single metric's formula through `search-ranking`'s own facade, preserving every other
     * field (name/weight/isActive/isHigherBetter) — same shape as {@see saveMetricWeight()}.
     *
     * @param int $idSearchRankingMetric
     * @param string $formula
     *
     * @return bool True if the metric still existed and was updated; false if it no longer exists.
     */
    public function saveMetricFormula(int $idSearchRankingMetric, string $formula): bool;

    /**
     * Appends an `isChange=false` audit row for $idSearchRankingMetric's CURRENT (unmodified) config and
     * digest — see `search-ranking`'s own `recordCheckOnly()` for the full specification. A safe no-op
     * (returns false) if the metric no longer exists.
     *
     * @param int $idSearchRankingMetric
     *
     * @return bool
     */
    public function recordMetricCheckOnly(int $idSearchRankingMetric): bool;
}
