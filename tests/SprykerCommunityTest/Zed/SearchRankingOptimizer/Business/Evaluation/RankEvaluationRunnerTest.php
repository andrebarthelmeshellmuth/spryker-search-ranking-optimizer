<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Evaluation;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingEvaluationQueryScoreTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunner;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RelevanceJudgmentGainMapperInterface;
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
 * @group Evaluation
 * @group RankEvaluationRunnerTest
 * Add your own group annotations below this line
 */
class RankEvaluationRunnerTest extends Unit
{
    /**
     * @return void
     */
    public function testEvaluateReturnsNullWhenNoQueriesExistForStoreLocale(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueriesByStoreLocale')->willReturn([]);
        $repositoryMock->method('findRatingsByStoreLocale')->willReturn([$this->createRatingTransfer(1, 100, 'heart')]);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('createEvaluation');

        $runner = $this->createRunner($repositoryMock, $entityManagerMock);

        // Act
        $result = $runner->evaluate('DE', 'en_US');

        // Assert
        $this->assertNull($result);
    }

    /**
     * @return void
     */
    public function testEvaluateReturnsNullWhenNoRatingsExistForStoreLocale(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueriesByStoreLocale')->willReturn([$this->createQueryTransfer(1, 'chair', 1.0)]);
        $repositoryMock->method('findRatingsByStoreLocale')->willReturn([]);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('createEvaluation');

        $runner = $this->createRunner($repositoryMock, $entityManagerMock);

        // Act
        $result = $runner->evaluate('DE', 'en_US');

        // Assert
        $this->assertNull($result);
    }

    /**
     * @return void
     */
    public function testEvaluateReturnsNullWhenTheBridgeReturnsNoScores(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueriesByStoreLocale')->willReturn([$this->createQueryTransfer(1, 'chair', 1.0)]);
        $repositoryMock->method('findRatingsByStoreLocale')->willReturn([$this->createRatingTransfer(1, 100, 'heart')]);

        $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
        $searchRankingClientMock->method('evaluateRankings')->willReturn(new SearchRankingEvaluationResponseTransfer());

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('createEvaluation');

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingClientMock);

        // Act
        $result = $runner->evaluate('DE', 'en_US');

        // Assert
        $this->assertNull($result);
    }

    /**
     * @return void
     */
    public function testEvaluatePersistsAQueryImportanceWeightedAggregateAcrossMultipleQueries(): void
    {
        // Arrange — query 1 (weight 2) scores 0.8, query 2 (weight 1) scores 0.2:
        // weighted = (2*0.8 + 1*0.2) / (2+1) = 1.8/3 = 0.6
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueriesByStoreLocale')->willReturn([
            $this->createQueryTransfer(1, 'chair', 2.0),
            $this->createQueryTransfer(2, 'desk', 1.0),
        ]);
        $repositoryMock->method('findRatingsByStoreLocale')->willReturn([
            $this->createRatingTransfer(1, 100, 'heart'),
            $this->createRatingTransfer(2, 200, 'check'),
        ]);

        $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
        $searchRankingClientMock->expects($this->once())
            ->method('evaluateRankings')
            ->with($this->callback(function (SearchRankingEvaluationRequestTransfer $requestTransfer): bool {
                return count($requestTransfer->getQueries()) === 2
                    && $requestTransfer->getStoreNameOrFail() === 'DE'
                    && $requestTransfer->getLocaleNameOrFail() === 'en_US';
            }))
            ->willReturn(
                (new SearchRankingEvaluationResponseTransfer())
                    ->addQueryScore((new SearchRankingEvaluationQueryScoreTransfer())->setIdSearchRankingQuery(1)->setMetricScore(0.8))
                    ->addQueryScore((new SearchRankingEvaluationQueryScoreTransfer())->setIdSearchRankingQuery(2)->setMetricScore(0.2)),
            );

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())
            ->method('createEvaluation')
            ->with($this->callback(function (SearchRankingEvaluationTransfer $evaluationTransfer): bool {
                return $evaluationTransfer->getStoreNameOrFail() === 'DE'
                    && $evaluationTransfer->getLocaleNameOrFail() === 'en_US'
                    && $evaluationTransfer->getQueryCountOrFail() === 2
                    && abs($evaluationTransfer->getMetricScoreOrFail() - 0.6) < 0.0001;
            }))
            ->willReturnArgument(0);

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingClientMock);

        // Act
        $result = $runner->evaluate('DE', 'en_US');

        // Assert
        $this->assertNotNull($result);
        $this->assertSame(2, $result->getQueryCount());
    }

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface|null $searchRankingClient
     *
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunner
     */
    protected function createRunner(
        SearchRankingOptimizerRepositoryInterface $repository,
        SearchRankingOptimizerEntityManagerInterface $entityManager,
        ?SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient = null,
    ): RankEvaluationRunner {
        $gainMapperMock = $this->createMock(RelevanceJudgmentGainMapperInterface::class);
        $gainMapperMock->method('mapRatingType')->willReturnMap([
            ['heart', 3.0],
            ['check', 1.0],
            ['x', 0.0],
        ]);

        return new RankEvaluationRunner(
            $repository,
            $entityManager,
            $searchRankingClient ?? $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class),
            $gainMapperMock,
        );
    }

    /**
     * @param int $idSearchRankingQuery
     * @param string $searchTerm
     * @param float $importanceWeight
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryTransfer
     */
    protected function createQueryTransfer(int $idSearchRankingQuery, string $searchTerm, float $importanceWeight): SearchRankingQueryTransfer
    {
        return (new SearchRankingQueryTransfer())
            ->setIdSearchRankingQuery($idSearchRankingQuery)
            ->setSearchTerm($searchTerm)
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setImportanceWeight($importanceWeight);
    }

    /**
     * @param int $fkSearchRankingQuery
     * @param int $fkProductAbstract
     * @param string $ratingType
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryRatingTransfer
     */
    protected function createRatingTransfer(int $fkSearchRankingQuery, int $fkProductAbstract, string $ratingType): SearchRankingQueryRatingTransfer
    {
        return (new SearchRankingQueryRatingTransfer())
            ->setFkSearchRankingQuery($fkSearchRankingQuery)
            ->setCustomerReference('CUST-1')
            ->setFkProductAbstract($fkProductAbstract)
            ->setRatingType($ratingType);
    }
}
