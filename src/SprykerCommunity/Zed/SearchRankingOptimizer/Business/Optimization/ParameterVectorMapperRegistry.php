<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\UnsupportedRankingStrategyException;

class ParameterVectorMapperRegistry implements ParameterVectorMapperRegistryInterface
{
    /**
     * @var array<string, \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\ParameterVectorMapperInterface>
     */
    protected array $mappersByStrategyName;

    /**
     * @param array<string, \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\ParameterVectorMapperInterface> $mappersByStrategyName
     *   Keyed by {@see \SprykerCommunity\Client\SearchRanking\Strategy\RankingStrategyInterface::getName()}.
     *   Seeded by {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerBusinessFactory}
     *   with the built-in `adaptive_formula` mapper; a project adds a further entry only when it also
     *   registered a matching ranking-strategy plugin on `search-ranking`.
     */
    public function __construct(array $mappersByStrategyName)
    {
        $this->mappersByStrategyName = $mappersByStrategyName;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $strategyName
     */
    public function hasMapperFor(string $strategyName): bool
    {
        return isset($this->mappersByStrategyName[$strategyName]);
    }

    /**
     * {@inheritDoc}
     *
     * @param string $strategyName
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\UnsupportedRankingStrategyException
     */
    public function getMapperFor(string $strategyName): ParameterVectorMapperInterface
    {
        if (!isset($this->mappersByStrategyName[$strategyName])) {
            $registeredStrategyNames = $this->getRegisteredStrategyNames();

            throw new UnsupportedRankingStrategyException(sprintf(
                'search-ranking-optimizer has no parameter-vector mapper for ranking strategy "%s" (known: %s).',
                $strategyName,
                $registeredStrategyNames === [] ? '(none)' : '"' . implode('", "', $registeredStrategyNames) . '"',
            ));
        }

        return $this->mappersByStrategyName[$strategyName];
    }

    /**
     * {@inheritDoc}
     *
     * @return array<int, string>
     */
    public function getRegisteredStrategyNames(): array
    {
        return array_keys($this->mappersByStrategyName);
    }
}
