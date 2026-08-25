<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation;

interface QueryBucketClassifierInterface
{
    /**
     * Specification:
     * - Maps a search term to its hardcoded query-type bucket (1-6, see {@see QueryBucketClassifier} for
     *   the full list and rationale) via a case-insensitive match. Returns `0` (unknown) for a search term
     *   with no entry — never throws.
     *
     * @param string $searchTerm
     */
    public function classify(string $searchTerm): int;
}
