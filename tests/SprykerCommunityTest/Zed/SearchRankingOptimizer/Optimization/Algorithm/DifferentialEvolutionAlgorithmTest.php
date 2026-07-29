<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Optimization\Algorithm;

use Codeception\Test\Unit;
use InvalidArgumentException;
use SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\DifferentialEvolutionAlgorithm;
use SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\OptimizerAlgorithmInterface;

/**
 * Tests SHARED-layer, framework-agnostic code (SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\*)
 * -- placed under the Zed suite only because this package has no dedicated Shared-layer test suite yet;
 * nothing here touches Zed at all. Validates against known toy benchmark functions (sphere, Rosenbrock)
 * with known optima, per this package's own decision to prove any optimizer implementation correct BEFORE
 * ever pointing it at the real (and much more expensive to debug) rank_eval objective.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Optimization
 * @group DifferentialEvolutionAlgorithmTest
 */
class DifferentialEvolutionAlgorithmTest extends Unit
{
    /**
     * @return void
     */
    public function testImplementsTheGenericOptimizerAlgorithmInterface(): void
    {
        $this->assertInstanceOf(OptimizerAlgorithmInterface::class, new DifferentialEvolutionAlgorithm());
    }

    /**
     * The n-dimensional sphere function f(x) = sum(x_i^2) has a single global minimum of 0 at the origin
     * -- the simplest possible convex benchmark, good for a basic sanity check.
     *
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

        $algorithm = new DifferentialEvolutionAlgorithm();
        $algorithm->setDifferentialEvolutionParameters(populationSize: 30, maxGenerations: 150);

        // Act
        $result = $algorithm->optimize($sphere, [-5.0, -5.0, -5.0], [5.0, 5.0, 5.0]);

        // Assert
        $this->assertLessThan(1e-4, $result->getBestValue(), 'DE should get very close to the sphere function\'s known minimum of 0.');

        foreach ($result->getBestVector() as $component) {
            $this->assertEqualsWithDelta(0.0, $component, 0.1, 'Each dimension should converge close to the known optimum at the origin.');
        }
    }

    /**
     * The 2D Rosenbrock "banana" function f(x,y) = (a-x)^2 + b(y-x^2)^2 (a=1, b=100) has a known global
     * minimum of 0 at (1, 1) -- a classic non-convex benchmark with a narrow, curved valley that's much
     * harder to navigate than the sphere function, a meaningfully stronger correctness check.
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

        $algorithm = new DifferentialEvolutionAlgorithm();
        $algorithm->setDifferentialEvolutionParameters(populationSize: 40, maxGenerations: 500);

        // Act
        $result = $algorithm->optimize($rosenbrock, [-3.0, -3.0], [3.0, 3.0]);

        // Assert
        $this->assertLessThan(0.05, $result->getBestValue(), 'DE should get close to the Rosenbrock function\'s known minimum of 0.');
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

        $algorithm = new DifferentialEvolutionAlgorithm();
        $algorithm->setDifferentialEvolutionParameters(populationSize: 10, maxGenerations: 5);

        // Act
        $result = $algorithm->optimize($sphere, [-5.0], [5.0]);

        // Assert -- 10 initial evaluations + 5 generations * 10 candidates each
        $this->assertSame(60, $result->getEvaluationCount());
        $this->assertCount(6, $result->getBestValueHistory(), 'One history entry for the initial population plus one per generation.');

        $history = $result->getBestValueHistory();
        $historyCount = count($history);

        for ($i = 1; $i < $historyCount; $i++) {
            $this->assertLessThanOrEqual($history[$i - 1], $history[$i], 'The best-found value must never get worse from one generation to the next.');
        }
    }

    /**
     * @return void
     */
    public function testSetDifferentialEvolutionParametersRejectsATooSmallPopulation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DifferentialEvolutionAlgorithm())->setDifferentialEvolutionParameters(populationSize: 3);
    }
}
