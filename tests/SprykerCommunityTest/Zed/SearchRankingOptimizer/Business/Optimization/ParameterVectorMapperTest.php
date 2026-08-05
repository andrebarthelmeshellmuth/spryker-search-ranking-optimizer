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
     * @var float
     */
    protected const SPECIFICITY_WEIGHT_EXPONENT_AT_RUN_START = 1.0;

    /**
     * @var float
     */
    protected const SPECIFICITY_WEIGHT_SHIFT_MAGNITUDE_AT_RUN_START = 0.2;

    /**
     * @var float
     */
    protected const SPECIFICITY_BLEND_WEIGHT_AT_RUN_START = 0.7;

    public function testGetDimensionCountIsFourForZeroActiveMetrics(): void
    {
        $mapper = $this->buildMapper([]);

        $this->assertSame(4, $mapper->getDimensionCount(), '1 (relevanceWeight) + 0 free z values + 3 specificity dimensions.');
    }

    public function testGetDimensionCountIsFourForASingleActiveMetric(): void
    {
        $mapper = $this->buildMapper([['idSearchRankingMetric' => 1, 'name' => 'top_seller']]);

        $this->assertSame(4, $mapper->getDimensionCount(), '1 (relevanceWeight) + 0 free z values + 3 specificity dimensions.');
    }

    public function testGetDimensionCountIsRelevanceWeightPlusNMinusOneMetricsPlusThreeSpecificityDimensionsForNActiveMetrics(): void
    {
        $mapper = $this->buildMapper($this->buildThreeMetrics());

        $this->assertSame(6, $mapper->getDimensionCount(), '1 (relevanceWeight) + (3 - 1) free z values + 3 specificity dimensions.');
    }

    public function testBoundsClampTheRelevanceWeightTrustRegionToStayWithinZeroAndOne(): void
    {
        // Arrange -- a relevanceWeight of 0.05 with a trust region wider than 0.05 must clip the lower
        // bound at 0, not go negative.
        $mapper = $this->buildMapper([], [], 0.05);

        // Act & Assert
        $this->assertSame(0.0, $mapper->getLowerBounds()[0]);
        $this->assertGreaterThan(0.0, $mapper->getUpperBounds()[0]);
        $this->assertLessThanOrEqual(1.0, $mapper->getUpperBounds()[0]);
    }

    public function testFreeZDimensionsAreBoundedByTheConfiguredZSpaceBoundNotLiterallyInfinite(): void
    {
        // Arrange -- both CmaEsAlgorithm (needs a finite midpoint for its default initial mean) and
        // DifferentialEvolutionAlgorithm (samples its initial population uniformly WITHIN the given
        // bounds) need real, finite bounds to even start a run -- a real constraint, not a hypothetical one.
        $mapper = $this->buildMapper($this->buildThreeMetrics());
        $zSpaceBound = SearchRankingOptimizerConfig::getMetricWeightZSpaceBound();

        $lowerBounds = $mapper->getLowerBounds();
        $upperBounds = $mapper->getUpperBounds();

        $this->assertFalse(is_infinite($lowerBounds[1]));
        $this->assertFalse(is_infinite($upperBounds[1]));
        $this->assertSame(-$zSpaceBound, $lowerBounds[1]);
        $this->assertSame($zSpaceBound, $upperBounds[1]);
    }

    public function testSpecificityDimensionsAppearAsTheFinalThreeBoundsAfterTheMetricSimplexDimensions(): void
    {
        // Arrange -- 3 metrics => 2 free z dimensions, so the specificity triplet must start at index 3
        // (index 0 = relevanceWeight, indices 1-2 = free z).
        $mapper = $this->buildMapper($this->buildThreeMetrics());

        // Act
        $lowerBounds = $mapper->getLowerBounds();
        $upperBounds = $mapper->getUpperBounds();

        // Assert
        $this->assertCount(6, $lowerBounds);
        $this->assertCount(6, $upperBounds);

        $this->assertEqualsWithDelta(0.5, $lowerBounds[3], 1e-9, 'exponent trust region: 1.0 - 0.5');
        $this->assertEqualsWithDelta(1.5, $upperBounds[3], 1e-9, 'exponent trust region: 1.0 + 0.5');
        $this->assertEqualsWithDelta(0.1, $lowerBounds[4], 1e-9, 'shift magnitude trust region: 0.2 - 0.1');
        $this->assertEqualsWithDelta(0.3, $upperBounds[4], 1e-9, 'shift magnitude trust region: 0.2 + 0.1');
        $this->assertEqualsWithDelta(0.5, $lowerBounds[5], 1e-9, 'blend weight trust region: max(0, 0.7 - 0.2)');
        $this->assertEqualsWithDelta(0.9, $upperBounds[5], 1e-9, 'blend weight trust region: min(1, 0.7 + 0.2)');
    }

    /**
     * Proves `search-ranking`'s specificity-aware relevance weighting has no live effect at all when
     * disabled (see `RankEvalRunnerTest`'s own coverage of that gate), so spending any search budget on
     * these 3 dimensions in that case would be pure waste -- they must be omitted from the vector entirely,
     * not merely fixed like an excluded non-deterministic metric.
     */
    public function testSpecificityDimensionsAreOmittedEntirelyWhenSpecificityWeightingIsDisabled(): void
    {
        // Arrange
        $mapper = $this->buildMapper($this->buildThreeMetrics(), [], 0.75, false);

        // Act & Assert
        $this->assertSame(3, $mapper->getDimensionCount(), '1 (relevanceWeight) + (3 - 1) free z values + 0 specificity dimensions.');
        $this->assertCount(3, $mapper->getLowerBounds());
        $this->assertCount(3, $mapper->getUpperBounds());
    }

    public function testMapVectorToConfigurationCarriesTheAtRunStartSpecificityValuesUnchangedWhenDisabled(): void
    {
        // Arrange -- no specificity tail on this vector at all (3 metrics => 1 relevanceWeight + 2 free z).
        $mapper = $this->buildMapper($this->buildThreeMetrics(), [], 0.75, false);

        // Act
        $configurationTransfer = $mapper->mapVectorToConfiguration([0.6, 1.2, -0.5], 12.0);

        // Assert -- exactly the constants passed into buildMapper() as the "at run start" values.
        $this->assertSame(static::SPECIFICITY_WEIGHT_EXPONENT_AT_RUN_START, $configurationTransfer->getSpecificityWeightExponent());
        $this->assertSame(static::SPECIFICITY_WEIGHT_SHIFT_MAGNITUDE_AT_RUN_START, $configurationTransfer->getSpecificityWeightShiftMagnitude());
        $this->assertSame(static::SPECIFICITY_BLEND_WEIGHT_AT_RUN_START, $configurationTransfer->getSpecificityBlendWeight());
    }

    public function testMapConfigurationToVectorOmitsTheSpecificityTailWhenDisabled(): void
    {
        // Arrange
        $mapper = $this->buildMapper($this->buildThreeMetrics(), [], 0.75, false);
        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.75)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['top_seller' => 0.4, 'pdp_impressions' => 0.3, 'random' => 0.3])
            ->setSpecificityWeightExponent(1.0)
            ->setSpecificityWeightShiftMagnitude(0.2)
            ->setSpecificityBlendWeight(0.7);

        // Act
        $vector = $mapper->mapConfigurationToVector($configurationTransfer);

        // Assert -- 1 (relevanceWeight) + 2 free z, no specificity tail, regardless of what the transfer carries.
        $this->assertCount(3, $vector);
    }

    public function testMapVectorToConfigurationProducesMetricWeightsSummingToOneKeyedByName(): void
    {
        // Arrange
        $mapper = $this->buildMapper($this->buildThreeMetrics());

        // Act
        $configurationTransfer = $mapper->mapVectorToConfiguration([0.6, 1.2, -0.5, 1.0, 0.2, 0.7], 12.0);

        // Assert
        $this->assertSame(0.6, $configurationTransfer->getRelevanceWeight());
        $this->assertSame(12.0, $configurationTransfer->getRelevanceSaturationPoint());
        $this->assertSame(1.0, $configurationTransfer->getSpecificityWeightExponent());
        $this->assertSame(0.2, $configurationTransfer->getSpecificityWeightShiftMagnitude());
        $this->assertSame(0.7, $configurationTransfer->getSpecificityBlendWeight());

        $metricWeights = $configurationTransfer->getMetricWeights();
        $this->assertEqualsWithDelta(1.0, array_sum($metricWeights), 1e-9);
        $this->assertEqualsCanonicalizing(['pdp_impressions', 'top_seller', 'random'], array_keys($metricWeights));

        foreach ($metricWeights as $weight) {
            $this->assertGreaterThan(0.0, $weight);
        }
    }

    public function testMapVectorToConfigurationClampsSpecificityBlendWeightToItsOwnAbsoluteBounds(): void
    {
        // Arrange -- specificityBlendWeight's trust region [0.5, 0.9] (see
        // testSpecificityDimensionsAppearAsTheFinalThreeBoundsAfterTheMetricSimplexDimensions), unlike the
        // old entropyProbeResultSize this replaces, it is a plain continuous float bounded to [0;1] overall
        // -- no integer rounding involved.
        $mapper = $this->buildMapper([]);

        // Act
        $clampedHigh = $mapper->mapVectorToConfiguration([0.75, 1.0, 0.2, 100.0], 12.0);
        $clampedLow = $mapper->mapVectorToConfiguration([0.75, 1.0, 0.2, -100.0], 12.0);

        // Assert
        $this->assertEqualsWithDelta(0.9, $clampedHigh->getSpecificityBlendWeight(), 1e-9, 'clamped to this run\'s own trust-region upper bound of 0.7 + 0.2');
        $this->assertEqualsWithDelta(0.5, $clampedLow->getSpecificityBlendWeight(), 1e-9, 'clamped to this run\'s own trust-region lower bound of 0.7 - 0.2');
    }

    public function testMapVectorToConfigurationClampsRelevanceWeightToItsOwnTrustRegionBounds(): void
    {
        // Arrange -- default relevanceWeightAtRunStart=0.75, trust region max distance 0.15 => [0.6, 0.9].
        // Every shipped algorithm already clamps its own candidates to exactly this range before this
        // method ever sees them (see AbstractOptimizerAlgorithm::clamp()), so this is a defensive second
        // line, not the primary enforcement -- an out-of-range value here would otherwise flow straight
        // into a persisted (and potentially live-applied) configuration unclamped.
        $mapper = $this->buildMapper([]);

        // Act
        $clampedHigh = $mapper->mapVectorToConfiguration([5.0, 1.0, 0.2, 0.7], 12.0);
        $clampedLow = $mapper->mapVectorToConfiguration([-5.0, 1.0, 0.2, 0.7], 12.0);

        // Assert
        $this->assertSame(0.9, $clampedHigh->getRelevanceWeight());
        $this->assertSame(0.6, $clampedLow->getRelevanceWeight());
    }

    public function testMapVectorToConfigurationClampsSpecificityWeightExponentAndShiftMagnitudeToTheirOwnTrustRegionBounds(): void
    {
        // Arrange -- exponent trust region [0.5, 1.5], shift magnitude trust region [0.1, 0.3] (see
        // testSpecificityDimensionsAppearAsTheFinalThreeBoundsAfterTheMetricSimplexDimensions).
        $mapper = $this->buildMapper([]);

        // Act
        $clampedHigh = $mapper->mapVectorToConfiguration([0.75, 99.0, 99.0, 0.7], 12.0);
        $clampedLow = $mapper->mapVectorToConfiguration([0.75, -99.0, -99.0, 0.7], 12.0);

        // Assert
        $this->assertEqualsWithDelta(1.5, $clampedHigh->getSpecificityWeightExponent(), 1e-9);
        $this->assertEqualsWithDelta(0.3, $clampedHigh->getSpecificityWeightShiftMagnitude(), 1e-9);
        $this->assertEqualsWithDelta(0.5, $clampedLow->getSpecificityWeightExponent(), 1e-9);
        $this->assertEqualsWithDelta(0.1, $clampedLow->getSpecificityWeightShiftMagnitude(), 1e-9);
    }

    public function testMapVectorToConfigurationGivesTheSingleMetricWeightOneExactly(): void
    {
        // Arrange
        $mapper = $this->buildMapper([['idSearchRankingMetric' => 1, 'name' => 'top_seller']]);

        // Act
        $configurationTransfer = $mapper->mapVectorToConfiguration([0.75, 1.0, 0.2, 0.7], 12.0);

        // Assert
        $this->assertSame(['top_seller' => 1.0], $configurationTransfer->getMetricWeights());
    }

    public function testMapVectorToConfigurationProducesNoMetricWeightsAtAllForZeroActiveMetrics(): void
    {
        // Arrange
        $mapper = $this->buildMapper([]);

        // Act
        $configurationTransfer = $mapper->mapVectorToConfiguration([0.75, 1.0, 0.2, 0.7], 12.0);

        // Assert
        $this->assertSame([], $configurationTransfer->getMetricWeights());
    }

    public function testMapConfigurationToVectorIsTheInverseOfMapVectorToConfiguration(): void
    {
        // Arrange
        $mapper = $this->buildMapper($this->buildThreeMetrics());
        $originalVector = [0.65, 0.8, -1.3, 1.0, 0.2, 0.7];

        // Act
        $configurationTransfer = $mapper->mapVectorToConfiguration($originalVector, 12.0);
        $roundTrippedVector = $mapper->mapConfigurationToVector($configurationTransfer);

        // Assert
        $this->assertEqualsWithDelta($originalVector, $roundTrippedVector, 1e-9);
    }

    public function testMapConfigurationToVectorDefaultsAMissingMetricWeightToZero(): void
    {
        // Arrange -- a live configuration that predates one of the currently-active metrics being added
        // (a real, plausible state: a metric can be activated after the last publish).
        $mapper = $this->buildMapper($this->buildThreeMetrics());
        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.75)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['top_seller' => 1.0]);

        // Act
        $vector = $mapper->mapConfigurationToVector($configurationTransfer);

        // Assert -- must not throw, and must produce a finite, usable vector.
        $this->assertCount(6, $vector);

        foreach ($vector as $value) {
            $this->assertFalse(is_nan($value));
            $this->assertFalse(is_infinite($value));
        }
    }

    public function testMapConfigurationToVectorFallsBackToSensibleSpecificityDefaultsWhenUnset(): void
    {
        // Arrange -- specificity fields are only ever populated once search-ranking's specificity
        // weighting feature has actually been configured; an older/never-touched live configuration may
        // still carry nulls for all 3.
        $mapper = $this->buildMapper([]);
        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.75)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights([]);

        // Act
        $vector = $mapper->mapConfigurationToVector($configurationTransfer);

        // Assert
        $this->assertEqualsWithDelta([0.75, 1.0, 0.0, 0.7], $vector, 1e-9);
    }

    public function testMapVectorToConfigurationHoldsAFixedMetricWeightConstantAndScalesTheOptimizableSimplexToFillTheRemainingBudget(): void
    {
        // Arrange -- "random" is excluded from the search (e.g. a non-deterministic formula) and pinned at
        // its live weight of 0.4; only top_seller/pdp_impressions are optimizable, and together must fill
        // exactly the remaining 0.6 budget, not the full [0;1] range.
        $optimizableMetrics = [
            ['idSearchRankingMetric' => 1, 'name' => 'top_seller'],
            ['idSearchRankingMetric' => 2, 'name' => 'pdp_impressions'],
        ];
        $mapper = $this->buildMapper($optimizableMetrics, ['random' => 0.4]);

        // Act -- all-zero z's produce a uniform split between the two optimizable metrics.
        $configurationTransfer = $mapper->mapVectorToConfiguration([0.75, 0.0, 1.0, 0.2, 0.7], 12.0);

        // Assert
        $metricWeights = $configurationTransfer->getMetricWeights();
        $this->assertEqualsWithDelta(1.0, array_sum($metricWeights), 1e-9, 'The full set (optimizable + fixed) must still sum to 1.');
        $this->assertSame(0.4, $metricWeights['random'], "The fixed metric's weight must be exactly what was passed in, untouched.");
        $this->assertEqualsWithDelta(0.3, $metricWeights['top_seller'], 1e-9, 'Each optimizable metric gets half of the remaining 0.6 budget.');
        $this->assertEqualsWithDelta(0.3, $metricWeights['pdp_impressions'], 1e-9);
    }

    /**
     * Guards against the fixed metrics' OWN weights alone already exceeding the full [0;1] budget (live
     * weights that were never renormalized, or floating-point drift across many small weights) -- every
     * optimizable metric correctly gets zero either way, but the fixed weights themselves must be
     * rescaled down to sum to exactly 1, not left silently summing to more than that.
     */
    public function testMapVectorToConfigurationRescalesFixedWeightsDownWhenTheyAloneExceedTheFullBudget(): void
    {
        // Arrange -- two fixed metrics summing to 1.2 on their own, well over the [0;1] budget.
        $optimizableMetrics = [
            ['idSearchRankingMetric' => 1, 'name' => 'top_seller'],
        ];
        $mapper = $this->buildMapper($optimizableMetrics, ['random' => 0.7, 'other_random' => 0.5]);

        // Act
        $configurationTransfer = $mapper->mapVectorToConfiguration([0.75, 1.0, 0.2, 0.7], 12.0);

        // Assert
        $metricWeights = $configurationTransfer->getMetricWeights();
        $this->assertEqualsWithDelta(1.0, array_sum($metricWeights), 1e-9, 'The full set must still sum to exactly 1, even when the fixed weights alone exceeded it.');
        $this->assertSame(0.0, $metricWeights['top_seller'], 'The optimizable metric gets zero -- no budget is left for it.');
        $this->assertEqualsWithDelta(0.7 / 1.2, $metricWeights['random'], 1e-9, 'Rescaled proportionally, not left at its raw 0.7.');
        $this->assertEqualsWithDelta(0.5 / 1.2, $metricWeights['other_random'], 1e-9);
    }

    public function testMapVectorToConfigurationGivesTheSingleOptimizableMetricTheFullRemainingBudget(): void
    {
        // Arrange
        $mapper = $this->buildMapper(
            [['idSearchRankingMetric' => 1, 'name' => 'top_seller']],
            ['random' => 0.4],
        );

        // Act
        $configurationTransfer = $mapper->mapVectorToConfiguration([0.75, 1.0, 0.2, 0.7], 12.0);

        // Assert
        $this->assertSame(['random' => 0.4, 'top_seller' => 0.6], $configurationTransfer->getMetricWeights());
    }

    public function testMapVectorToConfigurationReturnsOnlyTheFixedWeightsWhenNothingIsOptimizable(): void
    {
        // Arrange -- every active metric turned out non-deterministic; there's nothing left to search.
        $mapper = $this->buildMapper([], ['random' => 1.0]);

        // Act
        $configurationTransfer = $mapper->mapVectorToConfiguration([0.75, 1.0, 0.2, 0.7], 12.0);

        // Assert
        $this->assertSame(['random' => 1.0], $configurationTransfer->getMetricWeights());
    }

    /**
     * @param array<int, array{idSearchRankingMetric: int, name: string}> $metrics
     * @param array<string, float> $fixedMetricWeights
     * @param float $relevanceWeightAtRunStart
     * @param bool $specificityWeightingEnabled
     */
    protected function buildMapper(
        array $metrics,
        array $fixedMetricWeights = [],
        float $relevanceWeightAtRunStart = 0.75,
        bool $specificityWeightingEnabled = true,
    ): ParameterVectorMapper {
        return new ParameterVectorMapper(
            $metrics,
            $fixedMetricWeights,
            $relevanceWeightAtRunStart,
            static::SPECIFICITY_WEIGHT_EXPONENT_AT_RUN_START,
            static::SPECIFICITY_WEIGHT_SHIFT_MAGNITUDE_AT_RUN_START,
            static::SPECIFICITY_BLEND_WEIGHT_AT_RUN_START,
            $specificityWeightingEnabled,
        );
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
