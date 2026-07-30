<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client;

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

    /**
     * @return bool
     */
    public function isEntropyWeightingEnabled(): bool
    {
        return $this->searchRankingClient->isEntropyWeightingEnabled();
    }
}
