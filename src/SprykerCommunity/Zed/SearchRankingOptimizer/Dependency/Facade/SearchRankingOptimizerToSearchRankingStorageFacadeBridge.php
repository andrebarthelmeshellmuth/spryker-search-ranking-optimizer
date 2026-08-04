<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade;

class SearchRankingOptimizerToSearchRankingStorageFacadeBridge implements SearchRankingOptimizerToSearchRankingStorageFacadeInterface
{
    /**
     * @var \SprykerCommunity\Zed\SearchRankingStorage\Business\SearchRankingStorageFacadeInterface
     */
    protected $searchRankingStorageFacade;

    /**
     * @param \SprykerCommunity\Zed\SearchRankingStorage\Business\SearchRankingStorageFacadeInterface $searchRankingStorageFacade
     */
    public function __construct($searchRankingStorageFacade)
    {
        $this->searchRankingStorageFacade = $searchRankingStorageFacade;
    }

    public function publishRankingConfiguration(): void
    {
        $this->searchRankingStorageFacade->publishRankingConfiguration();
    }
}
