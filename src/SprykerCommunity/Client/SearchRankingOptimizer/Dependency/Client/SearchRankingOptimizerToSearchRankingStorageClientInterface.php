<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client;

use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;

interface SearchRankingOptimizerToSearchRankingStorageClientInterface
{
    /**
     * @return \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null
     */
    public function findRankingConfiguration(): ?SearchRankingConfigurationStorageTransfer;
}
