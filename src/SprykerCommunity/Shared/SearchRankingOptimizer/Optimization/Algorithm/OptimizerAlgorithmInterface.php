<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm;

/**
 * Deliberately generic — nothing in this namespace knows about search ranking, rank_eval, or Spryker at
 * all. An implementer optimizes an arbitrary black-box, derivative-free, box-constrained MINIMIZATION
 * problem: given $objectiveFunction (any `array<float> $vector -> float` callable, lower is better) and a
 * bounded box, find the vector that minimizes it. Every algorithm here treats the objective function as
 * opaque — it never inspects, caches, or assumes anything about what it computes. This is what lets the
 * SAME CmaEsAlgorithm/DifferentialEvolutionAlgorithm be validated against toy benchmark functions (sphere,
 * Rosenbrock) in tests, and separately be handed a real (and much more expensive) callable that runs an
 * actual rank_eval evaluation elsewhere in this package — that wiring lives on the CALLER's side (which
 * builds the closure, e.g. doing the softmax reparametrization + negating a score to minimize), never here.
 *
 * Each concrete algorithm additionally exposes its OWN typed setter for algorithm-specific internals
 * (e.g. `CmaEsAlgorithm::setCmaEsParameters(...)`, `DifferentialEvolutionAlgorithm::setDifferentialEvolutionParameters(...)`)
 * — deliberately NOT part of this shared interface, since cramming every algorithm's own knobs into one
 * generic method would either lose meaning (a bag of mixed-purpose floats) or force every implementer to
 * support parameters that only make sense for a different algorithm.
 */
interface OptimizerAlgorithmInterface
{
    /**
     * @param callable $objectiveFunction `array<int, float> $vector -> float`, LOWER is better (minimization).
     * @param array<int, float> $lowerBounds One bound per dimension, same length as $upperBounds.
     * @param array<int, float> $upperBounds One bound per dimension, same length as $lowerBounds.
     *
     * @return \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\OptimizationResult
     */
    public function optimize(callable $objectiveFunction, array $lowerBounds, array $upperBounds): OptimizationResult;
}
