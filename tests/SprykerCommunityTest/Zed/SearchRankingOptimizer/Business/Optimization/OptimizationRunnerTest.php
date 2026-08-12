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
use Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer;
use RuntimeException;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunnerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismChecker;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\AlgorithmFactory;
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

    /**
     * Same shape as {@see testRunNextCompletesSuccessfullyAndPersistsAWinningCandidate()}, run against
     * RechenbergSchwefelEsAlgorithm instead of the default DifferentialEvolutionAlgorithm -- proves
     * AlgorithmFactory::create() actually routes the third algorithm choice correctly, not just that DE and
     * CMA-ES (already exercised elsewhere) still work.
     */
    public function testRunNextCompletesSuccessfullyWithTheRechenbergSchwefelEsAlgorithm(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn(
            $this->createQueuedRunTransfer(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_RECHENBERG_SCHWEFEL_ES),
        );
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createBasicSearchRankingFacadeMock();

        // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- see the DE test above.
        $objectiveCallback = fn (string $storeName, string $localeName, SearchRankingConfigurationStorageTransfer $configurationTransfer): float => $configurationTransfer->getRelevanceWeightOrFail();
        // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter

        $rankEvaluationRunnerMock = $this->createMock(RankEvaluationRunnerInterface::class);
        $rankEvaluationRunnerMock->method('evaluateCandidate')->willReturnCallback($objectiveCallback);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())->method('startOptimizerRun')->with(1, $this->greaterThan(0), 0.75);
        $entityManagerMock->expects($this->atLeastOnce())->method('updateOptimizerRunProgress');
        $entityManagerMock->expects($this->once())->method('completeOptimizerRun');
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
            ['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'isLocaleScoped' => true],
            ['idSearchRankingMetric' => 2, 'name' => 'pdp_impressions', 'isLocaleScoped' => true],
            ['idSearchRankingMetric' => 3, 'name' => 'random', 'isLocaleScoped' => true],
        ]);
        $searchRankingFacadeMock->method('getConfiguration')->willReturn(
            $this->createLiveConfigurationTransfer(['top_seller' => 0.4, 'pdp_impressions' => 0.4, 'random' => 0.2]),
        );
        $searchRankingFacadeMock->method('findMetricDetail')->willReturnMap([
            [1, 'DE', 'en_US', ['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'formula' => 'x / max', 'isHigherBetter' => true, 'shape' => 'linear']],
            [2, 'DE', 'en_US', ['idSearchRankingMetric' => 2, 'name' => 'pdp_impressions', 'formula' => 'atan(x / avg)', 'isHigherBetter' => true, 'shape' => 'atan']],
            [3, 'DE', 'en_US', ['idSearchRankingMetric' => 3, 'name' => 'random', 'formula' => 'random()', 'isHigherBetter' => true, 'shape' => null]],
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

    public function testRunNextIncludesAStoreWideMetricInTheSearchAndInTheAppliedResult(): void
    {
        // Arrange -- "top_seller" is store-wide (isLocaleScoped=false): search-ranking's own
        // saveMetricWeight() fans a write for it out to every real locale of the store on Apply, same as a
        // human manually editing it from the Metrics page in any one locale already does today. That's not
        // a reason to exclude it -- this (store, locale) run searches and proposes a value for it exactly
        // like any other deterministic metric.
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn($this->createQueuedRunTransfer());
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('getActiveMetrics')->willReturn([
            ['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'isLocaleScoped' => false],
            ['idSearchRankingMetric' => 2, 'name' => 'pdp_impressions', 'isLocaleScoped' => true],
        ]);
        $searchRankingFacadeMock->method('getConfiguration')->willReturn(
            $this->createLiveConfigurationTransfer(['top_seller' => 0.3, 'pdp_impressions' => 0.7]),
        );
        // Now called for BOTH metrics -- a store-wide metric goes through the same determinism check.
        $searchRankingFacadeMock->expects($this->exactly(2))->method('findMetricDetail')->willReturnMap([
            [1, 'DE', 'en_US', ['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'formula' => 'x / max', 'isHigherBetter' => true, 'shape' => 'linear']],
            [2, 'DE', 'en_US', ['idSearchRankingMetric' => 2, 'name' => 'pdp_impressions', 'formula' => 'atan(x / avg)', 'isHigherBetter' => true, 'shape' => 'atan']],
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

        $this->assertArrayHasKey('top_seller', $weightsByName, 'A store-wide metric must be part of what an Apply action writes back.');
        $this->assertArrayHasKey('pdp_impressions', $weightsByName);
        $this->assertEqualsWithDelta(1.0, array_sum($weightsByName), 1e-9, 'The full set must still sum to 1.');
    }

    /**
     * generations_used must be the real number of generations blackbox-optimizer's own
     * OptimizationResult::getBestValueHistory() reports, independent of the totalCount/processedCount
     * candidate-evaluation counters -- captures completeOptimizerRun()'s new 9th argument directly rather
     * than inferring it, since it's a plain int with no further behavioral signature to probe.
     */
    public function testRunNextPersistsGenerationsUsedOnCompletion(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn($this->createQueuedRunTransfer());
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createBasicSearchRankingFacadeMock();

        $rankEvaluationRunnerMock = $this->createMock(RankEvaluationRunnerInterface::class);
        $rankEvaluationRunnerMock->method('evaluateCandidate')->willReturn(0.5);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $capturedGenerationsUsed = null;

        // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- the mock's signature must match
        // completeOptimizerRun()'s real 9 arguments; only the last (generationsUsed) is used below.
        $captureCallback = function (
            int $idOptimizerRun,
            float $bestRelevanceWeight,
            array $bestMetricWeightTransfers,
            float $bestScore,
            float $bestSpecificityBlendWeight,
            float $bestSpecificityCurveExponent,
            float $bestSpecificityWeightExponent,
            float $bestSpecificityWeightShiftMagnitude,
            int $generationsUsed,
        ) use (&$capturedGenerationsUsed): void {
            // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter
            $capturedGenerationsUsed = $generationsUsed;
        };
        $entityManagerMock->expects($this->once())->method('completeOptimizerRun')->willReturnCallback($captureCallback);

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingFacadeMock, $rankEvaluationRunnerMock);

        // Act
        $runner->runNext();

        // Assert -- createRunner() uses a deliberately tiny maxGenerations=2 (DE's own initial-population
        // batch plus up to 2 generations), so generationsUsed is bounded above by that same real behavior.
        $this->assertGreaterThanOrEqual(1, $capturedGenerationsUsed);
        $this->assertLessThanOrEqual(3, $capturedGenerationsUsed);
    }

    /**
     * Proves isTerminationCriteriaTrusted actually reaches the built algorithm, not just that
     * AlgorithmFactory::create() itself honors the flag (already covered by AlgorithmFactoryTest) --
     * createRunner()'s deliberately tiny maxGenerations=2 override must be ignored once this run's own
     * isTerminationCriteriaTrusted is true, the same "exceeds the tiny budget" proof
     * blackbox-optimizer's own algorithm tests use for trustTerminationCriteria() itself.
     */
    public function testRunNextPassesIsTerminationCriteriaTrustedFromTheQueuedRunToTheAlgorithm(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn(
            $this->createQueuedRunTransfer(isTerminationCriteriaTrusted: true),
        );
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createBasicSearchRankingFacadeMock();

        // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- see the DE test above.
        $objectiveCallback = fn (string $storeName, string $localeName, SearchRankingConfigurationStorageTransfer $configurationTransfer): float => $configurationTransfer->getRelevanceWeightOrFail();
        // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter

        $rankEvaluationRunnerMock = $this->createMock(RankEvaluationRunnerInterface::class);
        $rankEvaluationRunnerMock->method('evaluateCandidate')->willReturnCallback($objectiveCallback);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $capturedGenerationsUsed = null;

        // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- see the capture test above.
        $captureCallback = function (
            int $idOptimizerRun,
            float $bestRelevanceWeight,
            array $bestMetricWeightTransfers,
            float $bestScore,
            float $bestSpecificityBlendWeight,
            float $bestSpecificityCurveExponent,
            float $bestSpecificityWeightExponent,
            float $bestSpecificityWeightShiftMagnitude,
            int $generationsUsed,
        ) use (&$capturedGenerationsUsed): void {
            // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter
            $capturedGenerationsUsed = $generationsUsed;
        };
        $entityManagerMock->expects($this->once())->method('completeOptimizerRun')->willReturnCallback($captureCallback);

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingFacadeMock, $rankEvaluationRunnerMock);

        // Act
        $runner->runNext();

        // Assert
        $this->assertGreaterThan(3, $capturedGenerationsUsed, 'The 2-generation cap from maxGenerations must be ignored once this run\'s own isTerminationCriteriaTrusted is true.');
    }

    /**
     * Structural, not statistical: proves the mapConfigurationToVector()-to-setWarmStart() plumbing (a real
     * array shape/count match between ParameterVectorMapper's own output and what the built algorithm
     * accepts) doesn't break the run end to end. The actual warm-start ARITHMETIC is already proven
     * correct and precisely, deterministically at the AlgorithmFactoryTest level -- this only needs to show
     * the orchestration wires it through without error.
     */
    public function testRunNextPassesWarmStartFractionFromTheQueuedRunToTheAlgorithmWithoutError(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn(
            $this->createQueuedRunTransfer(warmStartFraction: 1.0),
        );
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createBasicSearchRankingFacadeMock();

        $rankEvaluationRunnerMock = $this->createMock(RankEvaluationRunnerInterface::class);
        $rankEvaluationRunnerMock->method('evaluateCandidate')->willReturn(0.5);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())->method('completeOptimizerRun');
        $entityManagerMock->expects($this->never())->method('failOptimizerRun');

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingFacadeMock, $rankEvaluationRunnerMock);

        // Act
        $runner->runNext();
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

    public function testRunNextHoldsAUserFixedMetricConstantAtItsChosenValueEvenThoughItsFormulaIsDeterministic(): void
    {
        // Arrange -- top_seller's formula is deterministic (it would normally be searched, and its own
        // current live weight is 0.6 -- see createBasicSearchRankingFacadeMock()), but a human pinned it at
        // 0.55 on the run form's own checklist instead, a DIFFERENT value than the live one.
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn(
            $this->createQueuedRunTransfer(fixedMetricWeights: ['top_seller' => 0.55]),
        );
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createBasicSearchRankingFacadeMock();

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

        $this->assertSame(0.55, $weightsByName['top_seller'], "The human-chosen pin value, not top_seller's live weight of 0.6.");
        $this->assertEqualsWithDelta(1.0, array_sum($weightsByName), 1e-9, 'The full set must still sum to 1.');
    }

    public function testRunNextUsesTheFixedRelevanceWeightRatherThanSearchingIt(): void
    {
        // Arrange -- a human pinned relevanceWeight at 0.42 on the run form's own checklist. The objective
        // function below rewards relevanceWeight closer to 1.0, so if the fixed value were NOT actually
        // honored, the search would drift the winning candidate's relevanceWeight away from 0.42.
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn(
            $this->createQueuedRunTransfer(fixedRelevanceWeight: 0.42),
        );
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createBasicSearchRankingFacadeMock();

        // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- see the DE test above.
        $objectiveCallback = fn (string $storeName, string $localeName, SearchRankingConfigurationStorageTransfer $configurationTransfer): float => $configurationTransfer->getRelevanceWeightOrFail();
        // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter

        $rankEvaluationRunnerMock = $this->createMock(RankEvaluationRunnerInterface::class);
        $rankEvaluationRunnerMock->method('evaluateCandidate')->willReturnCallback($objectiveCallback);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $capturedBestRelevanceWeight = null;

        // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- the mock's signature must
        // match completeOptimizerRun()'s real 2nd argument; the rest are unused below.
        $captureCallback = function (int $idOptimizerRun, float $bestRelevanceWeight) use (&$capturedBestRelevanceWeight): void {
            // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter
            $capturedBestRelevanceWeight = $bestRelevanceWeight;
        };
        $entityManagerMock->expects($this->once())->method('completeOptimizerRun')->willReturnCallback($captureCallback);

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingFacadeMock, $rankEvaluationRunnerMock);

        // Act
        $runner->runNext();

        // Assert
        $this->assertSame(0.42, $capturedBestRelevanceWeight, 'The fixed value -- an unconstrained search maximizing relevanceWeight would have pushed it toward 1.0 instead.');
    }

    public function testRunNextUsesAFixedSpecificityKnobRatherThanSearchingIt(): void
    {
        // Arrange -- a human pinned specificityBlendWeight at 0.33 on the run form's own checklist, a value
        // outside its own live trust region ([0.5, 0.9], see createBasicSearchRankingFacadeMock()'s 0.7 +/-
        // 0.2) -- proving the fixed value is used as-is, never clamped to a trust region meant for the free
        // case.
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOldestQueuedOptimizerRun')->willReturn(
            $this->createQueuedRunTransfer(fixedSpecificityBlendWeight: 0.33),
        );
        $repositoryMock->method('findOptimizerRunById')->willReturn($this->createDoneRunTransfer());

        $searchRankingFacadeMock = $this->createBasicSearchRankingFacadeMock();

        $rankEvaluationRunnerMock = $this->createMock(RankEvaluationRunnerInterface::class);
        $rankEvaluationRunnerMock->method('evaluateCandidate')->willReturn(0.5);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $capturedBestSpecificityBlendWeight = null;

        // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- the mock's signature must
        // match completeOptimizerRun()'s real 5th argument; the rest are unused below.
        $captureCallback = function (
            int $idOptimizerRun,
            float $bestRelevanceWeight,
            array $bestMetricWeightTransfers,
            float $bestScore,
            float $bestSpecificityBlendWeight,
        ) use (&$capturedBestSpecificityBlendWeight): void {
            // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter
            $capturedBestSpecificityBlendWeight = $bestSpecificityBlendWeight;
        };
        $entityManagerMock->expects($this->once())->method('completeOptimizerRun')->willReturnCallback($captureCallback);

        $runner = $this->createRunner($repositoryMock, $entityManagerMock, $searchRankingFacadeMock, $rankEvaluationRunnerMock);

        // Act
        $runner->runNext();

        // Assert
        $this->assertSame(0.33, $capturedBestSpecificityBlendWeight);
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
            new AlgorithmFactory(),
            // Deliberately tiny -- these tests verify orchestration, not optimization quality (already
            // covered by CmaEsAlgorithmTest's own benchmark-function tests).
            maxGenerations: 2,
        );
    }

    protected function createBasicSearchRankingFacadeMock(): SearchRankingOptimizerToSearchRankingFacadeInterface
    {
        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('getActiveMetrics')->willReturn([
            ['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'isLocaleScoped' => true],
            ['idSearchRankingMetric' => 2, 'name' => 'pdp_impressions', 'isLocaleScoped' => true],
        ]);
        $searchRankingFacadeMock->method('getConfiguration')->willReturn(
            $this->createLiveConfigurationTransfer(['top_seller' => 0.6, 'pdp_impressions' => 0.4]),
        );
        $searchRankingFacadeMock->method('isSpecificityWeightingEnabled')->willReturn(true);

        return $searchRankingFacadeMock;
    }

    /**
     * The one live configuration `search-ranking`'s own facade hands back per (store, locale) — the single
     * read {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationRunner}
     * builds its whole baseline from.
     *
     * @param array<string, float> $metricWeights
     */
    protected function createLiveConfigurationTransfer(array $metricWeights): SearchRankingConfigurationStorageTransfer
    {
        return (new SearchRankingConfigurationStorageTransfer())
            ->setMetricWeights($metricWeights)
            ->setRelevanceWeight(0.75)
            ->setRelevanceSaturationPoint(12.0)
            ->setSpecificitySaturationPoint(3.0)
            ->setSpecificityCurveExponent(1.0)
            ->setSpecificityWeightExponent(1.5)
            ->setSpecificityWeightShiftMagnitude(0.25)
            ->setSpecificityBlendWeight(0.7)
            ->setRandomMetricName('random');
    }

    /**
     * @param string|null $algorithm
     * @param bool $isTerminationCriteriaTrusted
     * @param float $warmStartFraction
     * @param float|null $fixedRelevanceWeight
     * @param float|null $fixedSpecificityCurveExponent
     * @param float|null $fixedSpecificityWeightExponent
     * @param float|null $fixedSpecificityWeightShiftMagnitude
     * @param float|null $fixedSpecificityBlendWeight
     * @param array<string, float> $fixedMetricWeights Name => the value a human chose to pin it at.
     */
    protected function createQueuedRunTransfer(
        ?string $algorithm = null,
        bool $isTerminationCriteriaTrusted = false,
        float $warmStartFraction = 0.0,
        ?float $fixedRelevanceWeight = null,
        ?float $fixedSpecificityCurveExponent = null,
        ?float $fixedSpecificityWeightExponent = null,
        ?float $fixedSpecificityWeightShiftMagnitude = null,
        ?float $fixedSpecificityBlendWeight = null,
        array $fixedMetricWeights = [],
    ): SearchRankingOptimizerRunTransfer {
        $optimizerRunTransfer = (new SearchRankingOptimizerRunTransfer())
            ->setIdSearchRankingOptimizerRun(1)
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setAlgorithm($algorithm ?? SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION)
            ->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_QUEUED)
            ->setIsTerminationCriteriaTrusted($isTerminationCriteriaTrusted)
            ->setWarmStartFraction($warmStartFraction)
            ->setFixedRelevanceWeight($fixedRelevanceWeight)
            ->setFixedSpecificityCurveExponent($fixedSpecificityCurveExponent)
            ->setFixedSpecificityWeightExponent($fixedSpecificityWeightExponent)
            ->setFixedSpecificityWeightShiftMagnitude($fixedSpecificityWeightShiftMagnitude)
            ->setFixedSpecificityBlendWeight($fixedSpecificityBlendWeight);

        foreach ($fixedMetricWeights as $name => $weight) {
            $optimizerRunTransfer->addFixedMetricWeight(
                (new SearchRankingWeightCheckpointMetricWeightTransfer())
                    ->setName($name)
                    ->setWeight($weight),
            );
        }

        return $optimizerRunTransfer;
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
