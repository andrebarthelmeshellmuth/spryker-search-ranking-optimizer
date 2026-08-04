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
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismChecker;
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

    public function testRunNextCompletesSuccessfullyAndPersistsAWinningCandidate(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn($this->createQueuedRunTransfer());
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createBasicSearchRankingFacadeMock();

        // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- the mock's signature must
        // match evaluateCandidate()'s real 3 arguments; only the configuration transfer is used below.
        // A deterministic, cheap "objective": reward relevanceWeight closer to 1.0 -- proves real
        // candidate configurations (not just the baseline) are actually being scored differently.
        $objectiveCallback = fn (string $storeName, string $localeName, SearchRankingConfigurationStorageTransfer $configurationTransfer): float => $configurationTransfer->getRelevanceWeightOrFail();
        // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter

        $rankEvaluationRunnerMock = $this->createMock(RankEvaluationRunnerInterface::class);
        $rankEvaluationRunnerMock->method('evaluateCandidate')->willReturnCallback($objectiveCallback);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())->method('startOptimizerRun')->with(1, $this->greaterThan(0), 0.75);
        $entityManagerMock->expects($this->atLeastOnce())->method('updateOptimizerRunProgress');
        $entityManagerMock->expects($this->once())
            ->method('completeOptimizerRun')
            ->with(
                1,
                $this->isType('float'),
                $this->isType('array'),
                $this->isType('float'),
                $this->isType('float'),
                $this->isType('float'),
                $this->isType('float'),
            );
        $entityManagerMock->expects($this->never())->method('failOptimizerRun');

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingFacadeMock, $rankEvaluationRunnerMock);

        // Act
        $runner->runNext();
    }

    public function testRunNextHoldsANonDeterministicMetricsWeightFixedAndNeverIncludesItInTheSearch(): void
    {
        // Arrange -- "random" (formula calls random()) sits alongside two real metrics. It must keep
        // EXACTLY its current live weight (0.2) in the persisted result, never something the objective
        // function's own optimization touched.
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn($this->createQueuedRunTransfer());
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('getActiveMetrics')->willReturn([
            ['idSearchRankingMetric' => 1, 'name' => 'top_seller'],
            ['idSearchRankingMetric' => 2, 'name' => 'pdp_impressions'],
            ['idSearchRankingMetric' => 3, 'name' => 'random'],
        ]);
        $searchRankingFacadeMock->method('getMetricWeights')->willReturn([
            ['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'weight' => 0.4],
            ['idSearchRankingMetric' => 2, 'name' => 'pdp_impressions', 'weight' => 0.4],
            ['idSearchRankingMetric' => 3, 'name' => 'random', 'weight' => 0.2],
        ]);
        $searchRankingFacadeMock->method('getRelevanceWeight')->willReturn(0.75);
        $searchRankingFacadeMock->method('getRelevanceSaturationPoint')->willReturn(12.0);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturnMap([
            [1, ['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'formula' => 'x / max', 'isHigherBetter' => true, 'shape' => 'linear']],
            [2, ['idSearchRankingMetric' => 2, 'name' => 'pdp_impressions', 'formula' => 'atan(x / avg)', 'isHigherBetter' => true, 'shape' => 'atan']],
            [3, ['idSearchRankingMetric' => 3, 'name' => 'random', 'formula' => 'random()', 'isHigherBetter' => true, 'shape' => null]],
        ]);

        $rankEvaluationRunnerMock = $this->createMock(RankEvaluationRunnerInterface::class);
        $rankEvaluationRunnerMock->method('evaluateCandidate')->willReturn(0.5);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $capturedBestMetricWeightTransfers = null;

        // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- the mock's signature must
        // match completeOptimizerRun()'s real 4 arguments; only the metric weight transfers are used below.
        $captureCallback = function (int $idOptimizerRun, float $bestRelevanceWeight, array $bestMetricWeightTransfers) use (&$capturedBestMetricWeightTransfers): void {
            // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter
            $capturedBestMetricWeightTransfers = $bestMetricWeightTransfers;
        };
        $entityManagerMock->expects($this->once())->method('completeOptimizerRun')->willReturnCallback($captureCallback);

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingFacadeMock, $rankEvaluationRunnerMock);

        // Act
        $runner->runNext();

        // Assert
        $weightsByName = [];

        foreach ($capturedBestMetricWeightTransfers as $metricWeightTransfer) {
            $weightsByName[$metricWeightTransfer->getName()] = $metricWeightTransfer->getWeight();
        }

        $this->assertSame(0.2, $weightsByName['random'], "random's weight must be exactly its current live value, untouched by the search.");
        $this->assertEqualsWithDelta(1.0, array_sum($weightsByName), 1e-9, 'The full set must still sum to 1.');
    }

    public function testRunNextSeedsTheBaselineWithTheLiveSpecificitySettingsAndKeepsEveryCandidateWithinTheirTrustRegion(): void
    {
        // Arrange -- the live facade reports specificityWeightExponent=1.5, specificityWeightShiftMagnitude=0.25,
        // specificityBlendWeight=0.7 (see createBasicSearchRankingFacadeMock()). The very first
        // evaluateCandidate() call is the baseline (unmodified buildLiveConfiguration() output), every
        // later call is a real search candidate that must stay inside the configured trust region around
        // those same live values.
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn($this->createQueuedRunTransfer());
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createBasicSearchRankingFacadeMock();

        $seenConfigurationTransfers = [];

        // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- the mock's signature must
        // match evaluateCandidate()'s real 3 arguments; only the configuration transfer is captured below.
        $captureCallback = function (string $storeName, string $localeName, SearchRankingConfigurationStorageTransfer $configurationTransfer) use (&$seenConfigurationTransfers): float {
            // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter
            $seenConfigurationTransfers[] = $configurationTransfer;

            return 0.5;
        };

        $rankEvaluationRunnerMock = $this->createMock(RankEvaluationRunnerInterface::class);
        $rankEvaluationRunnerMock->method('evaluateCandidate')->willReturnCallback($captureCallback);

        $runner = $this->createRunner($repositoryMock, null, $searchRankingFacadeMock, $rankEvaluationRunnerMock);

        // Act
        $runner->runNext();

        // Assert
        $this->assertGreaterThan(1, count($seenConfigurationTransfers), 'A real search must evaluate more than just the baseline.');

        $baselineTransfer = $seenConfigurationTransfers[0];
        $this->assertSame(1.5, $baselineTransfer->getSpecificityWeightExponent(), 'The baseline call must carry the LIVE value, untouched.');
        $this->assertSame(0.25, $baselineTransfer->getSpecificityWeightShiftMagnitude());
        $this->assertSame(0.7, $baselineTransfer->getSpecificityBlendWeight());

        $exponentMaxDistance = SearchRankingOptimizerConfig::getSpecificityWeightExponentTrustRegionMaxDistance();
        $shiftMaxDistance = SearchRankingOptimizerConfig::getSpecificityWeightShiftMagnitudeTrustRegionMaxDistance();
        $blendWeightMaxDistance = SearchRankingOptimizerConfig::getSpecificityBlendWeightTrustRegionMaxDistance();

        foreach ($seenConfigurationTransfers as $configurationTransfer) {
            $this->assertEqualsWithDelta(1.5, $configurationTransfer->getSpecificityWeightExponent(), $exponentMaxDistance + 1e-9);
            $this->assertEqualsWithDelta(0.25, $configurationTransfer->getSpecificityWeightShiftMagnitude(), $shiftMaxDistance + 1e-9);
            $this->assertEqualsWithDelta(0.7, $configurationTransfer->getSpecificityBlendWeight(), $blendWeightMaxDistance + 1e-9);
        }
    }

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface|null $entityManager
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface|null $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunnerInterface|null $rankEvaluationRunner
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
            new FormulaDeterminismChecker(),
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
        $searchRankingFacadeMock->method('getSpecificityWeightExponent')->willReturn(1.5);
        $searchRankingFacadeMock->method('getSpecificityWeightShiftMagnitude')->willReturn(0.25);
        $searchRankingFacadeMock->method('getSpecificityBlendWeight')->willReturn(0.7);
        $searchRankingFacadeMock->method('isSpecificityWeightingEnabled')->willReturn(true);

        return $searchRankingFacadeMock;
    }

    protected function createQueuedRunTransfer(): SearchRankingOptimizerRunTransfer
    {
        return (new SearchRankingOptimizerRunTransfer())
            ->setIdSearchRankingOptimizerRun(1)
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setAlgorithm(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION)
            ->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_QUEUED);
    }

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
