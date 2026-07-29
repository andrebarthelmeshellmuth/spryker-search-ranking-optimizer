<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Optimization;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use RuntimeException;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunnerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationRunner;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Optimization
 * @group OptimizationRunnerTest
 */
class OptimizationRunnerTest extends Unit
{
    /**
     * @return void
     */
    public function testRunNextReturnsNullWhenNothingIsQueued(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn(null);

        $runner = $this->createRunner($repositoryMock);

        // Act
        $result = $runner->runNext();

        // Assert
        $this->assertNull($result);
    }

    /**
     * @return void
     */
    public function testRunNextFailsTheRunWhenNoActiveMetricsExist(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn($this->createQueuedRunTransfer());
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('getActiveMetrics')->willReturn([]);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())
            ->method('failOptimizerRun')
            ->with(1, $this->stringContains('active metric'));
        $entityManagerMock->expects($this->never())->method('startOptimizerRun');

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingFacadeMock);

        // Act
        $runner->runNext();
    }

    /**
     * @return void
     */
    public function testRunNextFailsTheRunWhenNoBaselineScoreCanBeComputed(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn($this->createQueuedRunTransfer());
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createBasicSearchRankingFacadeMock();

        $rankEvaluationRunnerMock = $this->createMock(RankEvaluationRunnerInterface::class);
        $rankEvaluationRunnerMock->method('evaluateCandidate')->willReturn(null);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())
            ->method('failOptimizerRun')
            ->with(1, $this->stringContains('rated'));
        $entityManagerMock->expects($this->never())->method('startOptimizerRun');

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingFacadeMock, $rankEvaluationRunnerMock);

        // Act
        $runner->runNext();
    }

    /**
     * @return void
     */
    public function testRunNextFailsTheRunOnAnUnexpectedException(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn($this->createQueuedRunTransfer());
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('getActiveMetrics')->willThrowException(new RuntimeException('Elasticsearch is unreachable.'));

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())->method('failOptimizerRun')->with(1, 'Elasticsearch is unreachable.');

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingFacadeMock);

        // Act
        $runner->runNext();
    }

    /**
     * @return void
     */
    public function testRunNextCompletesSuccessfullyAndPersistsAWinningCandidate(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn($this->createQueuedRunTransfer());
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createBasicSearchRankingFacadeMock();

        // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- the mock's signature must
        // match evaluateCandidate()'s real 3 arguments; only the configuration transfer is used below.
        $objectiveCallback = function (string $storeName, string $localeName, SearchRankingConfigurationStorageTransfer $configurationTransfer): float {
            // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter
            // A deterministic, cheap "objective": reward relevanceWeight closer to 1.0 -- proves real
            // candidate configurations (not just the baseline) are actually being scored differently.
            return $configurationTransfer->getRelevanceWeightOrFail();
        };

        $rankEvaluationRunnerMock = $this->createMock(RankEvaluationRunnerInterface::class);
        $rankEvaluationRunnerMock->method('evaluateCandidate')->willReturnCallback($objectiveCallback);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())->method('startOptimizerRun')->with(1, $this->greaterThan(0), 0.75);
        $entityManagerMock->expects($this->atLeastOnce())->method('updateOptimizerRunProgress');
        $entityManagerMock->expects($this->once())
            ->method('completeOptimizerRun')
            ->with(1, $this->isType('float'), $this->isType('array'), $this->isType('float'));
        $entityManagerMock->expects($this->never())->method('failOptimizerRun');

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingFacadeMock, $rankEvaluationRunnerMock);

        // Act
        $runner->runNext();
    }

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface|null $entityManager
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface|null $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunnerInterface|null $rankEvaluationRunner
     *
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationRunner
     */
    protected function createRunner(
        SearchRankingOptimizerRepositoryInterface $repository,
        ?SearchRankingOptimizerEntityManagerInterface $entityManager = null,
        ?SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade = null,
        ?RankEvaluationRunnerInterface $rankEvaluationRunner = null,
    ): OptimizationRunner {
        if ($rankEvaluationRunner === null) {
            $rankEvaluationRunner = $this->createMock(RankEvaluationRunnerInterface::class);
            $rankEvaluationRunner->method('evaluateCandidate')->willReturn(0.5);
        }

        return new OptimizationRunner(
            $repository,
            $entityManager ?? $this->createMock(SearchRankingOptimizerEntityManagerInterface::class),
            $searchRankingFacade ?? $this->createBasicSearchRankingFacadeMock(),
            $rankEvaluationRunner,
            // Deliberately tiny -- these tests verify orchestration, not optimization quality (already
            // covered by CmaEsAlgorithmTest's own benchmark-function tests).
            maxGenerations: 2,
        );
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface
     */
    protected function createBasicSearchRankingFacadeMock(): SearchRankingOptimizerToSearchRankingFacadeInterface
    {
        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('getActiveMetrics')->willReturn([
            ['idSearchRankingMetric' => 1, 'name' => 'top_seller'],
            ['idSearchRankingMetric' => 2, 'name' => 'pdp_impressions'],
        ]);
        $searchRankingFacadeMock->method('getMetricWeights')->willReturn([
            ['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'weight' => 0.6],
            ['idSearchRankingMetric' => 2, 'name' => 'pdp_impressions', 'weight' => 0.4],
        ]);
        $searchRankingFacadeMock->method('getRelevanceWeight')->willReturn(0.75);
        $searchRankingFacadeMock->method('getRelevanceSaturationPoint')->willReturn(12.0);

        return $searchRankingFacadeMock;
    }

    /**
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer
     */
    protected function createQueuedRunTransfer(): SearchRankingOptimizerRunTransfer
    {
        return (new SearchRankingOptimizerRunTransfer())
            ->setIdSearchRankingOptimizerRun(1)
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setAlgorithm(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION)
            ->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_QUEUED);
    }

    /**
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer
     */
    protected function createDoneRunTransfer(): SearchRankingOptimizerRunTransfer
    {
        return (new SearchRankingOptimizerRunTransfer())
            ->setIdSearchRankingOptimizerRun(1)
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setAlgorithm(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION)
            ->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE);
    }
}
