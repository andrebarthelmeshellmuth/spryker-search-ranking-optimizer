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
}
