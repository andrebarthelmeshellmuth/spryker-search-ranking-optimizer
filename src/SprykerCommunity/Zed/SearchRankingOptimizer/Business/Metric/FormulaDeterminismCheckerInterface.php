<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric;

interface FormulaDeterminismCheckerInterface
{
    /**
     * @param string $formula A `search-ranking` metric's own normalization formula, in that package's
     *   formula DSL (e.g. `atan(x / avg) / (pi() / 2)`, `random()`).
     *
     * @return bool False if the formula calls any function
     *   {@see \SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig::getNonDeterministicFormulaFunctionNames()}
     *   names — true otherwise.
     */
    public function isDeterministic(string $formula): bool;
}
