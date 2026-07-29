<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm;

use InvalidArgumentException;
use Random\Randomizer;
use SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\Internal\SymmetricEigenDecomposition;

/**
 * (μ/μ_w, λ)-CMA-ES — a faithful port of the standard algorithm as described in Hansen's own simplified
 * "purecma" reference implementation (deliberately published as a compact, portable reference distinct
 * from the full-featured `pycma` production library), not reimplemented from the original paper's
 * equations directly. Ported rather than invented from scratch specifically to minimize the chance of a
 * subtle sign/normalization bug in the step-size and covariance adaptation machinery — see this package's
 * README/memory for the full reasoning behind that choice, including why the eigendecomposition needed
 * ({@see SymmetricEigenDecomposition}) is a hand-rolled Jacobi solver rather than an external dependency.
 *
 * Deliberately simple relative to a production CMA-ES: eigendecomposition happens every generation
 * (skipping the usual "only every few generations" performance optimization, unnecessary at this package's
 * real dimensionality of ~10-15 parameters) and there is no automatic restart/IPOP machinery — a fixed
 * generation count is the only stopping criterion, matching this project's "reviewable reference code over
 * sophistication" bias and this namespace's simple generation-count stopping rule elsewhere
 * (DifferentialEvolutionAlgorithm).
 */
class CmaEsAlgorithm extends AbstractOptimizerAlgorithm
{
    /**
     * @var float
     */
    protected const DEFAULT_INITIAL_STEP_SIZE = 0.3;

    /**
     * @var int
     */
    protected const DEFAULT_MAX_GENERATIONS = 200;

    /**
     * @var int|null
     */
    protected ?int $populationSize = null;

    /**
     * @var float
     */
    protected float $initialStepSize = self::DEFAULT_INITIAL_STEP_SIZE;

    /**
     * @var array<int, float>|null
     */
    protected ?array $initialMean = null;

    /**
     * @var int
     */
    protected int $maxGenerations = self::DEFAULT_MAX_GENERATIONS;

    /**
     * @var \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\Internal\SymmetricEigenDecomposition
     */
    protected SymmetricEigenDecomposition $eigenDecomposition;

    /**
     * @param \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\Internal\SymmetricEigenDecomposition|null $eigenDecomposition
     * @param \Random\Randomizer|null $randomizer
     */
    public function __construct(?SymmetricEigenDecomposition $eigenDecomposition = null, ?Randomizer $randomizer = null)
    {
        parent::__construct($randomizer);
        $this->eigenDecomposition = $eigenDecomposition ?? new SymmetricEigenDecomposition();
    }

