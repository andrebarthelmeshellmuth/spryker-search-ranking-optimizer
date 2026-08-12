<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Query;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentReader;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Query
 * @group ProductRelevanceJudgmentReaderTest
 * Add your own group annotations below this line
 * @group Portable
 */
class ProductRelevanceJudgmentReaderTest extends Unit
{
    public function testGetJudgmentsCanonicalizesTheSearchTermBeforeLookingUpTheQuery(): void
    {
        // Arrange
        $requestTransfer = $this->createRequestTransfer('  Office   Chair  ', [1, 2]);

        $canonicalizerMock = $this->createMock(SearchTermCanonicalizerInterface::class);
        $canonicalizerMock->expects($this->once())->method('canonicalize')->with('  Office   Chair  ')->willReturn('office chair');

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->expects($this->once())
            ->method('findQueryByTermStoreLocale')
            ->with('office chair', 'DE', 'en_US')
            ->willReturn((new SearchRankingQueryTransfer())->setIdSearchRankingQuery(7));
        $repositoryMock->method('findRatingsByQueryCustomerAndProducts')->willReturn([]);

        $reader = $this->createReader($canonicalizerMock, $repositoryMock);

        // Act
        $reader->getJudgments($requestTransfer);
    }

    public function testGetJudgmentsReturnsSuccessfulEmptyResultWhenNoQueryExistsYet(): void
    {
        // Arrange
        $requestTransfer = $this->createRequestTransfer('office chair', [1]);

        $canonicalizerMock = $this->createMock(SearchTermCanonicalizerInterface::class);
        $canonicalizerMock->method('canonicalize')->willReturn('office chair');

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueryByTermStoreLocale')->willReturn(null);
        $repositoryMock->expects($this->never())->method('findRatingsByQueryCustomerAndProducts');

        $reader = $this->createReader($canonicalizerMock, $repositoryMock);

        // Act
        $responseTransfer = $reader->getJudgments($requestTransfer);

        // Assert
        $this->assertTrue($responseTransfer->getIsSuccess());
        $this->assertCount(0, $responseTransfer->getRatings());
    }

    public function testGetJudgmentsReturnsSuccessfulEmptyResultWithoutTouchingPersistenceWhenNoProductIdsAreRequested(): void
    {
        // Arrange
        $requestTransfer = $this->createRequestTransfer('office chair', []);

        $canonicalizerMock = $this->createMock(SearchTermCanonicalizerInterface::class);
        $canonicalizerMock->expects($this->never())->method('canonicalize');

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->expects($this->never())->method('findQueryByTermStoreLocale');
        $repositoryMock->expects($this->never())->method('findRatingsByQueryCustomerAndProducts');

        $reader = $this->createReader($canonicalizerMock, $repositoryMock);

        // Act
        $responseTransfer = $reader->getJudgments($requestTransfer);

        // Assert
        $this->assertTrue($responseTransfer->getIsSuccess());
        $this->assertCount(0, $responseTransfer->getRatings());
    }

    public function testGetJudgmentsReturnsTheCustomersOwnRatingsForTheRequestedProducts(): void
    {
        // Arrange
        $requestTransfer = $this->createRequestTransfer('office chair', [1, 2]);

        $canonicalizerMock = $this->createMock(SearchTermCanonicalizerInterface::class);
        $canonicalizerMock->method('canonicalize')->willReturn('office chair');

        $ratingTransfer = (new SearchRankingQueryRatingTransfer())
            ->setFkProductAbstract(1)
            ->setRatingType('heart');

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueryByTermStoreLocale')->willReturn((new SearchRankingQueryTransfer())->setIdSearchRankingQuery(7));
        $repositoryMock->expects($this->once())
            ->method('findRatingsByQueryCustomerAndProducts')
            ->with(7, 'customer-1', [1, 2])
            ->willReturn([$ratingTransfer]);

        $reader = $this->createReader($canonicalizerMock, $repositoryMock);

        // Act
        $responseTransfer = $reader->getJudgments($requestTransfer);

        // Assert
        $this->assertTrue($responseTransfer->getIsSuccess());
        $this->assertSame([$ratingTransfer], iterator_to_array($responseTransfer->getRatings()));
    }

    /**
     * @param string $searchTerm
     * @param array<int> $idProductAbstracts
     */
    protected function createRequestTransfer(string $searchTerm, array $idProductAbstracts): SearchRankingProductRelevanceJudgmentBatchRequestTransfer
    {
        return (new SearchRankingProductRelevanceJudgmentBatchRequestTransfer())
            ->setSearchTerm($searchTerm)
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setIdProductAbstracts($idProductAbstracts)
            ->setCustomerReference('customer-1');
    }

    protected function createReader(
        SearchTermCanonicalizerInterface $canonicalizer,
        SearchRankingOptimizerRepositoryInterface $repository,
    ): ProductRelevanceJudgmentReader {
        return new ProductRelevanceJudgmentReader($canonicalizer, $repository);
    }
}
