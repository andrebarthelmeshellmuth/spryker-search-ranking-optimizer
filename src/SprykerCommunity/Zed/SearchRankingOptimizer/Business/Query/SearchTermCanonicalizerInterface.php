<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query;

interface SearchTermCanonicalizerInterface
{
    /**
     * Trims, lowercases, and collapses internal whitespace — deliberately NOT tokenized (real
     * tokenization risks silently merging queries that are actually different asks). " Office Chair "
     * and "office chair" canonicalize to the same string; "office chairs" does not.
     *
     * @param string $rawSearchTerm
     */
    public function canonicalize(string $rawSearchTerm): string;
}
