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

    /**
     * Specification:
     * - A weight checkpoint taken directly by a Query Curator — either an explicit "take checkpoint now",
     *   or the checkpoint a restore itself writes to record the resulting state (restore is "apply",
     *   same as any other weight change).
     * - Later phases add further values here as new checkpoint producers get built (a monthly auto-tune
     *   job, an eventual automated weight optimizer) — "one harness, three producers," per the roadmap.
     *
     * @api
     *
     * @var string
     */
    public const CHECKPOINT_SOURCE_MANUAL = 'manual';

    /**
     * Specification:
     * - Auto-tune refits a metric's formula while deliberately keeping its CURRENT curve family — only
     *   the shape's own free parameter is re-searched (e.g. keep `atan` and just recompute its `k`).
     * - Only meaningful for a metric whose `shape` is set (see spryker-community/search-ranking's own
     *   `SearchRankingMetric.shape`); falls back to {@see AUTO_UPDATE_SCOPE_PROGRAM_CHOICE} otherwise,
     *   since there is no known shape to stay within.
     *
     * @api
     *
     * @var string
     */
    public const AUTO_UPDATE_SCOPE_PARAMETERS_ONLY = 'parameters_only';

    /**
     * Specification:
     * - Auto-tune may switch a metric's formula to a DIFFERENT curve family entirely if that fits
     *   materially better (e.g. `atan` -> `x / (x + k)`) — the data picks, not the current shape.
     *
     * @api
     *
     * @var string
     */
    public const AUTO_UPDATE_SCOPE_PROGRAM_CHOICE = 'program_choice';

    /**
     * Specification:
     * - Name of the ACL role whose group members receive the auto-tune job's before/after summary email.
     *   Every member of every ACL group holding this role is notified — no per-metric or per-run
     *   subscriber list, deliberately the simplest option of those considered.
     * - Must exist as a real ACL role in the host shop (e.g. via `data:import acl-role`/the Zed ACL Gui)
     *   before the auto-tune job can resolve any recipient; a run that needs to notify but finds no
     *   admin holding this role simply sends to nobody, logged rather than treated as an error.
     *
     * @api
     *
     * @var string
     */
    public const AUTO_TUNE_NOTIFICATION_ROLE_NAME = 'search-score-admin';

    /**
     * Specification:
     * - How far a single automated weight-optimization run (Phase O6) may push relevanceWeight away from
     *   its OWN value at the moment the run started, in either direction, before clipping back to [0;1]
     *   at either edge -- a trust-region safety limit, not a general-purpose bound on relevanceWeight
     *   itself (which is always [0;1] regardless of this setting). Deliberately conservative by default:
     *   a single run finding "relevanceWeight should apparently be 0.05" from a 0.75 starting point is a
     *   signal worth a human's attention before being trusted outright, not something to let one run swing
     *   all the way to on its own.
     *
     * @api
     *
     * @return float
     */
    public static function getRelevanceWeightTrustRegionMaxDistance(): float
    {
        return 0.15;
    }

    /**
     * Specification:
     * - An optimization run just queued (via the Zed "Run now" button or a future cron tick), waiting for
     *   `search-ranking-optimizer:optimize` to pick it up. At most one run is ever processed per console
     *   invocation — the oldest queued — same "at most one at a time" discipline as Calibration.
     *
     * @api
     *
     * @var string
     */
    public const OPTIMIZATION_RUN_STATUS_QUEUED = 'queued';

    /**
     * Specification:
     * - The console command has picked this run up and is actively working through it — evolving
     *   generations, evaluating candidates. Backs the Zed page's live progress counter.
     *
     * @api
     *
     * @var string
     */
    public const OPTIMIZATION_RUN_STATUS_RUNNING = 'running';

    /**
     * Specification:
     * - The run finished successfully — a winning candidate (best_relevance_weight/best_metric_weights/
     *   best_score) is available for a human to review and, if they choose, Apply.
     *
     * @api
     *
     * @var string
     */
    public const OPTIMIZATION_RUN_STATUS_DONE = 'done';

    /**
     * Specification:
     * - The run stopped before producing a result — see the row's own error_message.
     *
     * @api
     *
     * @var string
     */
    public const OPTIMIZATION_RUN_STATUS_FAILED = 'failed';

    /**
     * Specification:
     * - {@see \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\CmaEsAlgorithm}.
     *
     * @api
     *
     * @var string
     */
    public const OPTIMIZATION_ALGORITHM_CMA_ES = 'cma_es';

    /**
     * Specification:
     * - {@see \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\DifferentialEvolutionAlgorithm}.
     *
     * @api
     *
     * @var string
     */
    public const OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION = 'differential_evolution';
}
