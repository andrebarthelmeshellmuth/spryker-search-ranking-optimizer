<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client;

class SearchRankingOptimizerToSearchRankingClientBridge implements SearchRankingOptimizerToSearchRankingClientInterface
{
    /**
     * @var \SprykerCommunity\Client\SearchRankingOptimizer\SearchRankingOptimizerClientInterface
     */
    protected $searchRankingOptimizerClient;

    /**
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\SearchRankingOptimizerClientInterface $searchRankingOptimizerClient
     */
    public function __construct($searchRankingOptimizerClient)
    {
        $this->searchRankingOptimizerClient = $searchRankingOptimizerClient;
    }

    /**
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $limit
     *
     * @return array<float>
     */
    public function getCalibrationScores(string $searchTerm, string $storeName, string $localeName, int $limit): array
    {
        return $this->searchRankingOptimizerClient->getCalibrationScores($searchTerm, $storeName, $localeName, $limit);
    }
}
