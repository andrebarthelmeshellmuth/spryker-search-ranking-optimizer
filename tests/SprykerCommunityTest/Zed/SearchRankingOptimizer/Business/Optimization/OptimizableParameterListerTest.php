<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Optimization;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismChecker;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizableParameterLister;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Optimization
 * @group OptimizableParameterListerTest
 */
class OptimizableParameterListerTest extends Unit
{
    public function testListReturnsTheFiveCurrentLiveScalarValues(): void
    {
        // Arrange
        $searchRankingFacadeMock = $this->createBasicSearchRankingFacadeMock();

        $lister = new OptimizableParameterLister($searchRankingFacadeMock, new FormulaDeterminismChecker());

        // Act
        $result = $lister->list('DE', 'en_US');

        // Assert
        $this->assertSame(0.75, $result['relevanceWeight']);
        $this->assertTrue($result['isSpecificityWeightingEnabled']);
        $this->assertSame(1.5, $result['specificityCurveExponent']);
        $this->assertSame(1.2, $result['specificityWeightExponent']);
        $this->assertSame(0.25, $result['specificityWeightShiftMagnitude']);
        $this->assertSame(0.7, $result['specificityBlendWeight']);
    }

    public function testListReturnsEveryActiveMetricWithItsCurrentWeight(): void
    {
        // Arrange
        $searchRankingFacadeMock = $this->createBasicSearchRankingFacadeMock();

        $lister = new OptimizableParameterLister($searchRankingFacadeMock, new FormulaDeterminismChecker());

        // Act
        $result = $lister->list('DE', 'en_US');

        // Assert
        $weightsByName = [];

        foreach ($result['metrics'] as $metric) {
            $weightsByName[$metric['name']] = $metric['weight'];
        }

        $this->assertSame(['top_seller' => 0.6, 'pdp_impressions' => 0.4], $weightsByName);
    }

    /**
     * The whole reason a human is shown this checklist is to choose what to search vs. pin -- a metric
     * whose formula is non-deterministic is held fixed unconditionally by OptimizationRunner regardless of
     * any checklist choice, so listing it here would only be a dead, uncheckable row, not a real option.
     */
    public function testListOmitsAMetricWithANonDeterministicFormulaEntirely(): void
    {
        // Arrange
        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('getActiveMetrics')->willReturn([
            ['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'isLocaleScoped' => true],
            ['idSearchRankingMetric' => 2, 'name' => 'random', 'isLocaleScoped' => true],
        ]);
        $searchRankingFacadeMock->method('getConfiguration')->willReturn(
            $this->createConfigurationTransfer(['top_seller' => 0.8, 'random' => 0.2]),
        );
        $searchRankingFacadeMock->method('findMetricDetail')->willReturnMap([
            [1, 'DE', 'en_US', ['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'formula' => 'x / max', 'isHigherBetter' => true, 'shape' => 'linear']],
            [2, 'DE', 'en_US', ['idSearchRankingMetric' => 2, 'name' => 'random', 'formula' => 'random()', 'isHigherBetter' => true, 'shape' => null]],
        ]);
        $searchRankingFacadeMock->method('isSpecificityWeightingEnabled')->willReturn(false);

        $lister = new OptimizableParameterLister($searchRankingFacadeMock, new FormulaDeterminismChecker());

        // Act
        $result = $lister->list('DE', 'en_US');

        // Assert
        $this->assertSame(['top_seller'], array_column($result['metrics'], 'name'));
    }

    /**
     * A metric missing its own findMetricDetail() row is treated as deterministic -- same fail-open
     * posture OptimizationRunner's own splitMetricsByDeterminism() takes toward a metric deleted mid-run.
     */
    public function testListTreatsAMetricWithNoDetailRowAsDeterministic(): void
    {
        // Arrange
        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('getActiveMetrics')->willReturn([
            ['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'isLocaleScoped' => true],
        ]);
        $searchRankingFacadeMock->method('getConfiguration')->willReturn(
            $this->createConfigurationTransfer(['top_seller' => 1.0]),
        );
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn(null);
        $searchRankingFacadeMock->method('isSpecificityWeightingEnabled')->willReturn(false);

        $lister = new OptimizableParameterLister($searchRankingFacadeMock, new FormulaDeterminismChecker());

        // Act
        $result = $lister->list('DE', 'en_US');

        // Assert
        $this->assertSame(['top_seller'], array_column($result['metrics'], 'name'));
    }

    protected function createBasicSearchRankingFacadeMock(): SearchRankingOptimizerToSearchRankingFacadeInterface
    {
        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('getActiveMetrics')->willReturn([
            ['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'isLocaleScoped' => true],
            ['idSearchRankingMetric' => 2, 'name' => 'pdp_impressions', 'isLocaleScoped' => true],
        ]);
        $searchRankingFacadeMock->method('getConfiguration')->willReturn(
            $this->createConfigurationTransfer(['top_seller' => 0.6, 'pdp_impressions' => 0.4]),
        );
        $searchRankingFacadeMock->method('findMetricDetail')->willReturnMap([
            [1, 'DE', 'en_US', ['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'formula' => 'x / max', 'isHigherBetter' => true, 'shape' => 'linear']],
            [2, 'DE', 'en_US', ['idSearchRankingMetric' => 2, 'name' => 'pdp_impressions', 'formula' => 'atan(x / avg)', 'isHigherBetter' => true, 'shape' => 'atan']],
        ]);
        $searchRankingFacadeMock->method('isSpecificityWeightingEnabled')->willReturn(true);

        return $searchRankingFacadeMock;
    }

    /**
     * @param array<string, float> $metricWeights
     */
    protected function createConfigurationTransfer(array $metricWeights): SearchRankingConfigurationStorageTransfer
    {
        return (new SearchRankingConfigurationStorageTransfer())
            ->setMetricWeights($metricWeights)
            ->setRelevanceWeight(0.75)
            ->setRelevanceSaturationPoint(12.0)
            ->setSpecificitySaturationPoint(3.0)
            ->setSpecificityCurveExponent(1.5)
            ->setSpecificityWeightExponent(1.2)
            ->setSpecificityWeightShiftMagnitude(0.25)
            ->setSpecificityBlendWeight(0.7)
            ->setRandomMetricName('random');
    }
}
