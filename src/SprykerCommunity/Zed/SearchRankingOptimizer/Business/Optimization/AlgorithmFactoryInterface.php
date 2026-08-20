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
     * @param bool $isTerminationCriteriaTrusted Calls the built algorithm's own `trustTerminationCriteria()`
     *   when true, governing the run by its own convergence/divergence/plateau detection instead of
     *   $maxGenerations.
     * @param array<int, float>|null $warmStartVector Same length/order as the problem this algorithm will
     *   optimize (i.e. `ParameterVectorMapperInterface::mapConfigurationToVector()`'s own output). Null (the
     *   default) or a non-positive $warmStartFraction leaves the built algorithm entirely unconfigured for
     *   warm start, same as never calling `setWarmStart()` at all.
     * @param float $warmStartFraction Passed through to the built algorithm's own `setWarmStart()` --
     *   see that method's own docblock. Ignored when $warmStartVector is null.
     * @param bool $isRestartOnPlateauEnabled Wraps the built algorithm in `blackbox-optimizer`'s own
     *   `RestartingOptimizerDecorator` when true: on a genuine fitness plateau, restarts from a fresh random
     *   point with a doubled population, within the SAME total evaluation budget
     *   (`populationSize * maxGenerations`) a non-restarting run already uses. Mutually exclusive with
     *   $isTerminationCriteriaTrusted -- that decorator does not support trusting an inner algorithm's own
     *   safety ceiling, since it would blow through the decorator's own budget accounting.
     *
     * @throws \InvalidArgumentException When both $isTerminationCriteriaTrusted and
     *   $isRestartOnPlateauEnabled are true.
     */
    public function create(
        string $algorithmName,
        int $populationSize,
        int $maxGenerations,
        bool $isTerminationCriteriaTrusted = false,
        ?array $warmStartVector = null,
        float $warmStartFraction = 0.0,
        bool $isRestartOnPlateauEnabled = false,
    ): OptimizerAlgorithmInterface;
}
