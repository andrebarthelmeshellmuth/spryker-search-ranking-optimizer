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
use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig as SharedSearchRankingConfig;
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
        $result = $restorer->restore(999, 'DE', 'de_DE');

        // Assert
        $this->assertNull($result);
    }

    public function testRestoreWritesRelevanceWeightSpecificityKnobsAndMetricWeightsBackThroughTheBridgeThenRecordsANewCheckpoint(): void
    {
        // Arrange
        $checkpointTransfer = (new SearchRankingWeightCheckpointTransfer())
            ->setIdSearchRankingWeightCheckpoint(7)
            ->setSource('auto-tune')
            ->setRelevanceWeight(0.75)
            ->setSpecificityBlendWeight(0.7)
            ->setSpecificityCurveExponent(1.5)
            ->setSpecificityWeightExponent(2.0)
            ->setSpecificityWeightShiftMagnitude(0.25)
            ->setIsSpecificityWeightingEnabled(true)
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
        $searchRankingFacadeMock->expects($this->once())->method('saveRelevanceWeight')->with('DE', 'de_DE', 0.75);
        $searchRankingFacadeMock->expects($this->once())->method('saveSpecificityBlendWeight')->with('DE', 'de_DE', 0.7);
        $searchRankingFacadeMock->expects($this->once())->method('saveSpecificityCurveExponent')->with('DE', 'de_DE', 1.5);
        $searchRankingFacadeMock->expects($this->once())->method('saveSpecificityWeightExponent')->with('DE', 'de_DE', 2.0);
        $searchRankingFacadeMock->expects($this->once())->method('saveSpecificityWeightShiftMagnitude')->with('DE', 'de_DE', 0.25);
        $searchRankingFacadeMock->expects($this->exactly(2))
            ->method('saveMetricWeight')
            ->willReturnMap([
                [1, 'DE', 'de_DE', 0.4, SharedSearchRankingConfig::CHANGE_SOURCE_CHECKPOINT_RESTORE, true],
                [2, 'DE', 'de_DE', 0.6, SharedSearchRankingConfig::CHANGE_SOURCE_CHECKPOINT_RESTORE, true],
            ]);
        $searchRankingFacadeMock->expects($this->never())->method('isSpecificityWeightingEnabled');

        $newCheckpointTransfer = (new SearchRankingWeightCheckpointTransfer())->setSource('manual');
        $recorderMock = $this->createMock(WeightCheckpointRecorderInterface::class);
        $recorderMock->expects($this->once())->method('record')->with('manual', 'DE', 'de_DE')->willReturn($newCheckpointTransfer);

        $restorer = new WeightCheckpointRestorer($repositoryMock, $searchRankingFacadeMock, $recorderMock);

        // Act
        $result = $restorer->restore(7, 'DE', 'de_DE');

        // Assert
        $this->assertSame($newCheckpointTransfer, $result);
    }

    public function testRestoreSkipsAMetricWeightThatNoLongerExistsWithoutFailing(): void
    {
        // Arrange
        $checkpointTransfer = (new SearchRankingWeightCheckpointTransfer())
            ->setIdSearchRankingWeightCheckpoint(7)
            ->setSource('auto-tune')
            ->setRelevanceWeight(0.75)
            ->setSpecificityBlendWeight(0.7)
            ->setSpecificityCurveExponent(1.5)
            ->setSpecificityWeightExponent(2.0)
            ->setSpecificityWeightShiftMagnitude(0.25)
            ->setIsSpecificityWeightingEnabled(false)
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
            ->with(999, 'DE', 'de_DE', 1.0, SharedSearchRankingConfig::CHANGE_SOURCE_CHECKPOINT_RESTORE)
            ->willReturn(false);

        $recorderMock = $this->createMock(WeightCheckpointRecorderInterface::class);
        $recorderMock->method('record')->willReturn(new SearchRankingWeightCheckpointTransfer());

        $restorer = new WeightCheckpointRestorer($repositoryMock, $searchRankingFacadeMock, $recorderMock);

        // Act
        $result = $restorer->restore(7, 'DE', 'de_DE');

        // Assert
        $this->assertNotNull($result);
    }
}
