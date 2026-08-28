<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;

interface SpecificityWeightingApplierInterface
{
    /**
     * Returns a clone of $configurationTransfer with `relevanceWeight` replaced by the specificity-adjusted
     * value for THIS search term — a no-op (the same instance, unchanged) when there's no configuration at
     * all, specificity weighting is disabled (the same project-level gate
     * {@see \SprykerCommunity\Client\SearchRanking\Plugin\Catalog\SearchRankingFunctionScoreQueryExpanderPlugin}
     * itself checks before ever firing the live probe — evaluation must never apply an effect live traffic
     * never applies, regardless of what a candidate configuration's own specificity fields say), or no
     * query term carries any real corpus evidence at all.
     *
     * @param string $indexName
     * @param string $searchTerm
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     */
    public function apply(
        string $indexName,
        string $searchTerm,
        ?SearchRankingConfigurationStorageTransfer $configurationTransfer,
    ): ?SearchRankingConfigurationStorageTransfer;
}
