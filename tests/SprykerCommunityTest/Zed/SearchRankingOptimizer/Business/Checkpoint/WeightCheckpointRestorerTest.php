<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Checkpoint;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorderInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRestorer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Checkpoint
 * @group WeightCheckpointRestorerTest
 * Add your own group annotations below this line
 */
class WeightCheckpointRestorerTest extends Unit
{
    /**
     * @return void
     */
    public function testRestoreReturnsNullWhenCheckpointDoesNotExist(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findWeightCheckpointById')->with(999)->willReturn(null);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->expects($this->never())->method('saveRelevanceWeight');

        $recorderMock = $this->createMock(WeightCheckpointRecorderInterface::class);
        $recorderMock->expects($this->never())->method('record');

        $restorer = new WeightCheckpointRestorer($repositoryMock, $searchRankingFacadeMock, $recorderMock);

        // Act
        $result = $restorer->restore(999);

        // Assert
        $this->assertNull($result);
    }

    /**
     * @return void
     */
    public function testRestoreWritesRelevanceWeightEntropyKnobsAndMetricWeightsBackThroughTheBridgeThenRecordsANewCheckpoint(): void
    {
        // Arrange
        $checkpointTransfer = (new SearchRankingWeightCheckpointTransfer())
            ->setIdSearchRankingWeightCheckpoint(7)
            ->setSource('auto-tune')
            ->setRelevanceWeight(0.75)
            ->setEntropyProbeResultSize(50)
            ->setEntropyWeightExponent(2.0)
            ->setEntropyWeightShiftMagnitude(0.25)
            ->setIsEntropyWeightingEnabled(true)
            ->addMetricWeight(
                (new SearchRankingWeightCheckpointMetricWeightTransfer())
                    ->setIdSearchRankingMetric(1)
                    ->setName('sales')
                    ->setWeight(0.4),
            )
            ->addMetricWeight(
                (new SearchRankingWeightCheckpointMetricWeightTransfer())
                    ->setIdSearchRankingMetric(2)
                    ->setName('margin')
                    ->setWeight(0.6),
            );

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findWeightCheckpointById')->with(7)->willReturn($checkpointTransfer);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->expects($this->once())->method('saveRelevanceWeight')->with(0.75);
        $searchRankingFacadeMock->expects($this->once())->method('saveEntropyProbeResultSize')->with(50);
        $searchRankingFacadeMock->expects($this->once())->method('saveEntropyWeightExponent')->with(2.0);
        $searchRankingFacadeMock->expects($this->once())->method('saveEntropyWeightShiftMagnitude')->with(0.25);
        $searchRankingFacadeMock->expects($this->exactly(2))
            ->method('saveMetricWeight')
            ->willReturnMap([
                [1, 0.4, true],
                [2, 0.6, true],
            ]);
        $searchRankingFacadeMock->expects($this->never())->method('isEntropyWeightingEnabled');

        $newCheckpointTransfer = (new SearchRankingWeightCheckpointTransfer())->setSource('manual');
        $recorderMock = $this->createMock(WeightCheckpointRecorderInterface::class);
        $recorderMock->expects($this->once())->method('record')->with('manual')->willReturn($newCheckpointTransfer);

        $restorer = new WeightCheckpointRestorer($repositoryMock, $searchRankingFacadeMock, $recorderMock);

        // Act
        $result = $restorer->restore(7);

        // Assert
        $this->assertSame($newCheckpointTransfer, $result);
    }

    /**
     * @return void
     */
    public function testRestoreSkipsAMetricWeightThatNoLongerExistsWithoutFailing(): void
    {
        // Arrange
        $checkpointTransfer = (new SearchRankingWeightCheckpointTransfer())
            ->setIdSearchRankingWeightCheckpoint(7)
            ->setSource('auto-tune')
            ->setRelevanceWeight(0.75)
            ->setEntropyProbeResultSize(50)
            ->setEntropyWeightExponent(2.0)
            ->setEntropyWeightShiftMagnitude(0.25)
            ->setIsEntropyWeightingEnabled(false)
            ->addMetricWeight(
                (new SearchRankingWeightCheckpointMetricWeightTransfer())
                    ->setIdSearchRankingMetric(999)
                    ->setName('deleted-metric')
                    ->setWeight(1.0),
            );

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findWeightCheckpointById')->willReturn($checkpointTransfer);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->expects($this->once())
            ->method('saveMetricWeight')
            ->with(999, 1.0)
            ->willReturn(false);

        $recorderMock = $this->createMock(WeightCheckpointRecorderInterface::class);
        $recorderMock->method('record')->willReturn(new SearchRankingWeightCheckpointTransfer());

        $restorer = new WeightCheckpointRestorer($repositoryMock, $searchRankingFacadeMock, $recorderMock);

        // Act
        $result = $restorer->restore(7);

        // Assert
        $this->assertNotNull($result);
    }
}
