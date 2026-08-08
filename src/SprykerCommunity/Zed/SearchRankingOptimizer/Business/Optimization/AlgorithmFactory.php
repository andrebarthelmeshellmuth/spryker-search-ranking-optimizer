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
     * @param bool $isTerminationCriteriaTrusted
     * @param array<int, float>|null $warmStartVector
     * @param float $warmStartFraction
     */
    public function create(
        string $algorithmName,
        int $populationSize,
        int $maxGenerations,
        bool $isTerminationCriteriaTrusted = false,
        ?array $warmStartVector = null,
        float $warmStartFraction = 0.0,
    ): OptimizerAlgorithmInterface {
        $algorithm = $this->createAll()[$algorithmName] ?? $this->createCmaEs();
        $algorithm->setPopulationSize($populationSize)->setMaxIterations($maxGenerations);

        if ($isTerminationCriteriaTrusted) {
            $algorithm->trustTerminationCriteria();
        }

        if ($warmStartVector !== null && $warmStartFraction > 0.0) {
            $algorithm->setWarmStart($warmStartVector, $warmStartFraction);
        }

        return $algorithm;
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
