<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm;

use InvalidArgumentException;

/**
 * Classic DE/rand/1/bin Differential Evolution — mutation (a + F * (b - c)) + binomial crossover +
 * greedy selection, nothing more. Deliberately the simplest population-based black-box optimizer in this
 * namespace: no covariance adaptation, no step-size control, just a handful of arithmetic operations per
 * candidate — see this package's README/memory for why this exists alongside CmaEsAlgorithm rather than
 * instead of it (it's "the thing to beat," and it doubles as proof {@see OptimizerAlgorithmInterface}
 * genuinely generalizes beyond CMA-ES's own shape, not just a second implementation of the same idea).
 */
class DifferentialEvolutionAlgorithm extends AbstractOptimizerAlgorithm
{
    /**
     * @var int
     */
    protected const DEFAULT_POPULATION_SIZE = 20;

    /**
     * @var float
     */
    protected const DEFAULT_MUTATION_FACTOR = 0.8;

    /**
     * @var float
     */
    protected const DEFAULT_CROSSOVER_PROBABILITY = 0.9;

    /**
     * @var int
     */
    protected const DEFAULT_MAX_GENERATIONS = 100;

    /**
     * @var int
     */
    protected int $populationSize = self::DEFAULT_POPULATION_SIZE;

    /**
     * @var float
     */
    protected float $mutationFactor = self::DEFAULT_MUTATION_FACTOR;

    /**
     * @var float
     */
    protected float $crossoverProbability = self::DEFAULT_CROSSOVER_PROBABILITY;

    /**
     * @var int
     */
    protected int $maxGenerations = self::DEFAULT_MAX_GENERATIONS;

    /**
     * Algorithm-specific setup, deliberately NOT part of {@see OptimizerAlgorithmInterface} — call before
     * optimize() to override the defaults above; skipping this call entirely is fine too.
     *
     * @param int $populationSize Number of candidate vectors per generation. Must be at least 4 (DE's
     *   mutation step needs 3 distinct OTHER vectors per target).
     * @param float $mutationFactor "F" — scales the differential (b - c) added to the base vector a.
     *   Typically in [0.4; 1.0]; must be greater than 0.
     * @param float $crossoverProbability "CR" — per-dimension probability a trial vector's value comes
     *   from the mutant rather than the original target. Must be in [0; 1].
     * @param int $maxGenerations Stopping criterion — a fixed generation count, not a fitness-plateau
     *   detector (kept simple deliberately; see this package's README for why).
     *
     * @throws \InvalidArgumentException
     *
     * @return void
     */
    public function setDifferentialEvolutionParameters(
        int $populationSize = self::DEFAULT_POPULATION_SIZE,
        float $mutationFactor = self::DEFAULT_MUTATION_FACTOR,
        float $crossoverProbability = self::DEFAULT_CROSSOVER_PROBABILITY,
        int $maxGenerations = self::DEFAULT_MAX_GENERATIONS,
    ): void {
        if ($populationSize < 4) {
            throw new InvalidArgumentException('populationSize must be at least 4 -- DE/rand/1/bin needs 3 distinct other vectors per target.');
        }

        if ($mutationFactor <= 0.0) {
            throw new InvalidArgumentException('mutationFactor must be greater than 0.');
        }

        if ($crossoverProbability < 0.0 || $crossoverProbability > 1.0) {
            throw new InvalidArgumentException('crossoverProbability must be between 0 and 1.');
        }

        if ($maxGenerations < 1) {
            throw new InvalidArgumentException('maxGenerations must be at least 1.');
        }

        $this->populationSize = $populationSize;
        $this->mutationFactor = $mutationFactor;
        $this->crossoverProbability = $crossoverProbability;
        $this->maxGenerations = $maxGenerations;
    }

    /**
     * {@inheritDoc}
     *
     * @param callable $objectiveFunction
     * @param array<int, float> $lowerBounds
     * @param array<int, float> $upperBounds
     *
     * @return \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\OptimizationResult
     */
    public function optimize(callable $objectiveFunction, array $lowerBounds, array $upperBounds): OptimizationResult
    {
        $this->resetTracking();
        $this->validateBounds($lowerBounds, $upperBounds);

        $population = [];
        $populationValues = [];

        for ($i = 0; $i < $this->populationSize; $i++) {
            $vector = $this->randomVectorWithinBounds($lowerBounds, $upperBounds);
            $population[$i] = $vector;
            $populationValues[$i] = $this->evaluate($objectiveFunction, $vector);
        }

        $this->recordGenerationHistory();

        for ($generation = 0; $generation < $this->maxGenerations; $generation++) {
            [$population, $populationValues] = $this->runOneGeneration(
                $objectiveFunction,
                $population,
                $populationValues,
                $lowerBounds,
                $upperBounds,
            );

            $this->recordGenerationHistory();
        }

        return $this->buildResult();
    }

    /**
     * @param callable $objectiveFunction
     * @param array<int, array<int, float>> $population
     * @param array<int, float> $populationValues
     * @param array<int, float> $lowerBounds
     * @param array<int, float> $upperBounds
     *
     * @return array{0: array<int, array<int, float>>, 1: array<int, float>}
     */
    protected function runOneGeneration(
        callable $objectiveFunction,
        array $population,
        array $populationValues,
        array $lowerBounds,
        array $upperBounds,
    ): array {
        $dimensionCount = count($lowerBounds);
        $nextPopulation = $population;
        $nextPopulationValues = $populationValues;

        foreach ($population as $targetIndex => $targetVector) {
            [$vectorA, $vectorB, $vectorC] = $this->pickThreeDistinctOthers($population, $targetIndex);
            $forcedDimension = $this->randomizer->getInt(0, $dimensionCount - 1);

            $trialVector = [];

            for ($dimension = 0; $dimension < $dimensionCount; $dimension++) {
                $useMutant = $dimension === $forcedDimension || $this->randomizer->getFloat(0.0, 1.0) < $this->crossoverProbability;

                $trialVector[$dimension] = $useMutant
                    ? $vectorA[$dimension] + $this->mutationFactor * ($vectorB[$dimension] - $vectorC[$dimension])
                    : $targetVector[$dimension];
            }

            $trialVector = $this->clamp($trialVector, $lowerBounds, $upperBounds);
            $trialValue = $this->evaluate($objectiveFunction, $trialVector);

            if ($trialValue > $populationValues[$targetIndex]) {
                continue;
            }

            $nextPopulation[$targetIndex] = $trialVector;
            $nextPopulationValues[$targetIndex] = $trialValue;
        }

        return [$nextPopulation, $nextPopulationValues];
    }

    /**
     * @param array<int, array<int, float>> $population
     * @param int $excludeIndex
     *
     * @return array{0: array<int, float>, 1: array<int, float>, 2: array<int, float>}
     */
    protected function pickThreeDistinctOthers(array $population, int $excludeIndex): array
    {
        $candidateIndexes = array_keys($population);
        $candidateIndexes = array_filter($candidateIndexes, fn (int $index): bool => $index !== $excludeIndex);
        $candidateIndexes = array_values($candidateIndexes);

        $pickedIndexes = [];

        while (count($pickedIndexes) < 3) {
            $index = $candidateIndexes[$this->randomizer->getInt(0, count($candidateIndexes) - 1)];

            if (in_array($index, $pickedIndexes, true)) {
                continue;
            }

            $pickedIndexes[] = $index;
        }

        return [$population[$pickedIndexes[0]], $population[$pickedIndexes[1]], $population[$pickedIndexes[2]]];
    }
}
