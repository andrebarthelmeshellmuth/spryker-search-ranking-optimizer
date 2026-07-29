<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Optimization\Algorithm;

use Codeception\Test\Unit;
use InvalidArgumentException;
use SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\CmaEsAlgorithm;
use SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\OptimizerAlgorithmInterface;

/**
 * Tests SHARED-layer code, placed under the Zed suite for the same reason as its sibling
 * DifferentialEvolutionAlgorithmTest -- no dedicated Shared suite exists in this package yet. Same
 * benchmark-function discipline: prove correctness against known optima BEFORE ever pointing this at the
 * real (and much more expensive to debug) rank_eval objective.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Optimization
 * @group CmaEsAlgorithmTest
 */
class CmaEsAlgorithmTest extends Unit
{
    /**
     * @return void
     */
    public function testImplementsTheGenericOptimizerAlgorithmInterface(): void
    {
        $this->assertInstanceOf(OptimizerAlgorithmInterface::class, new CmaEsAlgorithm());
    }

    /**
     * @return void
     */
    public function testOptimizeFindsTheKnownMinimumOfTheSphereFunction(): void
    {
        // Arrange
        $sphere = static function (array $vector): float {
            $sum = 0.0;

            foreach ($vector as $component) {
                $sum += $component ** 2;
            }

            return $sum;
        };

        $algorithm = new CmaEsAlgorithm();
        $algorithm->setCmaEsParameters(populationSize: 12, initialStepSize: 1.0, maxGenerations: 100);

        // Act
        $result = $algorithm->optimize($sphere, [-5.0, -5.0, -5.0], [5.0, 5.0, 5.0]);

        // Assert
        $this->assertLessThan(1e-4, $result->getBestValue(), 'CMA-ES should get very close to the sphere function\'s known minimum of 0.');

        foreach ($result->getBestVector() as $component) {
            $this->assertEqualsWithDelta(0.0, $component, 0.1, 'Each dimension should converge close to the known optimum at the origin.');
        }
    }

    /**
     * CMA-ES's whole reason for existing over simpler methods is navigating exactly this kind of narrow,
     * curved valley via its adapted covariance matrix -- a meaningfully stronger correctness check than
     * the sphere function alone.
     *
     * @return void
     */
    public function testOptimizeFindsTheKnownMinimumOfTheRosenbrockFunction(): void
    {
        // Arrange
        $rosenbrock = static function (array $vector): float {
            [$x, $y] = $vector;

            return (1 - $x) ** 2 + 100 * ($y - $x ** 2) ** 2;
        };

        $algorithm = new CmaEsAlgorithm();
        $algorithm->setCmaEsParameters(populationSize: 16, initialStepSize: 0.5, maxGenerations: 200);

        // Act
        $result = $algorithm->optimize($rosenbrock, [-3.0, -3.0], [3.0, 3.0]);

        // Assert
        $this->assertLessThan(0.05, $result->getBestValue(), 'CMA-ES should get close to the Rosenbrock function\'s known minimum of 0.');
        $this->assertEqualsWithDelta(1.0, $result->getBestVector()[0], 0.2, 'x should converge close to the known optimum at (1, 1).');
        $this->assertEqualsWithDelta(1.0, $result->getBestVector()[1], 0.2, 'y should converge close to the known optimum at (1, 1).');
    }

    /**
     * @return void
     */
    public function testOptimizeReportsAnEvaluationCountAndAMonotonicallyImprovingHistory(): void
    {
        // Arrange
        $sphere = static fn (array $vector): float => array_sum(array_map(fn (float $value): float => $value ** 2, $vector));

        $algorithm = new CmaEsAlgorithm();
        $algorithm->setCmaEsParameters(populationSize: 8, initialStepSize: 1.0, maxGenerations: 5);

        // Act
        $result = $algorithm->optimize($sphere, [-5.0], [5.0]);

        // Assert -- 5 generations * 8 candidates each, no separate initial-population evaluation batch
        // (unlike DE, CMA-ES's first generation IS the initial sample).
        $this->assertSame(40, $result->getEvaluationCount());
        $this->assertCount(5, $result->getBestValueHistory(), 'One history entry per generation.');

        $history = $result->getBestValueHistory();
        $historyCount = count($history);

        for ($i = 1; $i < $historyCount; $i++) {
            $this->assertLessThanOrEqual($history[$i - 1], $history[$i], 'The best-found value must never get worse from one generation to the next.');
        }
    }

    /**
     * @return void
     */
    public function testOptimizeUsesTheMidpointOfFiniteBoundsAsTheDefaultInitialMean(): void
    {
        // Arrange -- a sphere function shifted so its minimum is exactly at the bounds' midpoint (10, 10),
        // with a tiny population/generation budget: only a starting point already close to the optimum
        // could possibly get this close in so few evaluations, proving the midpoint default is honored.
        $shiftedSphere = static function (array $vector): float {
            return ($vector[0] - 10) ** 2 + ($vector[1] - 10) ** 2;
        };

        $algorithm = new CmaEsAlgorithm();
        $algorithm->setCmaEsParameters(populationSize: 4, initialStepSize: 0.1, maxGenerations: 1);

        // Act
        $result = $algorithm->optimize($shiftedSphere, [5.0, 5.0], [15.0, 15.0]);

        // Assert
        $this->assertLessThan(1.0, $result->getBestValue(), 'Starting from the bounds\' midpoint (10, 10), which is exactly the optimum, even one generation should land very close.');
    }

    /**
     * @return void
     */
    public function testOptimizeThrowsWhenABoundIsInfiniteAndNoInitialMeanWasGiven(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CmaEsAlgorithm())->optimize(static fn (array $vector): float => $vector[0] ** 2, [-INF], [INF]);
    }

    /**
     * @return void
     */
    public function testOptimizeAcceptsAnExplicitInitialMeanForAnUnboundedDimension(): void
    {
        // Arrange
        $sphere = static fn (array $vector): float => $vector[0] ** 2;

        $algorithm = new CmaEsAlgorithm();
        $algorithm->setCmaEsParameters(populationSize: 8, initialStepSize: 1.0, initialMean: [3.0], maxGenerations: 60);

        // Act
        $result = $algorithm->optimize($sphere, [-INF], [INF]);

        // Assert
        $this->assertLessThan(1e-3, $result->getBestValue());
    }

    /**
     * @return void
     */
    public function testSetCmaEsParametersRejectsATooSmallPopulation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CmaEsAlgorithm())->setCmaEsParameters(populationSize: 3);
    }
}
