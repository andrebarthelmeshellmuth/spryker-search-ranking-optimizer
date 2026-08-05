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
}
