<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Checkpoint;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorder;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Checkpoint
 * @group WeightCheckpointRecorderTest
 * Add your own group annotations below this line
 */
class WeightCheckpointRecorderTest extends Unit
{
    public function testRecordReadsCurrentStateFromSearchRankingFacadeAndPersistsIt(): void
    {
        // Arrange
        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('getRelevanceWeight')->willReturn(0.75);
        $searchRankingFacadeMock->method('getSpecificityBlendWeight')->willReturn(0.7);
        $searchRankingFacadeMock->method('getSpecificityWeightExponent')->willReturn(2.0);
        $searchRankingFacadeMock->method('getSpecificityWeightShiftMagnitude')->willReturn(0.25);
        $searchRankingFacadeMock->method('isSpecificityWeightingEnabled')->willReturn(false);
        $searchRankingFacadeMock->method('getMetricWeights')->willReturn([
            ['idSearchRankingMetric' => 1, 'name' => 'sales', 'weight' => 0.4],
            ['idSearchRankingMetric' => 2, 'name' => 'margin', 'weight' => 0.6],
        ]);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())
            ->method('createWeightCheckpoint')
            ->with($this->callback(fn (SearchRankingWeightCheckpointTransfer $weightCheckpointTransfer): bool => $weightCheckpointTransfer->getSource() === 'manual'
                && $weightCheckpointTransfer->getStoreName() === 'DE'
                && $weightCheckpointTransfer->getLocaleName() === 'de_DE'
                && $weightCheckpointTransfer->getRelevanceWeight() === 0.75
                && $weightCheckpointTransfer->getSpecificityBlendWeight() === 0.7
                && $weightCheckpointTransfer->getSpecificityWeightExponent() === 2.0
                && $weightCheckpointTransfer->getSpecificityWeightShiftMagnitude() === 0.25
                && $weightCheckpointTransfer->getIsSpecificityWeightingEnabled() === false
                && count($weightCheckpointTransfer->getMetricWeights()) === 2))
            ->willReturnArgument(0);

        $recorder = new WeightCheckpointRecorder($searchRankingFacadeMock, $entityManagerMock);

        // Act
        $result = $recorder->record('manual', 'DE', 'de_DE');

        // Assert
        $this->assertSame('manual', $result->getSource());
        $this->assertSame(0.75, $result->getRelevanceWeight());
    }
}