    /**
     * Algorithm-specific setup, deliberately NOT part of {@see OptimizerAlgorithmInterface} — call before
     * optimize() to override the defaults below; skipping this call entirely is fine too (populationSize
     * then falls back to Hansen's own classic default of 4 + floor(3 * ln(dimensionCount)), computed once
     * the actual dimension count is known from optimize()'s bounds).
     *
     * @param int|null $populationSize "λ" — offspring per generation. Null uses Hansen's classic default
     *   (4 + floor(3 * ln(n))), computed from the actual dimension count at optimize() time. If given,
     *   must be at least 4.
     * @param float $initialStepSize "σ0" — initial global step size (standard deviation of the search
     *   distribution before any adaptation). Must be greater than 0.
     * @param array<int, float>|null $initialMean Starting point "m0". Null uses the midpoint of the given
     *   bounds at optimize() time (both bounds must then be finite in every dimension).
     * @param int $maxGenerations Stopping criterion — a fixed generation count, not a fitness-plateau
     *   detector (kept simple deliberately; see this class's own docblock). Must be at least 1.
     *
     * @throws \InvalidArgumentException
     *
     * @return void
     */
    public function setCmaEsParameters(
        ?int $populationSize = null,
        float $initialStepSize = self::DEFAULT_INITIAL_STEP_SIZE,
        ?array $initialMean = null,
        int $maxGenerations = self::DEFAULT_MAX_GENERATIONS,
    ): void {
        if ($populationSize !== null && $populationSize < 4) {
            throw new InvalidArgumentException('populationSize must be at least 4.');
        }

        if ($initialStepSize <= 0.0) {
            throw new InvalidArgumentException('initialStepSize must be greater than 0.');
        }

        if ($maxGenerations < 1) {
            throw new InvalidArgumentException('maxGenerations must be at least 1.');
        }

        $this->populationSize = $populationSize;
        $this->initialStepSize = $initialStepSize;
        $this->initialMean = $initialMean;
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
        $n = $this->validateBounds($lowerBounds, $upperBounds);

        $strategy = $this->buildStrategyParameters($n);
        $mean = $this->initialMean ?? $this->midpoint($lowerBounds, $upperBounds);
        $sigma = $this->initialStepSize;

        $covariance = $this->buildIdentity($n);
        $pathSigma = array_fill(0, $n, 0.0);
        $pathC = array_fill(0, $n, 0.0);
        [$eigenvectors, $eigenvalues] = $this->eigenSqrt($covariance);

        for ($generation = 1; $generation <= $this->maxGenerations; $generation++) {
            $samples = $this->sampleOffspring($strategy['lambda'], $n, $mean, $sigma, $eigenvectors, $eigenvalues, $lowerBounds, $upperBounds);
            $values = [];

            foreach ($samples as $index => $sample) {
                $values[$index] = $this->evaluate($objectiveFunction, $sample['x']);
            }

            asort($values);
            $rankedIndexes = array_keys($values);

            $newMean = $this->weightedRecombination($samples, $rankedIndexes, $strategy['weights'], $n);
            $samplingSigma = $sigma;
            $yMean = $this->scaleVector($this->subtractVectors($newMean, $mean), 1.0 / $samplingSigma);

            $pathSigma = $this->updatePathSigma($pathSigma, $yMean, $eigenvectors, $eigenvalues, $strategy);
            $sigma = $this->updateStepSize($sigma, $pathSigma, $strategy);

            $hSigma = $this->computeHeaviside($pathSigma, $strategy, $n, $generation);
            $pathC = $this->updatePathC($pathC, $yMean, $hSigma, $strategy);

            // Uses $samplingSigma (the step size actually used to draw THIS generation's samples), not the
            // just-updated $sigma above -- the rank-mu term's y_i = (x_i - m_old) / sigma_old must match
            // what was used to produce those same x_i in sampleOffspring().
            $covariance = $this->updateCovariance($covariance, $pathC, $samples, $rankedIndexes, $strategy, $hSigma, $samplingSigma, $mean, $n);

            $mean = $newMean;
            [$eigenvectors, $eigenvalues] = $this->eigenSqrt($covariance);

            $this->recordGenerationHistory();
        }

        return $this->buildResult();
    }

    /**
     * All the (μ/μ_w, λ)-CMA-ES strategy constants, computed once per optimize() call from the dimension
     * count and (if given) the configured population size — standard formulas, see Hansen's "The CMA
     * Evolution Strategy: A Tutorial" for the derivations; not reproduced here since this is a port, not a
     * re-derivation.
     *
     * @param int $n
     *
     * @return array<string, mixed>
     */
    protected function buildStrategyParameters(int $n): array
    {
        $lambda = $this->populationSize ?? (int)(4 + floor(3 * log($n)));
        $mu = (int)floor($lambda / 2);

        $rawWeights = [];

        for ($i = 1; $i <= $mu; $i++) {
            $rawWeights[$i - 1] = log($mu + 0.5) - log($i);
        }

        $weightSum = array_sum($rawWeights);
        $weights = array_map(static fn (float $weight): float => $weight / $weightSum, $rawWeights);

        $muEff = 1.0 / array_sum(array_map(static fn (float $weight): float => $weight ** 2, $weights));

        $cSigma = ($muEff + 2) / ($n + $muEff + 5);
        $dSigma = 1 + 2 * max(0.0, sqrt(($muEff - 1) / ($n + 1)) - 1) + $cSigma;
        $cc = (4 + $muEff / $n) / ($n + 4 + 2 * $muEff / $n);
        $c1 = 2 / (($n + 1.3) ** 2 + $muEff);
        $cMu = min(1 - $c1, 2 * ($muEff - 2 + 1 / $muEff) / (($n + 2) ** 2 + $muEff));
        $chiN = sqrt((float)$n) * (1 - 1 / (4 * $n) + 1 / (21 * $n ** 2));

        return [
            'lambda' => $lambda,
            'mu' => $mu,
            'weights' => $weights,
            'muEff' => $muEff,
            'cSigma' => $cSigma,
            'dSigma' => $dSigma,
            'cc' => $cc,
            'c1' => $c1,
            'cMu' => $cMu,
            'chiN' => $chiN,
        ];
    }

