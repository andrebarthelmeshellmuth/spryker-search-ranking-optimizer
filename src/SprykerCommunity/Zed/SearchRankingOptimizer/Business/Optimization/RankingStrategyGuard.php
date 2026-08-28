<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\UnsupportedRankingStrategyException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;

class RankingStrategyGuard implements RankingStrategyGuardInterface
{
    /**
     * The identity of `search-ranking`'s built-in default ranking strategy — the adaptive
     * saturating-blend `function_score` whose parameter space IS this optimizer's parameter space. Mirror
     * of {@see \SprykerCommunity\Client\SearchRanking\Strategy\AdaptiveFormulaStrategy::NAME}, kept as a
     * literal here (not an imported Client-layer symbol) the same way the rest of this package already
     * refers to it; `search-ranking` documents it as a stable, must-not-change identifier.
     *
     * @var string
     */
    public const ADAPTIVE_FORMULA_STRATEGY_NAME = 'adaptive_formula';

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\ParameterVectorMapperRegistryInterface $parameterVectorMapperRegistry
     */
    public function __construct(
        protected SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient,
        protected ParameterVectorMapperRegistryInterface $parameterVectorMapperRegistry,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\UnsupportedRankingStrategyException
     */
    public function assertActiveStrategyIsTunable(): void
    {
        $unsupportedStrategyNames = [];

        foreach ($this->searchRankingClient->getRegisteredRankingStrategyNames() as $strategyName) {
            if ($strategyName === static::ADAPTIVE_FORMULA_STRATEGY_NAME) {
                continue;
            }

            if ($this->parameterVectorMapperRegistry->hasMapperFor($strategyName)) {
                continue;
            }

            $unsupportedStrategyNames[] = $strategyName;
        }

        if ($unsupportedStrategyNames === []) {
            return;
        }

        throw new UnsupportedRankingStrategyException(sprintf(
            'Refusing to optimize search-ranking formula parameters: the active ranking strategy '
                . 'configuration includes %s, which %s no parameter-space mapper in search-ranking-optimizer. '
                . 'Formula-parameter optimization would write configuration (relevanceWeight, metric weights, '
                . 'specificity knobs) that the active strategy never reads — a silent no-op reported as a real '
                . 'improvement. Register a ParameterVectorMapper for it, or revert search-ranking to the '
                . 'default "%s" strategy before running the optimizer.',
            '"' . implode('", "', $unsupportedStrategyNames) . '"',
            count($unsupportedStrategyNames) === 1 ? 'has' : 'have',
            static::ADAPTIVE_FORMULA_STRATEGY_NAME,
        ));
    }
}
