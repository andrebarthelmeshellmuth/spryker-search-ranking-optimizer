<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Optimization;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\ParameterVectorMapper;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Optimization
 * @group ParameterVectorMapperTest
 */
class ParameterVectorMapperTest extends Unit
{
    /**
     * @return void
     */
    public function testGetDimensionCountIsOneForZeroActiveMetrics(): void
    {
        $mapper = new ParameterVectorMapper([], 0.75);

        $this->assertSame(1, $mapper->getDimensionCount());
    }

    /**
     * @return void
     */
    public function testGetDimensionCountIsOneForASingleActiveMetric(): void
    {
        $mapper = new ParameterVectorMapper([['idSearchRankingMetric' => 1, 'name' => 'top_seller']], 0.75);

        $this->assertSame(1, $mapper->getDimensionCount());
    }

    /**
     * @return void
     */
    public function testGetDimensionCountIsRelevanceWeightPlusNMinusOneMetricsForNActiveMetrics(): void
    {
        $mapper = new ParameterVectorMapper($this->buildThreeMetrics(), 0.75);

        $this->assertSame(3, $mapper->getDimensionCount(), '1 (relevanceWeight) + (3 - 1) free z values.');
    }

    /**
     * @return void
     */
    public function testBoundsClampTheRelevanceWeightTrustRegionToStayWithinZeroAndOne(): void
    {
        // Arrange -- a relevanceWeight of 0.05 with a trust region wider than 0.05 must clip the lower
        // bound at 0, not go negative.
        $mapper = new ParameterVectorMapper([], 0.05);

        // Act & Assert
        $this->assertSame(0.0, $mapper->getLowerBounds()[0]);
        $this->assertGreaterThan(0.0, $mapper->getUpperBounds()[0]);
        $this->assertLessThanOrEqual(1.0, $mapper->getUpperBounds()[0]);
    }

    /**
     * @return void
     */
    public function testFreeZDimensionsAreBoundedByTheConfiguredZSpaceBoundNotLiterallyInfinite(): void
    {
        // Arrange -- both CmaEsAlgorithm (needs a finite midpoint for its default initial mean) and
        // DifferentialEvolutionAlgorithm (samples its initial population uniformly WITHIN the given
        // bounds) need real, finite bounds to even start a run -- confirmed live.
        $mapper = new ParameterVectorMapper($this->buildThreeMetrics(), 0.75);
        $zSpaceBound = SearchRankingOptimizerConfig::getMetricWeightZSpaceBound();

        $lowerBounds = $mapper->getLowerBounds();
        $upperBounds = $mapper->getUpperBounds();

        $this->assertFalse(is_infinite($lowerBounds[1]));
        $this->assertFalse(is_infinite($upperBounds[1]));
        $this->assertSame(-$zSpaceBound, $lowerBounds[1]);
        $this->assertSame($zSpaceBound, $upperBounds[1]);
    }

    /**
     * @return void
     */
    public function testMapVectorToConfigurationProducesMetricWeightsSummingToOneKeyedByName(): void
    {
        // Arrange
        $mapper = new ParameterVectorMapper($this->buildThreeMetrics(), 0.75);

        // Act
        $configurationTransfer = $mapper->mapVectorToConfiguration([0.6, 1.2, -0.5], 12.0);

        // Assert
        $this->assertSame(0.6, $configurationTransfer->getRelevanceWeight());
        $this->assertSame(12.0, $configurationTransfer->getRelevanceSaturationPoint());

        $metricWeights = $configurationTransfer->getMetricWeights();
        $this->assertEqualsWithDelta(1.0, array_sum($metricWeights), 1e-9);
        $this->assertEqualsCanonicalizing(['pdp_impressions', 'top_seller', 'random'], array_keys($metricWeights));

        foreach ($metricWeights as $weight) {
            $this->assertGreaterThan(0.0, $weight);
        }
    }

    /**
     * @return void
     */
    public function testMapVectorToConfigurationGivesTheSingleMetricWeightOneExactly(): void
    {
        // Arrange
        $mapper = new ParameterVectorMapper([['idSearchRankingMetric' => 1, 'name' => 'top_seller']], 0.75);

        // Act
        $configurationTransfer = $mapper->mapVectorToConfiguration([0.75], 12.0);

        // Assert
        $this->assertSame(['top_seller' => 1.0], $configurationTransfer->getMetricWeights());
    }

    /**
     * @return void
     */
    public function testMapVectorToConfigurationProducesNoMetricWeightsAtAllForZeroActiveMetrics(): void
    {
        // Arrange
        $mapper = new ParameterVectorMapper([], 0.75);

        // Act
        $configurationTransfer = $mapper->mapVectorToConfiguration([0.75], 12.0);

        // Assert
        $this->assertSame([], $configurationTransfer->getMetricWeights());
    }

    /**
     * @return void
     */
    public function testMapConfigurationToVectorIsTheInverseOfMapVectorToConfiguration(): void
    {
        // Arrange
        $mapper = new ParameterVectorMapper($this->buildThreeMetrics(), 0.75);
        $originalVector = [0.65, 0.8, -1.3];

        // Act
        $configurationTransfer = $mapper->mapVectorToConfiguration($originalVector, 12.0);
        $roundTrippedVector = $mapper->mapConfigurationToVector($configurationTransfer);

        // Assert
        $this->assertEqualsWithDelta($originalVector, $roundTrippedVector, 1e-9);
    }

    /**
     * @return void
     */
    public function testMapConfigurationToVectorDefaultsAMissingMetricWeightToZero(): void
    {
        // Arrange -- a live configuration that predates one of the currently-active metrics being added
        // (a real, plausible state: a metric can be activated after the last publish).
        $mapper = new ParameterVectorMapper($this->buildThreeMetrics(), 0.75);
        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.75)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['top_seller' => 1.0]);

        // Act
        $vector = $mapper->mapConfigurationToVector($configurationTransfer);

        // Assert -- must not throw, and must produce a finite, usable vector.
        $this->assertCount(3, $vector);

        foreach ($vector as $value) {
            $this->assertFalse(is_nan($value));
            $this->assertFalse(is_infinite($value));
        }
    }

    /**
     * @return array<int, array{idSearchRankingMetric: int, name: string}>
     */
    protected function buildThreeMetrics(): array
    {
        return [
            ['idSearchRankingMetric' => 1, 'name' => 'top_seller'],
            ['idSearchRankingMetric' => 2, 'name' => 'pdp_impressions'],
            ['idSearchRankingMetric' => 3, 'name' => 'random'],
        ];
    }
}
