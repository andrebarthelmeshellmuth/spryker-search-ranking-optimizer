<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Optimization;

use Closure;
use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use Spryker\Zed\Kernel\Persistence\EntityManager\TransactionHandlerInterface;
use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig as SharedSearchRankingConfig;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorderInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationApplier;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Optimization
 * @group OptimizationApplierTest
 */
class OptimizationApplierTest extends Unit
{
    public function testApplyReturnsNullWhenTheRunDoesNotExist(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOptimizerRunById')->with(999)->willReturn(null);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->expects($this->never())->method('saveRelevanceWeight');

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('markOptimizerRunApplied');

        $applier = $this->createApplier($repositoryMock, $searchRankingFacadeMock, null, $entityManagerMock);

        // Act
        $result = $applier->apply(999);

        // Assert
        $this->assertNull($result);
    }

    public function testApplyReturnsNullWhenTheRunIsNotDoneYet(): void
    {
        // Arrange
        $runTransfer = (new SearchRankingOptimizerRunTransfer())
            ->setIdSearchRankingOptimizerRun(1)
            ->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_RUNNING);

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOptimizerRunById')->with(1)->willReturn($runTransfer);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->expects($this->never())->method('saveRelevanceWeight');

        $applier = $this->createApplier($repositoryMock, $searchRankingFacadeMock);

        // Act
        $result = $applier->apply(1);

