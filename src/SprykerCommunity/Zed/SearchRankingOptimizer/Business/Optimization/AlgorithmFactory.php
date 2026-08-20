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
use InvalidArgumentException;
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
     * @param bool $isRestartOnPlateauEnabled
     *
     * @throws \InvalidArgumentException
     */
    public function create(
        string $algorithmName,
        int $populationSize,
        int $maxGenerations,
        bool $isTerminationCriteriaTrusted = false,
        ?array $warmStartVector = null,
        float $warmStartFraction = 0.0,
        bool $isRestartOnPlateauEnabled = false,
    ): OptimizerAlgorithmInterface {
        if ($isTerminationCriteriaTrusted && $isRestartOnPlateauEnabled) {
            throw new InvalidArgumentException(
                'isTerminationCriteriaTrusted and isRestartOnPlateauEnabled are mutually exclusive -- '
                . 'RestartingOptimizerDecorator does not support trusting an inner algorithm\'s own safety '
                . 'ceiling, since it would blow through the decorator\'s own evaluation-budget accounting.',
            );
        }

        $algorithm = $this->createAll()[$algorithmName] ?? $this->createCmaEs();

        if ($isRestartOnPlateauEnabled) {
            $algorithm = new RestartingOptimizerDecorator($algorithm);
        }

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
