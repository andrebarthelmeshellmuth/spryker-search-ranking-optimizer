<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Glue\SearchRankingOptimizerRestApi\Api\Storefront\Mapper;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use SprykerCommunity\Glue\SearchRankingOptimizerRestApi\Api\Storefront\Mapper\SearchRelevanceJudgmentsResourceMapper;

/**
 * @group SprykerCommunityTest
 * @group Glue
 * @group SearchRankingOptimizerRestApi
 * @group SearchRelevanceJudgmentsResourceMapperTest
 */
class SearchRelevanceJudgmentsResourceMapperTest extends Unit
{
    public function testMapRatingTransferToResourceDataUsesTheGivenSearchTermNotTheRatingTransfer(): void
    {
        // Arrange
        $ratingTransfer = (new SearchRankingQueryRatingTransfer())
            ->setIdSearchRankingQueryRating(17)
            ->setFkProductAbstract(123)
            ->setRatingType('heart')
            ->setCreatedAt('2026-08-24T10:00:00+00:00');

        $mapper = new SearchRelevanceJudgmentsResourceMapper();

        // Act
        $resourceData = $mapper->mapRatingTransferToResourceData($ratingTransfer, 'garden chair');

        // Assert
        $this->assertSame([
            'id' => '17',
            'searchTerm' => 'garden chair',
            'idProductAbstract' => 123,
            'ratingType' => 'heart',
            'createdAt' => '2026-08-24T10:00:00+00:00',
        ], $resourceData);
    }
}