        // Assert
        $this->assertNull($result);
    }

    public function testApplyWritesTheWinningCandidateThroughTheFacadeRecordsAnOptimizerSourcedCheckpointAndMarksTheRunApplied(): void
    {
        // Arrange
        $doneRunTransfer = (new SearchRankingOptimizerRunTransfer())
            ->setIdSearchRankingOptimizerRun(1)
            ->setStoreName('DE')
            ->setLocaleName('de_DE')
            ->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE)
            ->setBestRelevanceWeight(0.85)
            ->setBestSpecificityWeightExponent(1.2)
            ->setBestSpecificityWeightShiftMagnitude(0.25)
            ->setBestSpecificityBlendWeight(0.7)
            ->addBestMetricWeight(
                (new SearchRankingWeightCheckpointMetricWeightTransfer())
                    ->setIdSearchRankingMetric(1)
                    ->setName('top_seller')
                    ->setWeight(0.6),
            )
            ->addBestMetricWeight(
                (new SearchRankingWeightCheckpointMetricWeightTransfer())
                    ->setIdSearchRankingMetric(2)
                    ->setName('pdp_impressions')
                    ->setWeight(0.4),
            );

        $appliedRunTransfer = (new SearchRankingOptimizerRunTransfer())
            ->setIdSearchRankingOptimizerRun(1)
            ->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE)
            ->setAppliedAt('2026-07-29T00:00:00+00:00');

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->expects($this->exactly(2))
            ->method('findOptimizerRunById')
            ->with(1)
            ->willReturnOnConsecutiveCalls($doneRunTransfer, $appliedRunTransfer);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->expects($this->once())->method('saveRelevanceWeight')->with('DE', 'de_DE', 0.85);
        $searchRankingFacadeMock->expects($this->once())->method('saveSpecificityWeightExponent')->with('DE', 'de_DE', 1.2);
        $searchRankingFacadeMock->expects($this->once())->method('saveSpecificityWeightShiftMagnitude')->with('DE', 'de_DE', 0.25);
        $searchRankingFacadeMock->expects($this->once())->method('saveSpecificityBlendWeight')->with('DE', 'de_DE', 0.7);
        $searchRankingFacadeMock->expects($this->exactly(2))
            ->method('saveMetricWeight')
            ->willReturnMap([
                [1, 'DE', 'de_DE', 0.6, SharedSearchRankingConfig::CHANGE_SOURCE_OPTIMIZER_APPLY, true],
                [2, 'DE', 'de_DE', 0.4, SharedSearchRankingConfig::CHANGE_SOURCE_OPTIMIZER_APPLY, true],
            ]);

        $recorderMock = $this->createMock(WeightCheckpointRecorderInterface::class);
        $recorderMock->expects($this->once())
            ->method('record')
            ->with(SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_OPTIMIZER, 'DE', 'de_DE')
            ->willReturn(new SearchRankingWeightCheckpointTransfer());

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())->method('markOptimizerRunApplied')->with(1);
        $entityManagerMock->method('getTransactionHandler')->willReturn($this->createPassThroughTransactionHandler());

        $applier = $this->createApplier($repositoryMock, $searchRankingFacadeMock, $recorderMock, $entityManagerMock);

        // Act
        $result = $applier->apply(1);

        // Assert
        $this->assertSame($appliedRunTransfer, $result);
    }

    /**
     * A metric a winning candidate wants to write can be deleted between when its optimization run
     * finished and when an admin clicks Apply. Proves the whole apply rolls back rather than leaving
     * relevanceWeight/specificity settings live with only some metric weights applied (which would silently
     * leave the live metric weights summing to less than 1, with the run still marked applied).
     */
    public function testApplyRollsBackEverythingAndReturnsNullWhenAMetricNoLongerExists(): void
    {
        // Arrange
        $doneRunTransfer = (new SearchRankingOptimizerRunTransfer())
            ->setIdSearchRankingOptimizerRun(1)
            ->setStoreName('DE')
            ->setLocaleName('de_DE')
            ->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE)
            ->setBestRelevanceWeight(0.85)
            ->setBestSpecificityWeightExponent(1.2)
            ->setBestSpecificityWeightShiftMagnitude(0.25)
            ->setBestSpecificityBlendWeight(0.7)
            ->addBestMetricWeight(
                (new SearchRankingWeightCheckpointMetricWeightTransfer())
                    ->setIdSearchRankingMetric(404)
                    ->setName('deleted_metric')
                    ->setWeight(1.0),
            );

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->expects($this->once())
            ->method('findOptimizerRunById')
            ->with(1)
            ->willReturn($doneRunTransfer);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('saveMetricWeight')->with(404, 'DE', 'de_DE', 1.0, SharedSearchRankingConfig::CHANGE_SOURCE_OPTIMIZER_APPLY)->willReturn(false);

        $recorderMock = $this->createMock(WeightCheckpointRecorderInterface::class);
        $recorderMock->method('record')->willReturn(new SearchRankingWeightCheckpointTransfer());

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('markOptimizerRunApplied');
        $entityManagerMock->method('getTransactionHandler')->willReturn($this->createPassThroughTransactionHandler());

        $applier = $this->createApplier($repositoryMock, $searchRankingFacadeMock, $recorderMock, $entityManagerMock);

        // Act
        $result = $applier->apply(1);

        // Assert
        $this->assertNull($result);
    }

    /**
     * The checkpoint must snapshot the state BEFORE this run's values are written — recording it after
     * would checkpoint the just-applied state itself, useless as a way back.
     */
    public function testApplyRecordsTheCheckpointBeforeWritingAnyWeights(): void
    {
        // Arrange
        $doneRunTransfer = (new SearchRankingOptimizerRunTransfer())
            ->setIdSearchRankingOptimizerRun(1)
            ->setStoreName('DE')
            ->setLocaleName('de_DE')
            ->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE)
            ->setBestRelevanceWeight(0.85)
            ->setBestSpecificityWeightExponent(1.2)
            ->setBestSpecificityWeightShiftMagnitude(0.25)
            ->setBestSpecificityBlendWeight(0.7);

        $appliedRunTransfer = (new SearchRankingOptimizerRunTransfer())->setIdSearchRankingOptimizerRun(1);

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findOptimizerRunById')->willReturnOnConsecutiveCalls($doneRunTransfer, $appliedRunTransfer);

        $callOrder = [];

        $recorderMock = $this->createMock(WeightCheckpointRecorderInterface::class);
        $recorderMock->method('record')->willReturnCallback(function () use (&$callOrder): SearchRankingWeightCheckpointTransfer {
            $callOrder[] = 'checkpoint';

            return new SearchRankingWeightCheckpointTransfer();
        });

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('saveRelevanceWeight')->willReturnCallback(function () use (&$callOrder): void {
            $callOrder[] = 'saveRelevanceWeight';
        });

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->method('getTransactionHandler')->willReturn($this->createPassThroughTransactionHandler());

        $applier = $this->createApplier($repositoryMock, $searchRankingFacadeMock, $recorderMock, $entityManagerMock);

        // Act
        $applier->apply(1);

        // Assert
        $this->assertSame(['checkpoint', 'saveRelevanceWeight'], $callOrder);
    }

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorderInterface|null $recorder
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface|null $entityManager
     */
    protected function createApplier(
        SearchRankingOptimizerRepositoryInterface $repository,
        SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
        ?WeightCheckpointRecorderInterface $recorder = null,
        ?SearchRankingOptimizerEntityManagerInterface $entityManager = null,
    ): OptimizationApplier {
        if ($entityManager === null) {
            $entityManager = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
            $entityManager->method('getTransactionHandler')->willReturn($this->createPassThroughTransactionHandler());
        }

        return new OptimizationApplier(
            $repository,
            $searchRankingFacade,
            $recorder ?? $this->createMock(WeightCheckpointRecorderInterface::class),
            $entityManager,
        );
    }

    /**
     * A real transaction handler needs a live Propel connection this unit test has none of — this fake
     * just invokes the callback directly (no real transaction), which is all `OptimizationApplier` needs
     * from it: the callback runs, and any exception it throws propagates same as the real handler would
     * after its own rollback.
     */
    protected function createPassThroughTransactionHandler(): TransactionHandlerInterface
    {
        return new class implements TransactionHandlerInterface {
            /**
             * @param \Closure $callback
             */
            public function handleTransaction(Closure $callback): mixed
            {
                return $callback();
            }
        };
    }
}
