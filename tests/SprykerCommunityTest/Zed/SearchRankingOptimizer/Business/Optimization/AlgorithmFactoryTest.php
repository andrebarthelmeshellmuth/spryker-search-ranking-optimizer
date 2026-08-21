<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Optimization;

use BlackboxOptimizer\Algorithm\CmaEsAlgorithm;
use BlackboxOptimizer\Algorithm\DifferentialEvolutionAlgorithm;
use BlackboxOptimizer\Algorithm\RechenbergSchwefelEsAlgorithm;
use BlackboxOptimizer\Algorithm\RestartingOptimizerDecorator;
use BlackboxOptimizer\Problem\CallableProblem;
use Codeception\Test\Unit;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\AlgorithmFactory;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Optimization
 * @group AlgorithmFactoryTest
 * @group Portable
 */
class AlgorithmFactoryTest extends Unit
{
    public function testCreateAllReturnsOneUnconfiguredInstancePerKnownAlgorithmKeyedByItsConfigConstant(): void
    {
        $algorithms = (new AlgorithmFactory())->createAll();

        $this->assertCount(3, $algorithms);
        $this->assertInstanceOf(CmaEsAlgorithm::class, $algorithms[SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES]);
        $this->assertInstanceOf(RechenbergSchwefelEsAlgorithm::class, $algorithms[SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_RECHENBERG_SCHWEFEL_ES]);
        $this->assertInstanceOf(DifferentialEvolutionAlgorithm::class, $algorithms[SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION]);
    }

    public function testCreateReturnsACmaEsInstanceConfiguredWithTheGivenPopulationSizeAndMaxGenerations(): void
    {
        $algorithm = (new AlgorithmFactory())->create(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES, 12, 5);

        $this->assertInstanceOf(CmaEsAlgorithm::class, $algorithm);
        // CMA-ES's own estimateEvaluationCount() is exactly populationSize * maxIterations once
        // setPopulationSize() has been called (see its own docblock) -- the cleanest way to prove
        // create() actually applied both arguments rather than silently falling back to some default.
        $this->assertSame(60, $algorithm->estimateEvaluationCount());
    }

    public function testCreateReturnsADifferentialEvolutionInstanceForTheDifferentialEvolutionKey(): void
    {
        $algorithm = (new AlgorithmFactory())->create(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION, 12, 5);

        $this->assertInstanceOf(DifferentialEvolutionAlgorithm::class, $algorithm);
    }

    public function testCreateReturnsARechenbergSchwefelEsInstanceForTheRechenbergSchwefelEsKey(): void
    {
        $algorithm = (new AlgorithmFactory())->create(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_RECHENBERG_SCHWEFEL_ES, 12, 5);

        $this->assertInstanceOf(RechenbergSchwefelEsAlgorithm::class, $algorithm);
    }

    public function testCreateFallsBackToCmaEsForAnUnrecognizedAlgorithmName(): void
    {
        $algorithm = (new AlgorithmFactory())->create('totally-unknown-algorithm-name', 12, 5);

        $this->assertInstanceOf(CmaEsAlgorithm::class, $algorithm);
        $this->assertSame(60, $algorithm->estimateEvaluationCount(), 'The fallback must still apply the given population size/max generations, not just default-construct CMA-ES.');
    }

    /**
     * estimateEvaluationCount() switches to blackbox-optimizer's own large safety-ceiling estimate once
     * trustTerminationCriteria() has actually been called -- the cleanest way to prove create() applied the
     * flag, mirroring how the existing tests above prove populationSize/maxGenerations were applied.
     */
    public function testCreateCallsTrustTerminationCriteriaWhenTerminationModeIsTrustedSingleRun(): void
    {
        $algorithm = (new AlgorithmFactory())->create(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES, 12, 5, SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_TRUSTED_SINGLE_RUN);

        $this->assertGreaterThan(60, $algorithm->estimateEvaluationCount(), 'estimateEvaluationCount() must reflect the safety-ceiling once trustTerminationCriteria() was applied, not populationSize * maxGenerations.');
    }

    public function testCreateDoesNotCallTrustTerminationCriteriaByDefault(): void
    {
        $algorithm = (new AlgorithmFactory())->create(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES, 12, 5);

        $this->assertSame(60, $algorithm->estimateEvaluationCount(), 'The default (no 4th argument) must leave trustTerminationCriteria() uncalled.');
    }

