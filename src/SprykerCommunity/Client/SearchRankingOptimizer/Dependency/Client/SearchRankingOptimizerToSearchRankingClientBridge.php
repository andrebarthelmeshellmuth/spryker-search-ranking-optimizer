<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client;

use SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface;
use SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculatorInterface;

class SearchRankingOptimizerToSearchRankingClientBridge implements SearchRankingOptimizerToSearchRankingClientInterface
{
    /**
     * @var \SprykerCommunity\Client\SearchRanking\SearchRankingClientInterface
     */
    protected $searchRankingClient;

    /**
     * @param \SprykerCommunity\Client\SearchRanking\SearchRankingClientInterface $searchRankingClient
     */
    public function __construct($searchRankingClient)
    {
        $this->searchRankingClient = $searchRankingClient;
    }

    public function isSpecificityWeightingEnabled(): bool
    {
        return $this->searchRankingClient->isSpecificityWeightingEnabled();
    }

    /**
     * @return array<string, string>
     */
    public function getSpecificityProbeFieldSearchAnalyzers(): array
    {
        return $this->searchRankingClient->getSpecificityProbeFieldSearchAnalyzers();
    }

    /**
     * @return array<int, string>
     */
    public function getRegisteredRankingStrategyNames(): array
    {
        return $this->searchRankingClient->getRegisteredRankingStrategyNames();
    }

    public function createFunctionScoreBuilder(): FunctionScoreBuilderInterface
    {
        return $this->searchRankingClient->createFunctionScoreBuilder();
    }

    public function createQuerySpecificityCalculator(): QuerySpecificityCalculatorInterface
    {
        return $this->searchRankingClient->createQuerySpecificityCalculator();
    }
}
