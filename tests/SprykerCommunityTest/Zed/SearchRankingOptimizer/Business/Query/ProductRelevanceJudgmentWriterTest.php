<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Query;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\InvalidRatingTypeException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentWriter;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Query
 * @group ProductRelevanceJudgmentWriterTest
 * Add your own group annotations below this line
 */
class ProductRelevanceJudgmentWriterTest extends Unit
{
    /**
     * @return void
     */
    public function testSubmitJudgmentCanonicalizesTheSearchTermBeforeLookingUpTheQuery(): void
    {
        // Arrange
        $requestTransfer = $this->createRequestTransfer('  Office   Chair  ', SearchRankingOptimizerConfig::RATING_TYPE_HEART);

        $canonicalizerMock = $this->createMock(SearchTermCanonicalizerInterface::class);
        $canonicalizerMock->expects($this->once())->method('canonicalize')->with('  Office   Chair  ')->willReturn('office chair');

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->expects($this->once())
            ->method('findQueryByTermStoreLocale')
            ->with('office chair', 'DE', 'en_US')
            ->willReturn((new SearchRankingQueryTransfer())->setIdSearchRankingQuery(7));

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('createQuery');
        $entityManagerMock->method('upsertRating')->willReturnArgument(0);

        $writer = new ProductRelevanceJudgmentWriter($canonicalizerMock, $repositoryMock, $entityManagerMock);

        // Act
        $writer->submitJudgment($requestTransfer);
    }

    /**
     * @return void
     */
    public function testSubmitJudgmentCreatesTheQueryWhenNoneExistsYetForThatCanonicalTermStoreLocale(): void
    {
        // Arrange
        $requestTransfer = $this->createRequestTransfer('desk', SearchRankingOptimizerConfig::RATING_TYPE_CHECK);

        $canonicalizerMock = $this->createMock(SearchTermCanonicalizerInterface::class);
        $canonicalizerMock->method('canonicalize')->willReturn('desk');

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueryByTermStoreLocale')->willReturn(null);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())
            ->method('createQuery')
            ->with($this->callback(fn (SearchRankingQueryTransfer $queryTransfer): bool => $queryTransfer->getSearchTerm() === 'desk'
                && $queryTransfer->getStoreName() === 'DE'
                && $queryTransfer->getLocaleName() === 'en_US'))
            ->willReturn((new SearchRankingQueryTransfer())->setIdSearchRankingQuery(9));
        $entityManagerMock->expects($this->once())
            ->method('upsertRating')
            ->with($this->callback(fn (SearchRankingQueryRatingTransfer $ratingTransfer): bool => $ratingTransfer->getFkSearchRankingQuery() === 9))
            ->willReturnArgument(0);

        $writer = new ProductRelevanceJudgmentWriter($canonicalizerMock, $repositoryMock, $entityManagerMock);

        // Act
        $writer->submitJudgment($requestTransfer);
    }

    /**
     * @return void
     */
    public function testSubmitJudgmentRejectsAnUnknownRatingTypeWithoutTouchingPersistence(): void
    {
        // Arrange
        $requestTransfer = $this->createRequestTransfer('chair', 'not-a-real-rating-type');

        $canonicalizerMock = $this->createMock(SearchTermCanonicalizerInterface::class);
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('createQuery');
        $entityManagerMock->expects($this->never())->method('upsertRating');

        $writer = new ProductRelevanceJudgmentWriter($canonicalizerMock, $repositoryMock, $entityManagerMock);

        // Assert
        $this->expectException(InvalidRatingTypeException::class);

        // Act
        $writer->submitJudgment($requestTransfer);
    }

    /**
     * @param string $searchTerm
     * @param string $ratingType
     *
     * @return \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer
     */
    protected function createRequestTransfer(string $searchTerm, string $ratingType): SearchRankingProductRelevanceJudgmentRequestTransfer
    {
        return (new SearchRankingProductRelevanceJudgmentRequestTransfer())
            ->setSearchTerm($searchTerm)
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setIdProductAbstract(123)
            ->setRatingType($ratingType)
            ->setCustomerReference('CUST-1');
    }
}
