<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

/**
 * A deliberately small, standalone re-implementation of the ONE thing calibration needs out of an
 * `_explanation` tree — search-ranking previously called into `spryker-community/search-debug`'s
 * `ExplanationParser` for this (a much larger class that also handles term/token matching, per-field
 * BM25 breakdowns, and synonym groups, none of which calibration needs), which made calibration silently
 * unable to function without search-debug installed despite composer.json listing it only as a
 * `suggest`. This class exists so search-ranking has zero code-level dependency on search-debug.
 */
class RawRelevanceScoreExtractor implements RawRelevanceScoreExtractorInterface
{
    /**
     * @var string
     */
    protected const SCRIPT_SCORE_NODE_PREFIX = 'script score function';

    /**
     * @var string
     */
    protected const QUERY_SCORE_NODE_PREFIX = '_score';

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $explanation
     *
     * @return float
     */
    public function extract(array $explanation): float
    {
        return $this->findQueryScore($explanation) ?? (float)($explanation['value'] ?? 0.0);
    }

    /**
     * A zero-valued node contributes nothing and its children are internal filter-clause noise (Lucene
     * explains those for transparency even though they never scored) — mirrors the same guard
     * search-debug's own `ExplanationParser::walkNode()` uses, so this stays correct against the exact
     * tree shapes that class was already confirmed live against.
     *
     * @param array<string, mixed> $node
     *
     * @return float|null
     */
    protected function findQueryScore(array $node): ?float
    {
        $value = (float)($node['value'] ?? 0.0);

        if ($value === 0.0) {
            return null;
        }

        $description = (string)($node['description'] ?? '');

        if (str_starts_with($description, static::SCRIPT_SCORE_NODE_PREFIX)) {
            foreach ($node['details'] ?? [] as $childNode) {
                $childDescription = (string)($childNode['description'] ?? '');

                if (str_starts_with($childDescription, static::QUERY_SCORE_NODE_PREFIX)) {
                    return (float)($childNode['value'] ?? 0.0);
                }
            }
        }

        foreach ($node['details'] ?? [] as $childNode) {
            $queryScore = $this->findQueryScore($childNode);

            if ($queryScore !== null) {
                return $queryScore;
            }
        }

        return null;
    }
}
