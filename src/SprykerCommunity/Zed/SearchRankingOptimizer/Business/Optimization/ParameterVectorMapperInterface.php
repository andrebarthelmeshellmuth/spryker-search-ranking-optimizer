<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;

/**
 * The adapter between {@see \BlackboxOptimizer\Algorithm\OptimizerAlgorithmInterface}'s
 * generic, Spryker-agnostic vectors and this package's own real domain: a `relevanceWeight` scalar, a
 * simplex of metric weights (summing to 1, together with any fixed-weight metrics excluded from the
 * search — see the constructor), and 3 independent specificity-aware relevance weighting parameters
 * (`specificityWeightExponent`, `specificityWeightShiftMagnitude`, `specificityBlendWeight`). One mapper instance
 * is scoped to a single optimization run's fixed set of active metrics — build a fresh one per run, never
 * share one across runs with a different metric set.
 */
interface ParameterVectorMapperInterface
{
    /**
     * @return int
     */
    public function getDimensionCount(): int;

    /**
     * @return array<int, float>
     */
    public function getLowerBounds(): array;

    /**
     * @return array<int, float>
     */
    public function getUpperBounds(): array;

    /**
     * @param array<int, float> $vector A vector of {@see getDimensionCount()} values, as an
     *   OptimizerAlgorithmInterface implementation would produce it.
     * @param float $relevanceSaturationPoint Passed through unchanged — Phase O6 never touches this
     *   (Calibration's own concern), but the resulting configuration transfer needs a real value to be
     *   usable as a rank_eval override.
     *
     * @return \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer
     */
    public function mapVectorToConfiguration(array $vector, float $relevanceSaturationPoint): SearchRankingConfigurationStorageTransfer;

    /**
     * The inverse of {@see mapVectorToConfiguration()} — only needed to SEED an optimizer run's initial
     * mean from an existing configuration (e.g. the live one), never called by the optimizer itself during
     * a run.
     *
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer $configurationTransfer
     *
     * @return array<int, float>
     */
    public function mapConfigurationToVector(SearchRankingConfigurationStorageTransfer $configurationTransfer): array;
}
