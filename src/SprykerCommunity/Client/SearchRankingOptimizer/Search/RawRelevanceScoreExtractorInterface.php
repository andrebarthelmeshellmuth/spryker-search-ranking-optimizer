<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

interface RawRelevanceScoreExtractorInterface
{
    /**
     * Specification:
     * - Extracts the raw text-relevance score from an Elasticsearch `_explanation` tree — the value
     *   BEFORE any `function_score`/`script_score` wrapper was applied, regardless of whether one
     *   happens to be present.
     * - When a `script score function` node exists anywhere in the tree (the function_score-wrapped
     *   case), returns its `_score:` child's value — the wrapped query's own relevance score.
     * - When no such node exists (the unwrapped case), the raw text-relevance score IS the top-level
     *   `_score` — returns the explanation's own root `value`.
     *
     * @param array<string, mixed> $explanation
     *
     * @return float
     */
    public function extract(array $explanation): float;
}
