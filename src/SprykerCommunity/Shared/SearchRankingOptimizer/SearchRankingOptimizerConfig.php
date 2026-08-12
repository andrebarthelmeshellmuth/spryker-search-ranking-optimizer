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
     * - Calibration type: samples real per-product raw text-relevance scores from a live catalog query
     *   (BEFORE any function_score wrapper) — the ORIGINAL calibration path, suggesting
     *   `relevanceSaturationPoint`.
     *
     * @api
     *
     * @var string
     */
    public const CALIBRATION_TYPE_RELEVANCE_SCORE = 'relevance_score';

    /**
     * Specification:
     * - Calibration type: samples one raw specificity value per uploaded search term — no real catalog
     *   query at all, just a `_termvectors` probe against the term itself — suggesting
     *   `specificitySaturationPoint`.
     *
     * @api
     *
     * @var string
     */
    public const CALIBRATION_TYPE_SPECIFICITY = 'specificity';

    /**
     * Specification:
     * - A calibration run just uploaded, queued for the next `search-ranking-optimizer:calibrate` cron
     *   tick. At most one uploaded row is ever picked up per tick — the newest. Of the rest, only those
     *   targeting the SAME (storeName, localeName, calibrationType) move straight to
     *   {@see CALIBRATION_STATUS_SKIPPED}; uploads for another scope or type stay queued here for a later
     *   tick.
     *
     * @api
     *
     * @var string
     */
    public const CALIBRATION_STATUS_UPLOADED = 'uploaded';

    /**
     * Specification:
     * - A superseded upload: by the time the cron ran, a newer upload existed for the SAME
     *   (storeName, localeName, calibrationType), so this one was never calculated. Only a newer upload
     *   for that same target supersedes — one for another scope or another calibration type computes a
     *   different constant and leaves this row queued.
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
     *   {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\SaturationPointCalibrationSearcher}). A local copy
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
     */
    public static function getRankEvalCutoff(): int
    {
        return 10;
    }

    /**
     * Specification:
     * - A weight checkpoint taken directly by a Query Curator — either an explicit "take checkpoint now",
     *   or the checkpoint a restore itself writes to record the resulting state (restore is "apply",
     *   same as any other weight change). Distinct from `CHECKPOINT_SOURCE_OPTIMIZER`, which records
     *   checkpoints for automated optimizer runs instead.
     *
     * @api
     *
     * @var string
     */
    public const CHECKPOINT_SOURCE_MANUAL = 'manual';

    /**
     * Specification:
     * - The checkpoint recorded automatically when a human clicks Apply on an automated optimization
     *   run's winning candidate — distinct from `CHECKPOINT_SOURCE_MANUAL` so checkpoint
     *   history honestly shows which changes came from an optimizer run rather than a direct manual edit
     *   or restore.
     *
     * @api
     *
     * @var string
     */
    public const CHECKPOINT_SOURCE_OPTIMIZER = 'optimizer';

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
     * - How far a single automated weight-optimization run may push relevanceWeight away from
     *   its OWN value at the moment the run started, in either direction, before clipping back to [0;1]
     *   at either edge -- a trust-region safety limit, not a general-purpose bound on relevanceWeight
     *   itself (which is always [0;1] regardless of this setting). Deliberately conservative by default:
     *   a single run finding "relevanceWeight should apparently be 0.05" from a 0.75 starting point is a
     *   signal worth a human's attention before being trusted outright, not something to let one run swing
     *   all the way to on its own.
     *
     * @api
     */
    public static function getRelevanceWeightTrustRegionMaxDistance(): float
    {
        return 0.15;
    }

    /**
     * Specification:
     * - Same trust-region safety limit as {@see getRelevanceWeightTrustRegionMaxDistance()}, applied to
     *   `specificityWeightExponent` instead — how far a single run may push it away from its own value at
     *   the moment the run started, before clipping to the absolute bounds
     *   {@see getSpecificityWeightExponentLowerBound()}/{@see getSpecificityWeightExponentUpperBound()}.
     *
     * @api
     */
    public static function getSpecificityWeightExponentTrustRegionMaxDistance(): float
    {
        return 0.5;
    }

    /**
     * Specification:
     * - Absolute lower bound on `specificityWeightExponent` regardless of trust region — an exponent must
     *   stay strictly positive (0 or negative would make the shaped-deviation formula degenerate: `x ** 0`
     *   is always 1 regardless of `x`, discarding the specificity signal entirely; a negative exponent
     *   inverts it in an unintended way).
     *
     * @api
     */
    public static function getSpecificityWeightExponentLowerBound(): float
    {
        return 0.1;
    }

    /**
     * Specification:
     * - Absolute upper bound on `specificityWeightExponent` — deliberately generous (an exponent this high
     *   makes the shift's response to specificity extremely steep/near-binary, a real if aggressive choice
     *   a run should be allowed to reach, not artificially capped tighter than that).
     *
     * @api
     */
    public static function getSpecificityWeightExponentUpperBound(): float
    {
        return 5.0;
    }

    /**
     * Specification:
     * - Same trust-region safety limit as {@see getRelevanceWeightTrustRegionMaxDistance()}, applied to
     *   `specificityCurveExponent` instead — how far a single run may push it away from its own value at
     *   the moment the run started, before clipping to the absolute bounds
     *   {@see getSpecificityCurveExponentLowerBound()}/{@see getSpecificityCurveExponentUpperBound()}.
     *
     * @api
     */
    public static function getSpecificityCurveExponentTrustRegionMaxDistance(): float
    {
        return 0.5;
    }

    /**
     * Specification:
     * - Absolute lower bound on `specificityCurveExponent` regardless of trust region — same reasoning as
     *   {@see getSpecificityWeightExponentLowerBound()}: the Hill-equation curve `raw^p / (raw^p + k^p)`
     *   degenerates at `p <= 0` (0 collapses every input to the constant 0.5, discarding the specificity
     *   signal entirely; negative values invert the curve's monotonicity in an unintended way).
     *
     * @api
     */
    public static function getSpecificityCurveExponentLowerBound(): float
    {
        return 0.1;
    }

    /**
     * Specification:
     * - Absolute upper bound on `specificityCurveExponent` — same magnitude as
     *   {@see getSpecificityWeightExponentUpperBound()}, deliberately generous (a value this high makes the
     *   normalized-specificity curve extremely steep/near-binary around the saturation point, a real if
     *   aggressive choice a run should be allowed to reach, not artificially capped tighter than that).
     *
     * @api
     */
    public static function getSpecificityCurveExponentUpperBound(): float
    {
        return 5.0;
    }

    /**
     * Specification:
     * - Same trust-region safety limit as {@see getRelevanceWeightTrustRegionMaxDistance()}, applied to
     *   `specificityWeightShiftMagnitude` instead.
     *
     * @api
     */
    public static function getSpecificityWeightShiftMagnitudeTrustRegionMaxDistance(): float
    {
        return 0.1;
    }

    /**
     * Specification:
     * - Absolute bounds on `specificityWeightShiftMagnitude` regardless of trust region — a shift can never
     *   usefully exceed the [0;1] range `relevanceWeight` itself lives in (the calculated relevanceWeight
     *   is clamped to [0;1] regardless, so anything past 1.0 would only ever be reached by an
     *   already-maximal specificity deviation, making values beyond 1.0 indistinguishable from 1.0 itself).
     *
     * @api
     */
    public static function getSpecificityWeightShiftMagnitudeLowerBound(): float
    {
        return 0.0;
    }

    /**
     * @api
     */
    public static function getSpecificityWeightShiftMagnitudeUpperBound(): float
    {
        return 1.0;
    }

    /**
     * Specification:
     * - Same trust-region safety limit as {@see getRelevanceWeightTrustRegionMaxDistance()}, applied to
     *   `specificityBlendWeight` instead.
     *
     * @api
     */
    public static function getSpecificityBlendWeightTrustRegionMaxDistance(): float
    {
        return 0.2;
    }

    /**
     * Specification:
     * - Absolute bounds on `specificityBlendWeight` (alpha) regardless of trust region — it's a blend
     *   weight between `max(idf)` and `harmonicMean(idf)`, so it must stay within [0;1] by definition (0 =
     *   pure harmonic mean, 1 = pure max).
     *
     * @api
     */
    public static function getSpecificityBlendWeightLowerBound(): float
    {
        return 0.0;
    }

    /**
     * @api
     */
    public static function getSpecificityBlendWeightUpperBound(): float
    {
        return 1.0;
    }

    /**
     * Specification:
     * - Generation count both CmaEsAlgorithm and DifferentialEvolutionAlgorithm run for, when
     *   OptimizationRunner drives an optimizer run — a fixed stopping criterion, not a fitness-plateau
     *   detector (matches both algorithms' own kept-simple stopping rule). Also used, together with a
     *   population size derived from the parameter vector's own dimensionality, to compute the progress
     *   counter's denominator before a run actually starts.
     *
     * @api
     */
    public static function getOptimizationMaxGenerations(): int
    {
        return 150;
    }

    /**
     * Specification:
     * - The bound (both directions) placed on each free z value {@see \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Reparametrization\SimplexSoftmaxReparametrization}
     *   works over — deliberately finite, NOT the mathematically-unconstrained +-INF that reparametrization
     *   alone would allow. Both CmaEsAlgorithm (needs a finite midpoint to default its initial mean to) and
     *   DifferentialEvolutionAlgorithm (samples its initial population uniformly WITHIN the given bounds,
     *   which is undefined arithmetic against an infinite range) need real, finite bounds to even start a
     *   run — a real constraint, not a hypothetical one. 10.0 is already an extreme corner of the simplex (a
     *   softmax ratio of e^10 =~ 22000:1 against the other metrics), so this is not a meaningful
     *   restriction on what the optimizer can actually reach in practice.
     *
     * @api
     */
    public static function getMetricWeightZSpaceBound(): float
    {
        return 10.0;
    }

    /**
     * Specification:
     * - Function names, as they appear in a `search-ranking` metric's own formula DSL (see that package's
     *   `MathFunctionProvider`), that make a formula's output non-deterministic across evaluations —
     *   `random()` is the one such function that ships there today, deliberately returning a fresh
     *   uniformly-distributed value on every call. A metric whose formula calls one of these is a
     *   placeholder/noise signal, not a real business signal: automated weight optimization excludes it
     *   from the searchable simplex (its weight is held fixed instead — see
     *   {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\ParameterVectorMapper}),
     *   and auto-tune never applies a refit to it (fitting a "better" curve to noise would just overfit to
     *   whatever randomness happened to be in that digest snapshot, then silently swap in a formula that
     *   *looks* like a real fit but carries no more signal than before) — though it's still checked and
     *   logged normally, since observing a persistently bad fit is legitimate, only auto-*applying* a
     *   refit for one isn't.
     *
     * @api
     *
     * @return array<int, string>
     */
    public static function getNonDeterministicFormulaFunctionNames(): array
    {
        return ['random'];
    }

    /**
     * Specification:
     * - Shown only as the Auto-Tune Settings form field's greyed `placeholder` — never written as an
     *   actual value. A metric with no threshold set is deliberately opted OUT of auto-tune entirely (see
     *   {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\AutoTuneMetricConfigForm}), so
     *   this must never be auto-filled into a real, saved value: that would silently opt every existing
     *   metric in the moment this constant shipped. 0.85 is a reasonable general-purpose starting point
     *   for "this fit is good enough, don't bother refitting" — adjust per metric based on its own
     *   Current fit (R²) column once real data is in.
     *
     * @api
     */
    public static function getAutoTuneThresholdSuggestedDefault(): float
    {
        return 0.85;
    }

    /**
     * Specification:
     * - Minimum spread (max R² − min R²) across a metric's real locales, within one store, before
     *   `search-ranking-optimizer:auto-tune`'s own console report flags it as a real per-locale fit
     *   divergence worth a human's attention. Purely informational today — Auto-Tune itself still only
     *   checks/refits each store's default locale (see {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunner}'s
     *   own docblock), so nothing here acts on a flagged divergence automatically; it exists so a curator
     *   can see when a store-wide formula is quietly a much worse fit for one locale than another — the
     *   evidence `search-ranking`'s own `isLocaleScoped` flag asks for before a metric should be flipped
     *   to genuinely per-locale. 0.1 is a coarse "this is probably not just noise" starting point, not a
     *   validated statistical threshold — adjust per project once real multi-locale data exists to judge
     *   it against.
     *
     * @api
     */
    public static function getLocaleFitDivergenceWarningThreshold(): float
    {
        return 0.1;
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
     * - {@see \BlackboxOptimizer\Algorithm\CmaEsAlgorithm}.
     *
     * @api
     *
     * @var string
     */
    public const OPTIMIZATION_ALGORITHM_CMA_ES = 'cma_es';

    /**
     * Specification:
     * - {@see \BlackboxOptimizer\Algorithm\DifferentialEvolutionAlgorithm}.
     *
     * @api
     *
     * @var string
     */
    public const OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION = 'differential_evolution';

    /**
     * Specification:
     * - {@see \BlackboxOptimizer\Algorithm\RechenbergSchwefelEsAlgorithm}.
     *
     * @api
     *
     * @var string
     */
    public const OPTIMIZATION_ALGORITHM_RECHENBERG_SCHWEFEL_ES = 'rechenberg_schwefel_es';
}
