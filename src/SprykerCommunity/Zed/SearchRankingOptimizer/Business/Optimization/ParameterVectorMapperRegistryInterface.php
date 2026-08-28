<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

/**
 * The set of `search-ranking` ranking strategies this package knows how to translate a parameter vector
 * for, keyed by the strategy's own stable
 * {@see \SprykerCommunity\Client\SearchRanking\Strategy\RankingStrategyInterface::getName()} identity.
 *
 * Today it holds exactly one entry, `adaptive_formula` — the adaptive saturating-blend `function_score`
 * that was the single hardcoded ranking pipeline before the "Search Relevance v2" strategy seam. The
 * registry is the seam a future non-formula strategy's own mapper plugs into, and — more immediately —
 * the lookup {@see RankingStrategyGuardInterface} consults to decide whether an optimization/auto-tune
 * write can safely proceed.
 *
 * The entry's {@see ParameterVectorMapperInterface} is the identity/capability record for that strategy;
 * the concrete, metric-set-scoped instance the optimizer actually searches with is still built per run in
 * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationRunner}.
 */
interface ParameterVectorMapperRegistryInterface
{
    /**
     * @param string $strategyName
     */
    public function hasMapperFor(string $strategyName): bool;

    /**
     * @param string $strategyName
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\UnsupportedRankingStrategyException
     *   when no mapper is registered for $strategyName.
     */
    public function getMapperFor(string $strategyName): ParameterVectorMapperInterface;

    /**
     * @return array<int, string>
     */
    public function getRegisteredStrategyNames(): array;
}
