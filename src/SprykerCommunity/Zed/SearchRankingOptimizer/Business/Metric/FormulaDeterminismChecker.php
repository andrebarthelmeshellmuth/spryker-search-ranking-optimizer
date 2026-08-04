<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric;

use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

class FormulaDeterminismChecker implements FormulaDeterminismCheckerInterface
{
    /**
     * {@inheritDoc}
     *
     * @param string $formula
     */
    public function isDeterministic(string $formula): bool
    {
        foreach (SearchRankingOptimizerConfig::getNonDeterministicFormulaFunctionNames() as $functionName) {
            // Requires the function name immediately followed by optional whitespace then "(" -- e.g.
            // matches "random(" and "random (" but deliberately not "random_seed(" (a different,
            // hypothetical function that merely starts with the same name), since there's no word
            // boundary between "random" and the following "_" for \b to stop at.
            if (preg_match('/\b' . preg_quote($functionName, '/') . '\s*\(/', $formula) === 1) {
                return false;
            }
        }

        return true;
    }
}
