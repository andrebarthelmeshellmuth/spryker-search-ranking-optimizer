<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Shared\SearchRankingOptimizer;

class SearchRankingOptimizerConfig
{
    /**
     * Specification:
     * - A calibration run just uploaded, queued for the next `search-ranking-optimizer:calibrate` cron
     *   tick. At most one uploaded row is ever picked up per tick — the newest — the rest move straight to
     *   {@see CALIBRATION_STATUS_SKIPPED}.
     *
     * @api
     *
     * @var string
     */
    public const CALIBRATION_STATUS_UPLOADED = 'uploaded';

    /**
     * Specification:
     * - A superseded upload: a newer one existed by the time the cron ran, so this one was never
     *   calculated.
     *
     * @api
     *
     * @var string
     */
    public const CALIBRATION_STATUS_SKIPPED = 'skipped';

    /**
     * Specification:
     * - The cron has picked this run up and is currently firing search queries for it.
     *
     * @api
     *
     * @var string
     */
    public const CALIBRATION_STATUS_CALCULATING = 'calculating';

    /**
     * Specification:
     * - The run finished: every search term was queried (or skipped on individual failure) and the
     *   pooled score statistics, including the `computedK` suggestion, are populated.
     *
     * @api
     *
     * @var string
     */
    public const CALIBRATION_STATUS_CALCULATED = 'calculated';

    /**
     * Specification:
     * - The run could not produce any pooled scores at all (e.g. every search term failed or matched
     *   zero products) — `errorMessage` explains why.
     *
     * @api
     *
     * @var string
     */
    public const CALIBRATION_STATUS_FAILED = 'failed';

    /**
     * Specification:
     * - Elasticsearch page-index source identifier passed to `IndexNameResolver::resolve()` when
     *   calibration resolves an index name directly (bypassing Client\Catalog/Client\Search — see
     *   {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\CalibrationSearcher}). A local copy
     *   of the base package's identically-valued constant, kept here so this package resolves the page
     *   index without a compile-time reference back into spryker-community/search-ranking's config.
     *
     * @api
     *
     * @var string
     */
    public const PAGE_SOURCE_IDENTIFIER = 'page';

    /**
     * Specification:
     * - A Relevance Rater's positive-relevance judgment for a (query, product) pair.
     *
     * @api
     *
     * @var string
     */
    public const RATING_TYPE_HEART = 'heart';

    /**
     * Specification:
     * - A Relevance Rater's neutral/acceptable-relevance judgment for a (query, product) pair.
     *
     * @api
     *
     * @var string
     */
    public const RATING_TYPE_CHECK = 'check';

    /**
     * Specification:
     * - A Relevance Rater's negative-relevance judgment for a (query, product) pair.
     *
     * @api
     *
     * @var string
     */
    public const RATING_TYPE_X = 'x';

    /**
     * Specification:
     * - Maps each RATING_TYPE_* to the numeric gain rank_eval scores against — deliberately
     *   project-overridable rather than hardcoded (nDCG's standard gain function is `2^rating - 1`, so this
     *   3/1/0 default gives heart a much bigger gain-weight (7) than check (1); a linear scale may fit a
     *   given shop's judgments better once tested against real data).
     *
     * @api
     *
     * @return array<string, float>
     */
    public static function getRelevanceJudgmentGainMap(): array
    {
        return [
            static::RATING_TYPE_HEART => 3.0,
            static::RATING_TYPE_CHECK => 1.0,
            static::RATING_TYPE_X => 0.0,
        ];
    }

    /**
     * Specification:
     * - The `k` cutoff rank_eval's nDCG metric is computed against (how many of the query's own top-ranked
     *   results count towards the score). Project-overridable; 10 is a conventional default for this kind
     *   of cutoff (also used by spryker-community/search-ranking's own rank_eval capability probe).
     *
     * @api
     *
     * @return int
     */
    public static function getRankEvalCutoff(): int
    {
        return 10;
    }
}