    /**
     * @param array<int, float> $lowerBounds
     * @param array<int, float> $upperBounds
     *
     * @throws \InvalidArgumentException
     *
     * @return array<int, float>
     */
    protected function midpoint(array $lowerBounds, array $upperBounds): array
    {
        $mean = [];

        foreach ($lowerBounds as $index => $lowerBound) {
            if (is_infinite($lowerBound) || is_infinite($upperBounds[$index])) {
                throw new InvalidArgumentException('An initialMean must be given via setCmaEsParameters() when any bound is infinite -- the midpoint of an unbounded dimension is undefined.');
            }

            $mean[$index] = ($lowerBound + $upperBounds[$index]) / 2.0;
        }

        return $mean;
    }

    /**
     * @param int $n
     *
     * @return array<int, array<int, float>>
     */
    protected function buildIdentity(int $n): array
    {
        $identity = [];

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $identity[$i][$j] = $i === $j ? 1.0 : 0.0;
            }
        }

        return $identity;
    }

    /**
     * @param array<int, array<int, float>> $covariance
     *
     * @return array{0: array<int, array<int, float>>, 1: array<int, float>} [eigenvectors, sqrt(eigenvalues)]
     *   -- the square roots are what sampling and the inverse-square-root transform both actually need.
     */
    protected function eigenSqrt(array $covariance): array
    {
        $decomposed = $this->eigenDecomposition->decompose($covariance);
        $sqrtEigenvalues = array_map(static fn (float $eigenvalue): float => sqrt(max($eigenvalue, 0.0)), $decomposed['eigenvalues']);

        return [$decomposed['eigenvectors'], $sqrtEigenvalues];
    }

    /**
     * @param int $lambda
     * @param int $n
     * @param array<int, float> $mean
     * @param float $sigma
     * @param array<int, array<int, float>> $eigenvectors
     * @param array<int, float> $sqrtEigenvalues
     * @param array<int, float> $lowerBounds
     * @param array<int, float> $upperBounds
     *
     * @return array<int, array{z: array<int, float>, y: array<int, float>, x: array<int, float>}>
     */
    protected function sampleOffspring(
        int $lambda,
        int $n,
        array $mean,
        float $sigma,
        array $eigenvectors,
        array $sqrtEigenvalues,
        array $lowerBounds,
        array $upperBounds,
    ): array {
        $samples = [];

        for ($k = 0; $k < $lambda; $k++) {
            $z = [];

            for ($i = 0; $i < $n; $i++) {
                $z[$i] = $this->standardNormal();
            }

            // y = B * (D .* z) -- scale the isotropic sample by the eigenvalues' square roots, THEN
            // rotate into the covariance's own basis. No transformByTranspose here: z is already
            // isotropic (N(0,I)), so there is nothing to project out of eigenspace first.
            $y = $this->matrixVectorMultiply($eigenvectors, $this->applyDiagonal($sqrtEigenvalues, $z));
            $x = $this->clamp($this->addVectors($mean, $this->scaleVector($y, $sigma)), $lowerBounds, $upperBounds);

            $samples[$k] = ['z' => $z, 'y' => $y, 'x' => $x];
        }

        return $samples;
    }

    /**
     * Box-Muller transform — a standard, simple way to draw from N(0,1) without depending on any
     * PHP extension beyond what {@see \Random\Randomizer} already provides.
     *
     * @return float
     */
    protected function standardNormal(): float
    {
        $u1 = max($this->randomizer->getFloat(0.0, 1.0), PHP_FLOAT_EPSILON);
        $u2 = $this->randomizer->getFloat(0.0, 1.0);

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    /**
     * @param array<int, array{x: array<int, float>}> $samples
     * @param array<int, int> $rankedIndexes Sample indexes, best (lowest value) first.
     * @param array<int, float> $weights
     * @param int $n
     *
     * @return array<int, float>
     */
    protected function weightedRecombination(array $samples, array $rankedIndexes, array $weights, int $n): array
    {
        $result = array_fill(0, $n, 0.0);

        foreach ($weights as $rank => $weight) {
            $sampleIndex = $rankedIndexes[$rank];

            foreach ($samples[$sampleIndex]['x'] as $dimension => $value) {
                $result[$dimension] += $weight * $value;
            }
        }

        return $result;
    }

    /**
     * @param array<int, float> $pathSigma
     * @param array<int, float> $yMean
     * @param array<int, array<int, float>> $eigenvectors
     * @param array<int, float> $sqrtEigenvalues
     * @param array<string, mixed> $strategy
     *
     * @return array<int, float>
     */
    protected function updatePathSigma(array $pathSigma, array $yMean, array $eigenvectors, array $sqrtEigenvalues, array $strategy): array
    {
        $inverseSqrtTransformed = $this->applyInverseSqrtCovariance($yMean, $eigenvectors, $sqrtEigenvalues);
        $factor = sqrt($strategy['cSigma'] * (2 - $strategy['cSigma']) * $strategy['muEff']);

        $decayed = $this->scaleVector($pathSigma, 1 - $strategy['cSigma']);
        $boosted = $this->scaleVector($inverseSqrtTransformed, $factor);

        return $this->addVectors($decayed, $boosted);
    }

    /**
     * C^(-1/2) * v = B * D^(-1) * B^T * v, using the already-known eigendecomposition of C.
     *
     * @param array<int, float> $vector
     * @param array<int, array<int, float>> $eigenvectors
     * @param array<int, float> $sqrtEigenvalues
     *
     * @return array<int, float>
     */
    protected function applyInverseSqrtCovariance(array $vector, array $eigenvectors, array $sqrtEigenvalues): array
    {
        $transformed = $this->transformByTranspose($eigenvectors, $vector);

        foreach ($transformed as $index => $value) {
            $transformed[$index] = $value / max($sqrtEigenvalues[$index], PHP_FLOAT_EPSILON);
        }

        return $this->matrixVectorMultiply($eigenvectors, $transformed);
    }

    /**
     * @param float $sigma
     * @param array<int, float> $pathSigma
     * @param array<string, mixed> $strategy
     *
     * @return float
     */
    protected function updateStepSize(float $sigma, array $pathSigma, array $strategy): float
    {
        $pathNorm = $this->vectorNorm($pathSigma);

        return $sigma * exp(($strategy['cSigma'] / $strategy['dSigma']) * ($pathNorm / $strategy['chiN'] - 1));
    }

    /**
     * @param array<int, float> $pathSigma
     * @param array<string, mixed> $strategy
     * @param int $n
     * @param int $generation
     *
     * @return float 1.0 or 0.0 -- stalls the covariance path update when the step-size path has grown
     *   unusually large, a standard CMA-ES stabilization against premature covariance blow-up.
     */
    protected function computeHeaviside(array $pathSigma, array $strategy, int $n, int $generation): float
    {
        $pathNorm = $this->vectorNorm($pathSigma);
        $expectedNorm = sqrt(1 - (1 - $strategy['cSigma']) ** (2 * $generation)) * $strategy['chiN'];
        $threshold = (1.4 + 2 / ($n + 1)) * $strategy['chiN'];

        return $pathNorm / max($expectedNorm, PHP_FLOAT_EPSILON) < $threshold ? 1.0 : 0.0;
    }

    /**
     * @param array<int, float> $pathC
     * @param array<int, float> $yMean
     * @param float $hSigma
     * @param array<string, mixed> $strategy
     *
     * @return array<int, float>
     */
    protected function updatePathC(array $pathC, array $yMean, float $hSigma, array $strategy): array
    {
        $factor = $hSigma * sqrt($strategy['cc'] * (2 - $strategy['cc']) * $strategy['muEff']);

        $decayed = $this->scaleVector($pathC, 1 - $strategy['cc']);
        $boosted = $this->scaleVector($yMean, $factor);

        return $this->addVectors($decayed, $boosted);
    }

    /**
     * @param array<int, array<int, float>> $covariance
     * @param array<int, float> $pathC
     * @param array<int, array{x: array<int, float>}> $samples
     * @param array<int, int> $rankedIndexes
     * @param array<string, mixed> $strategy
     * @param float $hSigma
     * @param float $sigma
     * @param array<int, float> $mean
     * @param int $n
     *
     * @return array<int, array<int, float>>
     */
    protected function updateCovariance(
        array $covariance,
        array $pathC,
        array $samples,
        array $rankedIndexes,
        array $strategy,
        float $hSigma,
        float $sigma,
        array $mean,
        int $n,
    ): array {
        $c1 = $strategy['c1'];
        $cMu = $strategy['cMu'];
        $deltaH = (1 - $hSigma) * $strategy['cc'] * (2 - $strategy['cc']);

        $retained = 1 - $c1 - $cMu + $c1 * $deltaH;
        $updated = [];

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $updated[$i][$j] = $retained * $covariance[$i][$j] + $c1 * $pathC[$i] * $pathC[$j];
            }
        }

        foreach ($strategy['weights'] as $rank => $weight) {
            $sampleIndex = $rankedIndexes[$rank];
            $x = $samples[$sampleIndex]['x'];
            $y = $this->scaleVector($this->subtractVectors($x, $mean), 1.0 / $sigma);

            for ($i = 0; $i < $n; $i++) {
                for ($j = 0; $j < $n; $j++) {
                    $updated[$i][$j] += $cMu * $weight * $y[$i] * $y[$j];
                }
            }
        }

        return $updated;
    }

    /**
     * @param array<int, array<int, float>> $matrix
     * @param array<int, float> $vector
     *
     * @return array<int, float>
     */
    protected function matrixVectorMultiply(array $matrix, array $vector): array
    {
        $result = [];

        foreach ($matrix as $i => $row) {
            $sum = 0.0;

            foreach ($row as $j => $value) {
                $sum += $value * $vector[$j];
            }

            $result[$i] = $sum;
        }

        return $result;
    }

    /**
     * matrix^T * vector -- since {@see SymmetricEigenDecomposition} returns eigenvectors as columns of
     * $matrix, this is what's needed to project a vector INTO eigenspace (matrixVectorMultiply projects
     * back OUT of it).
     *
     * @param array<int, array<int, float>> $matrix
     * @param array<int, float> $vector
     *
     * @return array<int, float>
     */
    protected function transformByTranspose(array $matrix, array $vector): array
    {
        $n = count($vector);
        $result = array_fill(0, $n, 0.0);

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $result[$j] += $matrix[$i][$j] * $vector[$i];
            }
        }

        return $result;
    }

    /**
     * @param array<int, float> $diagonal
     * @param array<int, float> $vector
     *
     * @return array<int, float>
     */
    protected function applyDiagonal(array $diagonal, array $vector): array
    {
        $result = [];

        foreach ($vector as $index => $value) {
            $result[$index] = $diagonal[$index] * $value;
        }

        return $result;
    }

    /**
     * @param array<int, float> $vector
     * @param float $scalar
     *
     * @return array<int, float>
     */
    protected function scaleVector(array $vector, float $scalar): array
    {
        return array_map(static fn (float $value): float => $value * $scalar, $vector);
    }

    /**
     * @param array<int, float> $a
     * @param array<int, float> $b
     *
     * @return array<int, float>
     */
    protected function addVectors(array $a, array $b): array
    {
        $result = [];

        foreach ($a as $index => $value) {
            $result[$index] = $value + $b[$index];
        }

        return $result;
    }

    /**
     * @param array<int, float> $a
     * @param array<int, float> $b
     *
     * @return array<int, float>
     */
    protected function subtractVectors(array $a, array $b): array
    {
        $result = [];

        foreach ($a as $index => $value) {
            $result[$index] = $value - $b[$index];
        }

        return $result;
    }

    /**
     * @param array<int, float> $vector
     *
     * @return float
     */
    protected function vectorNorm(array $vector): float
    {
        $sumOfSquares = 0.0;

        foreach ($vector as $value) {
            $sumOfSquares += $value ** 2;
        }

        return sqrt($sumOfSquares);
    }
}
