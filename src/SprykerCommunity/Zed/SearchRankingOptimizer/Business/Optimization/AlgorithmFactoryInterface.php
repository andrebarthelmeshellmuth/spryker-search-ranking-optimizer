<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

use BlackboxOptimizer\Algorithm\OptimizerAlgorithmInterface;

/**
 * The single place this package maps `SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_*` values to
 * concrete `BlackboxOptimizer\Algorithm\*` classes — both {@see OptimizationRunner} (an actual run) and the
 * automated-run form (algorithm metadata only) go through this instead of each hardcoding their own copy
 * of the mapping.
 */
interface AlgorithmFactoryInterface
{
    /**
     * One unconfigured instance per known `SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_*` value,
     * keyed by that value — never {@see OptimizerAlgorithmInterface::optimize()}d, only used to read each
     * algorithm's own `getName()`/`getDescription()` metadata.
     *
     * @return array<string, \BlackboxOptimizer\Algorithm\OptimizerAlgorithmInterface>
     */
    public function createAll(): array;

    /**
     * A single instance configured and ready for an actual run. An unrecognized $algorithmName falls back
     * to CMA-ES, the same default the mapping this replaces used.
     *
     * @param string $algorithmName One of `SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_*`.
     * @param int $populationSize
     * @param int $maxGenerations
     */
    public function create(string $algorithmName, int $populationSize, int $maxGenerations): OptimizerAlgorithmInterface;
}
