<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Glue\SearchRankingOptimizerRestApi\Api\Storefront\Mapper;

use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;

class SearchRelevanceJudgmentsResourceMapper implements SearchRelevanceJudgmentsResourceMapperInterface
{
    /**
     * {@inheritDoc}
     *
     * @return array<string, mixed>
     */
    public function mapRatingTransferToResourceData(SearchRankingQueryRatingTransfer $ratingTransfer, string $searchTerm): array
    {
        return [
            'id' => (string)$ratingTransfer->getIdSearchRankingQueryRating(),
            'searchTerm' => $searchTerm,
            'idProductAbstract' => $ratingTransfer->getFkProductAbstract(),
            'ratingType' => $ratingTransfer->getRatingType(),
            'createdAt' => $ratingTransfer->getCreatedAt(),
        ];
    }
}
