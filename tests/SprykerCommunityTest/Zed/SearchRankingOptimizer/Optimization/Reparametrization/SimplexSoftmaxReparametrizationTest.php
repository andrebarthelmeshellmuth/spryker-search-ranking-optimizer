<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Optimization\Reparametrization;

use Codeception\Test\Unit;
use InvalidArgumentException;
use SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Reparametrization\SimplexSoftmaxReparametrization;

/**
 * Tests SHARED-layer code, placed under the Zed suite for the same reason as its sibling algorithm tests
 * -- no dedicated Shared suite exists in this package yet.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Optimization
 * @group SimplexSoftmaxReparametrizationTest
 * @group Portable
 */
class SimplexSoftmaxReparametrizationTest extends Unit
{
    public function testToSimplexAlwaysSumsToOne(): void
    {
        // Arrange
        $reparametrization = new SimplexSoftmaxReparametrization();

        // Act
        $weights = $reparametrization->toSimplex([2.3, -1.7, 0.4]);

        // Assert
        $this->assertEqualsWithDelta(1.0, array_sum($weights), 1e-9);
        $this->assertCount(4, $weights, 'n-1 free z values must produce n weights (the pinned z_0 adds one).');
    }

    public function testToSimplexAlwaysProducesStrictlyPositiveWeights(): void
    {
        // Arrange
        $reparametrization = new SimplexSoftmaxReparametrization();

        // Act -- deliberately wide-spread z values, to check the numerically-stable softmax never
        // overflows to INF/NaN. Not pushed all the way to +-500: exp(-1000) genuinely underflows to a
        // literal 0.0 in IEEE double precision regardless of the max-subtraction trick -- that's a real
        // floating-point limit, not a bug, so this stays within the range doubles can represent.
        $weights = $reparametrization->toSimplex([30.0, -30.0, 0.0]);

        // Assert
        foreach ($weights as $weight) {
            $this->assertGreaterThan(0.0, $weight);
            $this->assertIsFloat($weight);
            $this->assertFalse(is_nan($weight));
            $this->assertFalse(is_infinite($weight));
        }

        $this->assertEqualsWithDelta(1.0, array_sum($weights), 1e-9);
    }

    public function testAllZeroZProducesUniformWeights(): void
    {
        // Arrange
        $reparametrization = new SimplexSoftmaxReparametrization();

        // Act
        $weights = $reparametrization->toSimplex([0.0, 0.0, 0.0]);

        // Assert -- 4 weights (pinned z_0 + 3 free), all z's equal -> uniform 1/4 each.
        foreach ($weights as $weight) {
            $this->assertEqualsWithDelta(0.25, $weight, 1e-9);
        }
    }

    public function testToFreeZIsTheInverseOfToSimplex(): void
    {
        // Arrange
        $reparametrization = new SimplexSoftmaxReparametrization();
        $originalWeights = [0.5, 0.2, 0.2, 0.1];

        // Act
        $freeZ = $reparametrization->toFreeZ($originalWeights);
        $roundTrippedWeights = $reparametrization->toSimplex($freeZ);

        // Assert
        $this->assertCount(3, $freeZ, 'n weights must produce n-1 free z values.');
        $this->assertEqualsWithDelta($originalWeights, $roundTrippedWeights, 1e-9);
    }

    public function testToFreeZHandlesAZeroWeightWithoutBlowingUp(): void
    {
        // Arrange -- a metric configured with zero weight is a real, valid state (an active-but-currently-
        // unweighted metric), must not produce -INF/NaN.
        $reparametrization = new SimplexSoftmaxReparametrization();

        // Act
        $freeZ = $reparametrization->toFreeZ([0.6, 0.0, 0.4]);

        // Assert
        foreach ($freeZ as $z) {
            $this->assertFalse(is_nan($z));
            $this->assertFalse(is_infinite($z));
        }

        $roundTrippedWeights = $reparametrization->toSimplex($freeZ);
        $this->assertEqualsWithDelta(1.0, array_sum($roundTrippedWeights), 1e-9);
    }

    /**
     * A single metric has no real degrees of freedom at all -- its weight must always be exactly 1.
     */
    public function testASingleWeightWithNoFreeZAlwaysProducesWeightOne(): void
    {
        // Arrange
        $reparametrization = new SimplexSoftmaxReparametrization();

        // Act
        $weights = $reparametrization->toSimplex([]);

        // Assert
        $this->assertSame([1.0], $weights);
    }

    public function testToFreeZThrowsForAnEmptyWeightsArray(): void
    {
        // Arrange
        $reparametrization = new SimplexSoftmaxReparametrization();

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('weights must contain at least one entry.');

        // Act
        $reparametrization->toFreeZ([]);
    }
}
