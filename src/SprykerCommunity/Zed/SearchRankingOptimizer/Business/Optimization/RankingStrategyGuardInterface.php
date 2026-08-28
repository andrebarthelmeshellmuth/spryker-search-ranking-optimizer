<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

interface RankingStrategyGuardInterface
{
    /**
     * Specification:
     * - Asserts that every ranking strategy registered on `search-ranking` for this project is one this
     *   package can actually tune — i.e. it is `adaptive_formula` (the built-in default, whose parameter
     *   space IS this optimizer's parameter space) or a strategy with its own registered
     *   {@see ParameterVectorMapperInterface} in {@see ParameterVectorMapperRegistryInterface}.
     * - Must be called at the top of every path that writes ranking-formula parameters to live
     *   `search-ranking` configuration (the auto-tune cron, applying an optimization run, launching an
     *   optimization run). Read-only evaluation/scoring/preview paths must NOT call it.
     * - A no-op when `adaptive_formula` is the only registered strategy — the current demoshop reality.
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\UnsupportedRankingStrategyException
     *   naming every offending strategy, when at least one registered strategy has no mapper.
     */
    public function assertActiveStrategyIsTunable(): void;
}
