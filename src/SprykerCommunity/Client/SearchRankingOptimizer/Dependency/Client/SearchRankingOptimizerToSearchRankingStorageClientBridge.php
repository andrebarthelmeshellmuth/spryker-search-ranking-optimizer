<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client;

use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;

class SearchRankingOptimizerToSearchRankingStorageClientBridge implements SearchRankingOptimizerToSearchRankingStorageClientInterface
{
    /**
     * @var \SprykerCommunity\Client\SearchRankingStorage\SearchRankingStorageClientInterface
     */
    protected $searchRankingStorageClient;

    /**
     * @param \SprykerCommunity\Client\SearchRankingStorage\SearchRankingStorageClientInterface $searchRankingStorageClient
     */
    public function __construct($searchRankingStorageClient)
    {
        $this->searchRankingStorageClient = $searchRankingStorageClient;
    }

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null
     */
    public function findRankingConfiguration(string $storeName, string $localeName): ?SearchRankingConfigurationStorageTransfer
    {
        return $this->searchRankingStorageClient->findRankingConfiguration($storeName, $localeName);
    }
}
