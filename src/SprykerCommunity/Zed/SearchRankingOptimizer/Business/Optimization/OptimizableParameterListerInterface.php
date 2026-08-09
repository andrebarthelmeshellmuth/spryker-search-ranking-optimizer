<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

interface OptimizableParameterListerInterface
{
    /**
     * A read-only snapshot of every scalar/metric an optimization run for this (store, locale) would work
     * with RIGHT NOW — feeds the Automated Weight Optimization run form's own parameter checklist, so a
     * human can see what's actually there before choosing what to fix. Mirrors, but does not share code
     * with, {@see OptimizationRunner}'s own determinism split — this is a pure read, no run is created or
     * touched. `metrics` omits any metric whose formula is non-deterministic: that metric is held fixed at
     * its live weight unconditionally, no checklist choice can ever change that, so it isn't a real
     * checklist item at all.
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
    public function list(string $storeName, string $localeName): array;
}
