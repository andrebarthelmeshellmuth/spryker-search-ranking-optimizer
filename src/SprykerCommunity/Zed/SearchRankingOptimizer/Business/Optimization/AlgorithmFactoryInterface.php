<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

use BlackboxOptimizer\Algorithm\OptimizerAlgorithmInterface;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

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
     * to CMA-ES; an unrecognized $terminationMode falls back to
     * `SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_FIXED_BUDGET` the same way.
     *
     * @param string $algorithmName One of `SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_*`.
     * @param int $populationSize
     * @param int $maxGenerations
     * @param string $terminationMode One of `SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_*`.
     *   See that config class's own docblocks for exactly what each value does; in short: `FIXED_BUDGET`
     *   (the default) is a single run capped at $maxGenerations,
     *   `TRUSTED_SINGLE_RUN` is a single run governed by the algorithm's own convergence/divergence/plateau
     *   detection instead, `RESTART_ON_PLATEAU` wraps the algorithm in
     *   `blackbox-optimizer`'s `RestartingOptimizerDecorator`, and `RESTART_ON_PLATEAU_TRUSTED_BUDGET` does
     *   the same but also calls the decorator's own `trustRestartBudget()`.
     * @param array<int, float>|null $warmStartVector Same length/order as the problem this algorithm will
     *   optimize (i.e. `ParameterVectorMapperInterface::mapConfigurationToVector()`'s own output). Null (the
     *   default) or a non-positive $warmStartFraction leaves the built algorithm entirely unconfigured for
     *   warm start, same as never calling `setWarmStart()` at all.
     * @param float $warmStartFraction Passed through to the built algorithm's own `setWarmStart()` --
     *   see that method's own docblock. Ignored when $warmStartVector is null.
     */
    public function create(
        string $algorithmName,
        int $populationSize,
        int $maxGenerations,
        string $terminationMode = SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_FIXED_BUDGET,
        ?array $warmStartVector = null,
        float $warmStartFraction = 0.0,
    ): OptimizerAlgorithmInterface;
}
