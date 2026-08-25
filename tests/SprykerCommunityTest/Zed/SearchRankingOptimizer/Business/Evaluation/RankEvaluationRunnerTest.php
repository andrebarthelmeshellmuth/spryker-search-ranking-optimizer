<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Evaluation;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationQueryScoreTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Generated\Shared\Transfer\SearchRankingHybridComparisonTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\QueryBucketClassifier;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunner;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RelevanceJudgmentGainMapperInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
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
 * @group Portable
 */
class RankEvaluationRunnerTest extends Unit
{
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
            ->with($this->callback(fn (SearchRankingEvaluationRequestTransfer $requestTransfer): bool => count($requestTransfer->getQueries()) === 2
                && $requestTransfer->getStoreNameOrFail() === 'DE'
                && $requestTransfer->getLocaleNameOrFail() === 'en_US'))
            ->willReturn(
                (new SearchRankingEvaluationResponseTransfer())
                    ->addQueryScore((new SearchRankingEvaluationQueryScoreTransfer())->setIdSearchRankingQuery(1)->setMetricScore(0.8))
                    ->addQueryScore((new SearchRankingEvaluationQueryScoreTransfer())->setIdSearchRankingQuery(2)->setMetricScore(0.2)),
            );

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())
            ->method('createEvaluation')
            ->with($this->callback(fn (SearchRankingEvaluationTransfer $evaluationTransfer): bool => $evaluationTransfer->getStoreNameOrFail() === 'DE'
                && $evaluationTransfer->getLocaleNameOrFail() === 'en_US'
                && $evaluationTransfer->getQueryCountOrFail() === 2
                && abs($evaluationTransfer->getMetricScoreOrFail() - 0.6) < 0.0001))
            ->willReturnArgument(0);

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingClientMock);

        // Act
        $result = $runner->evaluate('DE', 'en_US');

        // Assert
        $this->assertNotNull($result);
        $this->assertSame(2, $result->getQueryCount());
    }

    public function testEvaluateCandidateReturnsNullWhenNoQueriesExistForStoreLocale(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueriesByStoreLocale')->willReturn([]);
        $repositoryMock->method('findRatingsByStoreLocale')->willReturn([$this->createRatingTransfer(1, 100, 'heart')]);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('createEvaluation');

        $runner = $this->createRunner($repositoryMock, $entityManagerMock);

        // Act
        $result = $runner->evaluateCandidate('DE', 'en_US', new SearchRankingConfigurationStorageTransfer());

        // Assert
        $this->assertNull($result);
    }

    /**
     * The whole point of this method: it must NEVER call createEvaluation, no matter how many times it's
     * called or how successful the evaluation is -- an optimizer loop calling this hundreds of times per
     * run must not flood spy_search_ranking_evaluation with candidate-scoring noise.
     */
    public function testEvaluateCandidateNeverPersistsAnEvaluation(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueriesByStoreLocale')->willReturn([$this->createQueryTransfer(1, 'chair', 1.0)]);
        $repositoryMock->method('findRatingsByStoreLocale')->willReturn([$this->createRatingTransfer(1, 100, 'heart')]);

        $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
        $searchRankingClientMock->method('evaluateRankings')->willReturn(
            (new SearchRankingEvaluationResponseTransfer())
                ->addQueryScore((new SearchRankingEvaluationQueryScoreTransfer())->setIdSearchRankingQuery(1)->setMetricScore(0.42)),
        );

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('createEvaluation');

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingClientMock);

        // Act
        $result = $runner->evaluateCandidate('DE', 'en_US', new SearchRankingConfigurationStorageTransfer());

        // Assert
        $this->assertEqualsWithDelta(0.42, $result, 0.0001);
    }

    /**
     * The other half of the point: the candidate configuration must actually reach the fired query, not
     * be silently dropped -- otherwise every candidate an optimizer proposes would score identically,
     * exactly the original bug this method exists to avoid re-introducing.
     */
    public function testEvaluateCandidatePassesTheGivenConfigurationThroughToTheRequest(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueriesByStoreLocale')->willReturn([$this->createQueryTransfer(1, 'chair', 1.0)]);
        $repositoryMock->method('findRatingsByStoreLocale')->willReturn([$this->createRatingTransfer(1, 100, 'heart')]);

        $candidateConfigurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.42)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['top_seller' => 1.0]);

        $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
        $searchRankingClientMock->expects($this->once())
            ->method('evaluateRankings')
            ->with($this->callback(fn (SearchRankingEvaluationRequestTransfer $requestTransfer): bool => $requestTransfer->getRankingConfiguration() === $candidateConfigurationTransfer))
            ->willReturn(
                (new SearchRankingEvaluationResponseTransfer())
                    ->addQueryScore((new SearchRankingEvaluationQueryScoreTransfer())->setIdSearchRankingQuery(1)->setMetricScore(0.5)),
            );

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingClientMock);

        // Act
        $result = $runner->evaluateCandidate('DE', 'en_US', $candidateConfigurationTransfer);

        // Assert
        $this->assertNotNull($result);
    }

    public function testCompareLexicalVsHybridReturnsAnEmptyComparisonWhenNoQueriesExistForStoreLocale(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueriesByStoreLocale')->willReturn([]);
        $repositoryMock->method('findRatingsByStoreLocale')->willReturn([$this->createRatingTransfer(1, 100, 'heart')]);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $runner = $this->createRunner($repositoryMock, $entityManagerMock);

        // Act
        $result = $runner->compareLexicalVsHybrid('DE', 'en_US', 0.5);

        // Assert
        $this->assertInstanceOf(SearchRankingHybridComparisonTransfer::class, $result);
        $this->assertCount(0, iterator_to_array($result->getQueryComparisons()));
        $this->assertSame(0.0, $result->getLexicalWeightedAggregate());
        $this->assertSame(0.0, $result->getHybridWeightedAggregate());
    }

    /**
     * Proves the two defining behaviors of this method in one test: (1) "lexical" is ALWAYS forced to
     * alpha=1.0 regardless of what the live configuration's own alpha is (here deliberately set to 0.7, a
     * value that would leak through if the override were missing), and (2) "hybrid" gets the candidate
     * alpha instead — i.e. the two `evaluateRankings()` calls fire against genuinely different
     * configurations, not the same one twice.
     */
    public function testCompareLexicalVsHybridForcesLexicalConfigurationAlphaToOneRegardlessOfTheLiveConfiguration(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueriesByStoreLocale')->willReturn([$this->createQueryTransfer(1, 'chair', 1.0)]);
        $repositoryMock->method('findRatingsByStoreLocale')->willReturn([$this->createRatingTransfer(1, 100, 'heart')]);

        $liveConfigurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.5)
            ->setAlpha(0.7);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('getConfiguration')->willReturn($liveConfigurationTransfer);

        $seenAlphas = [];
        $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
        $searchRankingClientMock->expects($this->exactly(2))
            ->method('evaluateRankings')
            ->with($this->callback(function (SearchRankingEvaluationRequestTransfer $requestTransfer) use (&$seenAlphas): bool {
                $seenAlphas[] = $requestTransfer->getRankingConfigurationOrFail()->getAlpha();

                return true;
            }))
            ->willReturn(
                (new SearchRankingEvaluationResponseTransfer())
                    ->addQueryScore((new SearchRankingEvaluationQueryScoreTransfer())->setIdSearchRankingQuery(1)->setMetricScore(0.5)),
            );

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingClientMock, $searchRankingFacadeMock);

        // Act
        $runner->compareLexicalVsHybrid('DE', 'en_US', 0.3);

        // Assert
        $this->assertSame([1.0, 0.3], $seenAlphas, 'The lexical call must always use alpha=1.0 (never the live config\'s own 0.7), and the hybrid call must use the requested candidate alpha (0.3).');
    }

    /**
     * Proves the per-query join/bucket-tag/delta computation: two different scores for the SAME query id
     * from the two `evaluateRankings()` calls must produce exactly one `SearchRankingQueryComparisonTransfer`
     * with both scores, the correct bucket (via the real {@see QueryBucketClassifier}, not a mock — "office
     * chair" is a real bucket-3 entry), and `delta = hybridScore - lexicalScore`.
     */
    public function testCompareLexicalVsHybridJoinsPerQueryScoresAndComputesDelta(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findQueriesByStoreLocale')->willReturn([$this->createQueryTransfer(1, 'office chair', 1.0)]);
        $repositoryMock->method('findRatingsByStoreLocale')->willReturn([$this->createRatingTransfer(1, 100, 'heart')]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('getConfiguration')->willReturn(new SearchRankingConfigurationStorageTransfer());

        $lexicalResponseTransfer = (new SearchRankingEvaluationResponseTransfer())
            ->addQueryScore((new SearchRankingEvaluationQueryScoreTransfer())->setIdSearchRankingQuery(1)->setMetricScore(0.4));
        $hybridResponseTransfer = (new SearchRankingEvaluationResponseTransfer())
            ->addQueryScore((new SearchRankingEvaluationQueryScoreTransfer())->setIdSearchRankingQuery(1)->setMetricScore(0.7));

        $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
        $searchRankingClientMock->method('evaluateRankings')->willReturnOnConsecutiveCalls($lexicalResponseTransfer, $hybridResponseTransfer);

        $runner = $this->createRunner($repositoryMock, $this->createMock(SearchRankingOptimizerEntityManagerInterface::class), $searchRankingClientMock, $searchRankingFacadeMock);

        // Act
        $result = $runner->compareLexicalVsHybrid('DE', 'en_US', 0.5);

        // Assert
        $queryComparisonTransfers = iterator_to_array($result->getQueryComparisons());
        $this->assertCount(1, $queryComparisonTransfers);
        $queryComparisonTransfer = $queryComparisonTransfers[0];
        $this->assertSame(1, $queryComparisonTransfer->getIdSearchRankingQuery());
        $this->assertSame('office chair', $queryComparisonTransfer->getSearchTerm());
        $this->assertSame(QueryBucketClassifier::BUCKET_GENERIC_PRODUCT_TYPE, $queryComparisonTransfer->getBucket());
        $this->assertEqualsWithDelta(0.4, $queryComparisonTransfer->getLexicalScore(), 0.0001);
        $this->assertEqualsWithDelta(0.7, $queryComparisonTransfer->getHybridScore(), 0.0001);
        $this->assertEqualsWithDelta(0.3, $queryComparisonTransfer->getDelta(), 0.0001);
    }

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface|null $searchRankingClient
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface|null $searchRankingFacade
     */
    protected function createRunner(
        SearchRankingOptimizerRepositoryInterface $repository,
        SearchRankingOptimizerEntityManagerInterface $entityManager,
        ?SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient = null,
        ?SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade = null,
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
            $searchRankingFacade ?? $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class),
            new QueryBucketClassifier(),
        );
    }

    /**
     * @param int $idSearchRankingQuery
     * @param string $searchTerm
     * @param float $importanceWeight
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
