<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm;

/**
 * Plain value object, deliberately NOT a generated Transfer — this whole namespace is meant to be usable
 * standalone (e.g. unit-tested against toy benchmark functions with zero Spryker machinery involved), so
 * it doesn't depend on transfer generation having run at all.
 */
final class OptimizationResult
{
    /**
     * @param array<int, float> $bestVector
     * @param float $bestValue
     * @param int $evaluationCount
     * @param array<int, float> $bestValueHistory One entry per generation/iteration — the best value found
     *   so far at that point, oldest first. Useful for convergence checks in benchmark tests and for
     *   diagnosing a run that didn't converge; empty if an algorithm doesn't track it.
     */
    public function __construct(
        protected array $bestVector,
        protected float $bestValue,
        protected int $evaluationCount,
        protected array $bestValueHistory = [],
    ) {
    }

    /**
     * @return array<int, float>
     */
    public function getBestVector(): array
    {
        return $this->bestVector;
    }

    /**
     * @return float
     */
    public function getBestValue(): float
    {
        return $this->bestValue;
    }

    /**
     * @return int
     */
    public function getEvaluationCount(): int
    {
        return $this->evaluationCount;
    }

    /**
     * @return array<int, float>
     */
    public function getBestValueHistory(): array
    {
        return $this->bestValueHistory;
    }
}