    /**
     * setWarmStart() has no public getter, so this proves it was actually called the same way
     * blackbox-optimizer's own tests do: place the known optimum exactly at the warm-start vector, far from
     * the bounds' own midpoint, and give only a tiny one-generation budget -- only a run that actually
     * started at that vector could land this close.
     */
    public function testCreateAppliesWarmStartWhenVectorAndPositiveFractionAreGiven(): void
    {
        $shiftedSphere = fn (array $vector): float => ($vector[0] - 10) ** 2 + ($vector[1] - 10) ** 2;
        $problem = new CallableProblem($shiftedSphere, [-10.0, -10.0], [10.0, 10.0]);

        $algorithm = (new AlgorithmFactory())->create(
            SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
            4,
            1,
            SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_FIXED_BUDGET,
            [10.0, 10.0],
            1.0,
        );
        $algorithm->setStepWidth(0.1);

        $result = $algorithm->optimize($problem);

        $this->assertLessThan(1.0, $result->getBestValue(), 'A fully warm-started run should start at (10, 10), not the bounds\' own midpoint (0, 0).');
    }

    public function testCreateSkipsWarmStartWhenVectorIsNull(): void
    {
        $midpointSphere = fn (array $vector): float => $vector[0] ** 2 + $vector[1] ** 2;
        $problem = new CallableProblem($midpointSphere, [-10.0, -10.0], [10.0, 10.0]);

        $algorithm = (new AlgorithmFactory())->create(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES, 4, 1);
        $algorithm->setStepWidth(0.1);

        $result = $algorithm->optimize($problem);

        $this->assertLessThan(1.0, $result->getBestValue(), 'With no warm-start vector given, the default midpoint (0, 0) is the bounds\' own midpoint, which is also this problem\'s known optimum.');
    }

    /**
     * A vector given but fraction=0.0 must behave identically to no warm start at all -- places the known
     * optimum at the bounds' own midpoint (0, 0), far from the given warm-start vector (10, 10), so only
     * truly ignoring the vector could land this close.
     */
    public function testCreateSkipsWarmStartWhenFractionIsZero(): void
    {
        $midpointSphere = fn (array $vector): float => $vector[0] ** 2 + $vector[1] ** 2;
        $problem = new CallableProblem($midpointSphere, [-10.0, -10.0], [10.0, 10.0]);

        $algorithm = (new AlgorithmFactory())->create(
            SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
            4,
            1,
            SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_FIXED_BUDGET,
            [10.0, 10.0],
            0.0,
        );
        $algorithm->setStepWidth(0.1);

        $result = $algorithm->optimize($problem);

        $this->assertLessThan(1.0, $result->getBestValue(), 'fraction=0.0 must leave the bounds-midpoint default fully in effect, ignoring the given warm-start vector.');
    }

    public function testCreateWrapsInRestartingOptimizerDecoratorWhenTerminationModeIsRestartOnPlateau(): void
    {
        $algorithm = (new AlgorithmFactory())->create(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES, 12, 5, SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_RESTART_ON_PLATEAU);

        $this->assertInstanceOf(RestartingOptimizerDecorator::class, $algorithm);
        // RestartingOptimizerDecorator::estimateEvaluationCount() is populationSize * maxIterations, same
        // formula as a plain CmaEsAlgorithm -- proves create() applied both arguments to the DECORATOR, not
        // just to the algorithm it wraps (which has no public getter to check directly).
        $this->assertSame(60, $algorithm->estimateEvaluationCount());
    }

    public function testCreateWrapsInRestartingOptimizerDecoratorAndTrustsTheRestartBudgetWhenTerminationModeIsRestartOnPlateauTrustedBudget(): void
    {
        $algorithm = (new AlgorithmFactory())->create(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES, 12, 5, SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_RESTART_ON_PLATEAU_TRUSTED_BUDGET);

        $this->assertInstanceOf(RestartingOptimizerDecorator::class, $algorithm);
        $this->assertGreaterThan(60, $algorithm->estimateEvaluationCount(), 'estimateEvaluationCount() must reflect trustRestartBudget()\'s grown total budget, not populationSize * maxGenerations.');
    }

    public function testCreateDoesNotWrapInRestartingOptimizerDecoratorByDefault(): void
    {
        $algorithm = (new AlgorithmFactory())->create(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES, 12, 5);

        $this->assertNotInstanceOf(RestartingOptimizerDecorator::class, $algorithm);
    }
}
