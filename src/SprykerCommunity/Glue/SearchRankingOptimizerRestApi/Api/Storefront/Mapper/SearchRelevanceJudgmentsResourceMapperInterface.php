<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Glue\SearchRankingOptimizerRestApi\Api\Storefront\Mapper;

use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;

interface SearchRelevanceJudgmentsResourceMapperInterface
{
    /**
     * Builds the resource-shaped array payload used to denormalize a
     * `SearchRelevanceJudgmentsStorefrontResource` from one `SearchRankingQueryRatingTransfer` — used
     * both for the POST response and for each item of the GetCollection response. `searchTerm` is not on
     * `SearchRankingQueryRatingTransfer` itself (only `fkSearchRankingQuery`); it is passed in separately
     * since the caller already has it (either from the request or from the batch read's own request
     * parameters).
     *
     * @return array<string, mixed>
     */
    public function mapRatingTransferToResourceData(SearchRankingQueryRatingTransfer $ratingTransfer, string $searchTerm): array;
}
