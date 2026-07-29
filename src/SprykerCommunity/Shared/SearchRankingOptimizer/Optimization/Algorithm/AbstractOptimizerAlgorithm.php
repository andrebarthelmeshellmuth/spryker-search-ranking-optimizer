<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm;

use InvalidArgumentException;
use Random\Randomizer;

/**
 * Common, algorithm-agnostic bookkeeping every population-based black-box optimizer needs: bounds
 * validation/clamping, a random-vector-within-bounds generator (for an initial population/mean), and
 * evaluation-count + best-so-far tracking so every concrete algorithm reports an {@see OptimizationResult}
 * the same way instead of reimplementing this per algorithm.
 */
abstract class AbstractOptimizerAlgorithm implements OptimizerAlgorithmInterface
{
    /**
     * @var \Random\Randomizer
     */
    protected Randomizer $randomizer;

    /**
     * @var int
     */
    protected int $evaluationCount = 0;

    /**
     * @var float|null
     */
    protected ?float $bestValueSoFar = null;

    /**
     * @var array<int, float>
     */
    protected array $bestVectorSoFar = [];

    /**
     * @var array<int, float>
     */
    protected array $bestValueHistory = [];

    /**
     * @param \Random\Randomizer|null $randomizer Injectable for deterministic tests; defaults to a real
     *   random engine in production use.
     */
    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * @param array<int, float> $lowerBounds
     * @param array<int, float> $upperBounds
     *
     * @throws \InvalidArgumentException
     *
     * @return int The validated dimension count.
     */
    protected function validateBounds(array $lowerBounds, array $upperBounds): int
    {
        $dimensionCount = count($lowerBounds);

        if ($dimensionCount === 0) {
            throw new InvalidArgumentException('Bounds must describe at least one dimension.');
        }

        if ($dimensionCount !== count($upperBounds)) {
            throw new InvalidArgumentException('lowerBounds and upperBounds must have the same length.');
        }

        foreach ($lowerBounds as $index => $lowerBound) {
            if ($lowerBound > $upperBounds[$index]) {
                throw new InvalidArgumentException(sprintf('Dimension %d: lower bound (%f) exceeds upper bound (%f).', $index, $lowerBound, $upperBounds[$index]));
            }
        }

        return $dimensionCount;
    }

    /**
     * @param array<int, float> $vector
     * @param array<int, float> $lowerBounds
     * @param array<int, float> $upperBounds
     *
     * @return array<int, float>
     */
    protected function clamp(array $vector, array $lowerBounds, array $upperBounds): array
    {
        $clamped = [];

        foreach ($vector as $index => $value) {
            $clamped[$index] = min(max($value, $lowerBounds[$index]), $upperBounds[$index]);
        }

        return $clamped;
    }

    /**
     * @param array<int, float> $lowerBounds
     * @param array<int, float> $upperBounds
     *
     * @return array<int, float>
     */
    protected function randomVectorWithinBounds(array $lowerBounds, array $upperBounds): array
    {
        $vector = [];

        foreach ($lowerBounds as $index => $lowerBound) {
            $vector[$index] = $lowerBound + $this->randomizer->getFloat(0.0, 1.0) * ($upperBounds[$index] - $lowerBound);
        }

        return $vector;
    }

    /**
     * Every candidate evaluation MUST go through here, never call $objectiveFunction directly — this is
     * the single place evaluation count and the best-found-so-far vector/value are tracked.
     *
     * @param callable $objectiveFunction
     * @param array<int, float> $vector
     *
     * @return float
     */
    protected function evaluate(callable $objectiveFunction, array $vector): float
    {
        $value = $objectiveFunction($vector);
        $this->evaluationCount++;

        if ($this->bestValueSoFar === null || $value < $this->bestValueSoFar) {
            $this->bestValueSoFar = $value;
            $this->bestVectorSoFar = $vector;
        }

        return $value;
    }

    /**
     * Call once per generation/iteration, after evaluating that generation's candidates, to append the
     * running best to the reported history.
     *
     * @return void
     */
    protected function recordGenerationHistory(): void
    {
        $this->bestValueHistory[] = $this->bestValueSoFar ?? INF;
    }

    /**
     * Resets all tracking state — every optimize() call must start with this, so a reused algorithm
     * instance never leaks a previous run's best-found vector into a new one.
     *
     * @return void
     */
    protected function resetTracking(): void
    {
        $this->evaluationCount = 0;
        $this->bestValueSoFar = null;
        $this->bestVectorSoFar = [];
        $this->bestValueHistory = [];
    }

    /**
     * @return \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\OptimizationResult
     */
    protected function buildResult(): OptimizationResult
    {
        return new OptimizationResult(
            $this->bestVectorSoFar,
            $this->bestValueSoFar ?? INF,
            $this->evaluationCount,
            $this->bestValueHistory,
        );
    }
}
