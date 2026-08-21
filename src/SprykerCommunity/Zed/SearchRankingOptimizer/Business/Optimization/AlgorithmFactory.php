<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

use BlackboxOptimizer\Algorithm\CmaEsAlgorithm;
use BlackboxOptimizer\Algorithm\DifferentialEvolutionAlgorithm;
use BlackboxOptimizer\Algorithm\OptimizerAlgorithmInterface;
use BlackboxOptimizer\Algorithm\RechenbergSchwefelEsAlgorithm;
use BlackboxOptimizer\Algorithm\RestartingOptimizerDecorator;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

class AlgorithmFactory implements AlgorithmFactoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @return array<string, \BlackboxOptimizer\Algorithm\OptimizerAlgorithmInterface>
     */
    public function createAll(): array
    {
        return [
            SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES => $this->createCmaEs(),
            SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_RECHENBERG_SCHWEFEL_ES => $this->createRechenbergSchwefelEs(),
            SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION => $this->createDifferentialEvolution(),
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @param string $algorithmName
     * @param int $populationSize
     * @param int $maxGenerations
     * @param string $terminationMode
     * @param array<int, float>|null $warmStartVector
     * @param float $warmStartFraction
     */
    public function create(
        string $algorithmName,
        int $populationSize,
        int $maxGenerations,
        string $terminationMode = SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_FIXED_BUDGET,
        ?array $warmStartVector = null,
        float $warmStartFraction = 0.0,
    ): OptimizerAlgorithmInterface {
        $algorithm = $this->createAll()[$algorithmName] ?? $this->createCmaEs();

        $algorithm = match ($terminationMode) {
            SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_TRUSTED_SINGLE_RUN => $this->configureTrustedSingleRun($algorithm, $populationSize, $maxGenerations),
            SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_RESTART_ON_PLATEAU => $this->configureRestartOnPlateau($algorithm, $populationSize, $maxGenerations, false),
            SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_RESTART_ON_PLATEAU_TRUSTED_BUDGET => $this->configureRestartOnPlateau($algorithm, $populationSize, $maxGenerations, true),
            default => $this->configureFixedBudget($algorithm, $populationSize, $maxGenerations),
        };

        if ($warmStartVector !== null && $warmStartFraction > 0.0) {
            $algorithm->setWarmStart($warmStartVector, $warmStartFraction);
        }

        return $algorithm;
    }

    /**
     * `SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_FIXED_BUDGET` (and the fallback for an
     * unrecognized $terminationMode, the same posture `createAll()[$algorithmName] ?? $this->createCmaEs()`
     * already takes for an unrecognized algorithm name).
     *
     * @param \BlackboxOptimizer\Algorithm\OptimizerAlgorithmInterface $algorithm
     * @param int $populationSize
     * @param int $maxGenerations
     */
    protected function configureFixedBudget(
        OptimizerAlgorithmInterface $algorithm,
        int $populationSize,
        int $maxGenerations,
    ): OptimizerAlgorithmInterface {
        $algorithm->setPopulationSize($populationSize)->setMaxIterations($maxGenerations);

        return $algorithm;
    }

    /**
     * `SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_TRUSTED_SINGLE_RUN`.
     *
     * @param \BlackboxOptimizer\Algorithm\OptimizerAlgorithmInterface $algorithm
     * @param int $populationSize
     * @param int $maxGenerations
     */
    protected function configureTrustedSingleRun(
        OptimizerAlgorithmInterface $algorithm,
        int $populationSize,
        int $maxGenerations,
    ): OptimizerAlgorithmInterface {
        $algorithm->setPopulationSize($populationSize)->setMaxIterations($maxGenerations);
        $algorithm->trustTerminationCriteria();

        return $algorithm;
    }

    /**
     * `SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_RESTART_ON_PLATEAU`/
     * `OPTIMIZATION_TERMINATION_MODE_RESTART_ON_PLATEAU_TRUSTED_BUDGET`.
     *
     * @param \BlackboxOptimizer\Algorithm\OptimizerAlgorithmInterface $algorithm
     * @param int $populationSize
     * @param int $maxGenerations
     * @param bool $trustRestartBudget
     */
    protected function configureRestartOnPlateau(
        OptimizerAlgorithmInterface $algorithm,
        int $populationSize,
        int $maxGenerations,
        bool $trustRestartBudget,
    ): OptimizerAlgorithmInterface {
        $decorator = new RestartingOptimizerDecorator($algorithm);
        $decorator->setPopulationSize($populationSize)->setMaxIterations($maxGenerations);

        if ($trustRestartBudget) {
            $decorator->trustRestartBudget();
        }

        return $decorator;
    }

    protected function createCmaEs(): CmaEsAlgorithm
    {
        return new CmaEsAlgorithm();
    }

    protected function createRechenbergSchwefelEs(): RechenbergSchwefelEsAlgorithm
    {
        return new RechenbergSchwefelEsAlgorithm();
    }

    protected function createDifferentialEvolution(): DifferentialEvolutionAlgorithm
    {
        return new DifferentialEvolutionAlgorithm();
    }
}
