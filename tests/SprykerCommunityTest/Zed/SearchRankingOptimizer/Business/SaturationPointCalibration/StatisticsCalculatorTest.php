<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\StatisticsCalculator;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group@group SearchRankingOptimizer
 * @group Business
 * @group SaturationPointCalibration
 * @group StatisticsCalculatorTest
 * Add your own group annotations below this line
 * @group Portable
 */
class StatisticsCalculatorTest extends Unit
{
    public function testComputesMinMaxMeanAndSampleCount(): void
    {
        // Arrange
        $calculator = new StatisticsCalculator();

        // Act
        $statisticsTransfer = $calculator->calculate([10.0, 20.0, 30.0, 40.0]);

        // Assert
        $this->assertSame(4, $statisticsTransfer->getSampleCount());
        $this->assertSame(10.0, $statisticsTransfer->getValueMin());
        $this->assertSame(40.0, $statisticsTransfer->getValueMax());
        $this->assertSame(25.0, $statisticsTransfer->getValueMean());
        $this->assertSame(25.0, $statisticsTransfer->getComputedK());
    }

    /**
     * computedK is defined as the pooled mean — must always equal valueMean exactly, not merely be close.
     */
    public function testComputedKIsAlwaysExactlyTheMean(): void
    {
        // Arrange
        $calculator = new StatisticsCalculator();

        // Act
        $statisticsTransfer = $calculator->calculate([3.0, 4.0, 5.0]);

        // Assert
        $this->assertSame($statisticsTransfer->getValueMean(), $statisticsTransfer->getComputedK());
    }

    /**
     * A single-element sample has no interpolation range — every statistic collapses onto that one value.
     */
    public function testASingleScoreMakesEveryStatisticEqualToItself(): void
    {
        // Arrange
        $calculator = new StatisticsCalculator();

        // Act
        $statisticsTransfer = $calculator->calculate([7.5]);

        // Assert
        $this->assertSame(1, $statisticsTransfer->getSampleCount());
        $this->assertSame(7.5, $statisticsTransfer->getValueMin());
        $this->assertSame(7.5, $statisticsTransfer->getValueMax());
        $this->assertSame(7.5, $statisticsTransfer->getValueMedian());
        $this->assertSame(7.5, $statisticsTransfer->getValueP25());
        $this->assertSame(7.5, $statisticsTransfer->getValueP75());
    }

    /**
     * Verifies the linear-interpolation percentile method against a hand-computed reference: for
     * [10, 20, 30, 40, 50], p25 sits a quarter of the way from index 1 (20) to index 2 (30) — rank =
     * 0.25 * 4 = 1.0, exactly on index 1 — so p25 = 20 exactly; p75 lands exactly on index 3 = 40 the
     * same way. Median (p50) is the exact middle element, 30.
     */
    public function testPercentilesMatchLinearInterpolationOnAKnownDataset(): void
    {
        // Arrange
        $calculator = new StatisticsCalculator();

        // Act
        $statisticsTransfer = $calculator->calculate([50.0, 10.0, 30.0, 40.0, 20.0]);

        // Assert
        $this->assertSame(20.0, $statisticsTransfer->getValueP25());
        $this->assertSame(30.0, $statisticsTransfer->getValueMedian());
        $this->assertSame(40.0, $statisticsTransfer->getValueP75());
    }

    /**
     * Scores must be sorted internally before pooling — passing them in scrambled order must not affect
     * any statistic.
     */
    public function testResultIsIndependentOfInputOrder(): void
    {
        // Arrange
        $calculator = new StatisticsCalculator();

        // Act
        $sortedResult = $calculator->calculate([1.0, 2.0, 3.0, 4.0, 5.0]);
        $scrambledResult = $calculator->calculate([3.0, 1.0, 5.0, 2.0, 4.0]);

        // Assert
        $this->assertSame($sortedResult->getValueMin(), $scrambledResult->getValueMin());
        $this->assertSame($sortedResult->getValueMax(), $scrambledResult->getValueMax());
        $this->assertSame($sortedResult->getValueMedian(), $scrambledResult->getValueMedian());
        $this->assertSame($sortedResult->getValueP25(), $scrambledResult->getValueP25());
        $this->assertSame($sortedResult->getValueP75(), $scrambledResult->getValueP75());
    }
}
