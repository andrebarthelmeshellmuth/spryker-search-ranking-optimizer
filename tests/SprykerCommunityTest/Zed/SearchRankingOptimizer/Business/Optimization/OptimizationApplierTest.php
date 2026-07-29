<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Optimization;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
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
    /**
     * @return void
     */
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

    /**
     * @return void
     */
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

    /**
     * @return void
     */
    public function testApplyWritesTheWinningCandidateThroughTheFacadeRecordsAnOptimizerSourcedCheckpointAndMarksTheRunApplied(): void
    {
        // Arrange
        $doneRunTransfer = (new SearchRankingOptimizerRunTransfer())
            ->setIdSearchRankingOptimizerRun(1)
            ->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE)
            ->setBestRelevanceWeight(0.85)
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
        $searchRankingFacadeMock->expects($this->once())->method('saveRelevanceWeight')->with(0.85);
        $searchRankingFacadeMock->expects($this->exactly(2))
            ->method('saveMetricWeight')
            ->willReturnMap([
                [1, 0.6, true],
                [2, 0.4, true],
            ]);

        $recorderMock = $this->createMock(WeightCheckpointRecorderInterface::class);
        $recorderMock->expects($this->once())
            ->method('record')
            ->with(SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_OPTIMIZER)
            ->willReturn(new SearchRankingWeightCheckpointTransfer());

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())->method('markOptimizerRunApplied')->with(1);

        $applier = $this->createApplier($repositoryMock, $searchRankingFacadeMock, $recorderMock, $entityManagerMock);

        // Act
        $result = $applier->apply(1);

        // Assert
        $this->assertSame($appliedRunTransfer, $result);
    }

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorderInterface|null $recorder
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface|null $entityManager
     *
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationApplier
     */
    protected function createApplier(
        SearchRankingOptimizerRepositoryInterface $repository,
        SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
        ?WeightCheckpointRecorderInterface $recorder = null,
        ?SearchRankingOptimizerEntityManagerInterface $entityManager = null,
    ): OptimizationApplier {
        return new OptimizationApplier(
            $repository,
            $searchRankingFacade,
            $recorder ?? $this->createMock(WeightCheckpointRecorderInterface::class),
            $entityManager ?? $this->createMock(SearchRankingOptimizerEntityManagerInterface::class),
        );
    }
}
