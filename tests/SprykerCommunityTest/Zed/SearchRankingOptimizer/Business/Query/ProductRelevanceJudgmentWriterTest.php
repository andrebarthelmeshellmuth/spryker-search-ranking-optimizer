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
use Propel\Runtime\Exception\PropelException;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\InvalidRatingTypeException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\ProductNotInSearchResultsException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentWriter;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
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

        $writer = $this->createWriter($canonicalizerMock, $repositoryMock, $entityManagerMock);

        // Act
        $writer->submitJudgment($requestTransfer);
    }

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

        $writer = $this->createWriter($canonicalizerMock, $repositoryMock, $entityManagerMock);

        // Act
        $writer->submitJudgment($requestTransfer);
    }

    /**
     * A time-of-check-to-time-of-use race: two raters submitting a judgment for the SAME never-before-rated
     * term at nearly the same time can both find null and both reach createQuery() -- the DB's own unique
     * (search_term, store_name, locale_name) constraint lets exactly one insert win, and the loser must
     * recover by re-fetching rather than losing that rater's judgment entirely.
     */
    public function testSubmitJudgmentRecoversWhenCreateQueryLosesARaceToAConcurrentInsert(): void
    {
        // Arrange
        $requestTransfer = $this->createRequestTransfer('desk', SearchRankingOptimizerConfig::RATING_TYPE_CHECK);

        $canonicalizerMock = $this->createMock(SearchTermCanonicalizerInterface::class);
        $canonicalizerMock->method('canonicalize')->willReturn('desk');

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueryByTermStoreLocale')->willReturnOnConsecutiveCalls(
            null,
            (new SearchRankingQueryTransfer())->setIdSearchRankingQuery(9),
        );

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())
            ->method('createQuery')
            ->willThrowException(new PropelException('Duplicate entry for key unique-spy_search_ranking_query-term_store_locale'));
        $entityManagerMock->expects($this->once())
            ->method('upsertRating')
            ->with($this->callback(fn (SearchRankingQueryRatingTransfer $ratingTransfer): bool => $ratingTransfer->getFkSearchRankingQuery() === 9))
            ->willReturnArgument(0);

        $writer = $this->createWriter($canonicalizerMock, $repositoryMock, $entityManagerMock);

        // Act
        $writer->submitJudgment($requestTransfer);
    }

    public function testSubmitJudgmentRethrowsWhenCreateQueryFailsForAReasonOtherThanARace(): void
    {
        // Arrange -- the re-fetch after the failure STILL comes back null, so this was never a race in the
        // first place (e.g. a genuine connection failure) and must propagate rather than being swallowed.
        $requestTransfer = $this->createRequestTransfer('desk', SearchRankingOptimizerConfig::RATING_TYPE_CHECK);

        $canonicalizerMock = $this->createMock(SearchTermCanonicalizerInterface::class);
        $canonicalizerMock->method('canonicalize')->willReturn('desk');

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueryByTermStoreLocale')->willReturn(null);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->method('createQuery')->willThrowException(new PropelException('Connection lost.'));
        $entityManagerMock->expects($this->never())->method('upsertRating');

        $writer = $this->createWriter($canonicalizerMock, $repositoryMock, $entityManagerMock);

        // Assert
        $this->expectException(PropelException::class);

        // Act
        $writer->submitJudgment($requestTransfer);
    }

    public function testSubmitJudgmentRejectsAnUnknownRatingTypeWithoutTouchingPersistence(): void
    {
        // Arrange
        $requestTransfer = $this->createRequestTransfer('chair', 'not-a-real-rating-type');

        $canonicalizerMock = $this->createMock(SearchTermCanonicalizerInterface::class);
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('createQuery');
        $entityManagerMock->expects($this->never())->method('upsertRating');

        $writer = $this->createWriter($canonicalizerMock, $repositoryMock, $entityManagerMock);

        // Assert
        $this->expectException(InvalidRatingTypeException::class);

        // Act
        $writer->submitJudgment($requestTransfer);
    }

    public function testSubmitJudgmentRejectsAProductThatIsNotAmongTheRealCurrentSearchResultsWithoutTouchingPersistence(): void
    {
        // Arrange
        $requestTransfer = $this->createRequestTransfer('chair', SearchRankingOptimizerConfig::RATING_TYPE_HEART);

        $canonicalizerMock = $this->createMock(SearchTermCanonicalizerInterface::class);
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->expects($this->never())->method('findQueryByTermStoreLocale');

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('createQuery');
        $entityManagerMock->expects($this->never())->method('upsertRating');

        $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
        $searchRankingClientMock->expects($this->once())
            ->method('productMatchesSearch')
            ->with('chair', 'DE', 'en_US', 123)
            ->willReturn(false);

        $writer = $this->createWriter($canonicalizerMock, $repositoryMock, $entityManagerMock, $searchRankingClientMock);

        // Assert
        $this->expectException(ProductNotInSearchResultsException::class);

        // Act
        $writer->submitJudgment($requestTransfer);
    }

    public function testClearJudgmentDeletesTheRatingWhenTheQueryExists(): void
    {
        // Arrange
        $requestTransfer = $this->createRequestTransfer('desk', SearchRankingOptimizerConfig::RATING_TYPE_HEART);

        $canonicalizerMock = $this->createMock(SearchTermCanonicalizerInterface::class);
        $canonicalizerMock->method('canonicalize')->willReturn('desk');

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueryByTermStoreLocale')
            ->with('desk', 'DE', 'en_US')
            ->willReturn((new SearchRankingQueryTransfer())->setIdSearchRankingQuery(9));

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())
            ->method('deleteRating')
            ->with(9, 'CUST-1', 123);

        $writer = $this->createWriter($canonicalizerMock, $repositoryMock, $entityManagerMock);

        // Act
        $writer->clearJudgment($requestTransfer);
    }

    public function testClearJudgmentIsASafeNoOpWhenNoQueryExistsForThatTerm(): void
    {
        // Arrange
        $requestTransfer = $this->createRequestTransfer('never rated', SearchRankingOptimizerConfig::RATING_TYPE_HEART);

        $canonicalizerMock = $this->createMock(SearchTermCanonicalizerInterface::class);
        $canonicalizerMock->method('canonicalize')->willReturn('never rated');

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueryByTermStoreLocale')->willReturn(null);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('deleteRating');

        $writer = $this->createWriter($canonicalizerMock, $repositoryMock, $entityManagerMock);

        // Act
        $writer->clearJudgment($requestTransfer);
    }

    /**
     * @param string $searchTerm
     * @param string $ratingType
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

    /**
     * Defaults `$searchRankingClientMock` to one where `productMatchesSearch()` always returns true --
     * only the one test that specifically exercises the rejection path needs to override this.
     *
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizerInterface $canonicalizerMock
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repositoryMock
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManagerMock
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface|null $searchRankingClientMock
     */
    protected function createWriter(
        SearchTermCanonicalizerInterface $canonicalizerMock,
        SearchRankingOptimizerRepositoryInterface $repositoryMock,
        SearchRankingOptimizerEntityManagerInterface $entityManagerMock,
        ?SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClientMock = null,
    ): ProductRelevanceJudgmentWriter {
        if ($searchRankingClientMock === null) {
            $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
            $searchRankingClientMock->method('productMatchesSearch')->willReturn(true);
        }

        return new ProductRelevanceJudgmentWriter($canonicalizerMock, $repositoryMock, $entityManagerMock, $searchRankingClientMock);
    }
}
