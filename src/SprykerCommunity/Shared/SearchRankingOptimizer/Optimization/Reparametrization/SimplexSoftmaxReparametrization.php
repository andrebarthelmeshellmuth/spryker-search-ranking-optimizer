<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Reparametrization;

use InvalidArgumentException;

/**
 * Converts between an UNCONSTRAINED (n-1)-dimensional real vector "z" and an n-dimensional point on the
 * probability simplex (n weights, each >= 0, summing to exactly 1) — the standard trick for handing a
 * simplex-constrained problem to a black-box optimizer that only knows how to explore an unconstrained (or
 * simple box-bounded) space: `OptimizerAlgorithmInterface` never needs to know weights must sum to 1 at
 * all, since z-space has no such constraint to violate in the first place.
 *
 * One degree of freedom is deliberately removed relative to a naive "n free z's, one softmax" approach:
 * softmax is shift-invariant (adding the same constant to every z leaves the resulting weights unchanged),
 * which for a population-based optimizer like CMA-ES creates a genuine flat direction with zero selection
 * pressure — the covariance matrix can grow unboundedly along it over generations. Pinning z_0 = 0 (the
 * first weight's z is never a free parameter at all) removes that flat direction entirely rather than
 * papering over it with bounds, which wouldn't help since a bounded-but-still-free flat direction is still
 * explored for no benefit. See this package's memory/README for the fuller reasoning (multiplicative vs.
 * additive step-size semantics, why this is a wash-to-favorable trade-off for relative-importance weights
 * specifically) — not reproduced here.
 */
class SimplexSoftmaxReparametrization
{
    /**
     * Floor applied before taking a logarithm in {@see toFreeZ()} — a weight of exactly 0 (a metric
     * configured with zero weight) would otherwise blow up to -INF/NaN; this only affects the ONE-TIME
     * conversion of an existing weight vector into an initial z (e.g. seeding an optimizer run from the
     * live configuration), never the optimizer's own repeated toSimplex() calls during a run.
     *
     * @var float
     */
    protected const MINIMUM_WEIGHT_FOR_LOG = 1e-9;

    /**
     * @param array<int, float> $freeZ The (n-1) free z values (z_1..z_{n-1}; z_0 is implicitly 0).
     *
     * @return array<int, float> n weights, each > 0, summing to exactly 1 — index 0 is the pinned
     *   dimension's own weight.
     */
    public function toSimplex(array $freeZ): array
    {
        $z = array_merge([0.0], array_values($freeZ));

        return $this->softmax($z);
    }

    /**
     * The inverse of {@see toSimplex()} — given an existing weight vector, find the z that reproduces it.
     * Only needed to SEED an optimizer run from an existing configuration (e.g. the live weights); never
     * called by the optimizer itself during a run.
     *
     * @param array<int, float> $weights n weights, each expected to be >= 0 and summing to (approximately) 1.
     *
     * @throws \InvalidArgumentException
     *
     * @return array<int, float> The (n-1) free z values reproducing these weights via toSimplex().
     */
    public function toFreeZ(array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('weights must contain at least one entry.');
        }

        $weights = array_values($weights);
        $referenceWeight = max($weights[0], static::MINIMUM_WEIGHT_FOR_LOG);
        $freeZ = [];
        $weightCount = count($weights);

        for ($i = 1; $i < $weightCount; $i++) {
            $weight = max($weights[$i], static::MINIMUM_WEIGHT_FOR_LOG);
            $freeZ[] = log($weight) - log($referenceWeight);
        }

        return $freeZ;
    }

    /**
     * @param array<int, float> $z
     *
     * @return array<int, float>
     */
    protected function softmax(array $z): array
    {
        if ($z === []) {
            return [];
        }

        $max = max($z);
        $exponentiated = array_map(static fn (float $value): float => exp($value - $max), $z);
        $sum = array_sum($exponentiated);

        return array_map(static fn (float $value): float => $value / $sum, $exponentiated);
    }
}
