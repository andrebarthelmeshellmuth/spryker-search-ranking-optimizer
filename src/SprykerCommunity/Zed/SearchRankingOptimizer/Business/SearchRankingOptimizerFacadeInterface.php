<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business;

use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Generated\Shared\Transfer\SearchRankingAutoTuneNotificationDiagnosisTransfer;
use Generated\Shared\Transfer\SearchRankingAutoTuneResultTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Generated\Shared\Transfer\SearchRankingHybridComparisonTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchResponseTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

interface SearchRankingOptimizerFacadeInterface
{
    /**
     * Specification:
     * - When $csvContent is null (the default), sources search terms from the distinct, organically rated
     *   queries already collected via the SRP widget for this store/locale.
     * - When $csvContent is given (one search term per line), parses it into search terms instead,
     *   bypassing organic queries — a bootstrap/test path, not the primary one.
     * - Either way, creates a new calibration run in status=uploaded, with one child row per search term.
     * - Fires no search queries — {@see runNextCalibration()} does that, on its own schedule.
     * - $calibrationType is one of `SearchRankingOptimizerConfig::CALIBRATION_TYPE_*`.
     *
     * @api
     *
     * @param string $calibrationType
     * @param int $relevantProductCount
     * @param string $storeName
     * @param string $localeName
     * @param string|null $csvContent
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\NoSearchTermsAvailableException
     */
    public function createCalibration(
        string $calibrationType,
        int $relevantProductCount,
        string $storeName,
        string $localeName,
        ?string $csvContent = null,
    ): SearchRankingSaturationPointCalibrationTransfer;

    /**
     * Specification:
     * - Picks the newest calibration run in status=uploaded (if any), marks as status=skipped every
     *   OTHER uploaded run for that same (storeName, localeName, calibrationType) — and only those,
     *   leaving uploads for any other scope or type queued for a later tick — fires the calibration query
     *   per search term, pools the scores and persists the statistics (status=calculated), or
     *   status=failed when no score could be collected. Returns null when there is nothing to run.
     *
     * @api
     */
    public function runNextCalibration(): ?SearchRankingSaturationPointCalibrationTransfer;

    /**
     * Specification:
     * - Returns the most recently finished (status=calculated) calibration run for this (store, locale),
     *   or null when none has ever finished there.
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     */
    public function findLatestCalculatedCalibration(string $storeName, string $localeName): ?SearchRankingSaturationPointCalibrationTransfer;

    /**
     * Specification:
     * - Returns the run for this (store, locale) currently in status=calculating, if any — at most one
     *   SYSTEM-WIDE at a time by design, but that one run may be for a different scope than the one asked
     *   about here. Backs the Calibration page's live progress counter.
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     */
    public function findCalibrationInProgress(string $storeName, string $localeName): ?SearchRankingSaturationPointCalibrationTransfer;

    /**
     * Specification:
     * - Canonicalizes the request's raw search term, finds-or-creates the matching query row, then
     *   upserts the rater's judgment for that (query, product) pair — the same rater re-submitting for
     *   the same pair updates their existing row in place; a different rater on the same pair always gets
     *   their own row (disagreement is a signal, never overwritten).
     * - Caller (the Gateway Controller) is responsible for authorization — this method does not itself
     *   check the RateSearchRelevancePermissionPlugin permission.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\InvalidRatingTypeException
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\ProductNotInSearchResultsException
     */
    public function submitProductRelevanceJudgment(SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer): SearchRankingQueryRatingTransfer;

    /**
     * Specification:
     * - Canonicalizes the request's raw search term, looks up the matching query row (never creates one),
     *   and deletes the rater's judgment for that (query, product) pair, if any. A safe no-op if there was
     *   nothing to clear.
     * - Caller (the Gateway Controller) is responsible for authorization, same as
     *   {@see submitProductRelevanceJudgment()}.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     */
    public function clearProductRelevanceJudgment(SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer): void;

    /**
     * Specification:
     * - Canonicalizes the request's raw search term, looks up the matching query row (never creates one —
     *   a query nobody has ever rated has no ratings to return), and returns this customer's own ratings
     *   for whichever of the requested product-abstract ids they have actually rated. A query that does
     *   not exist yet, or an empty idProductAbstracts list, both return successfully with an empty ratings
     *   array — neither is an error, just "nothing to show".
     * - Caller (the Gateway Controller) is responsible for authorization, same as
     *   {@see submitProductRelevanceJudgment()}.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer $requestTransfer
     */
    public function getProductRelevanceJudgments(
        SearchRankingProductRelevanceJudgmentBatchRequestTransfer $requestTransfer,
    ): SearchRankingProductRelevanceJudgmentBatchResponseTransfer;

    /**
     * Specification:
     * - Returns every rated query, newest-activity-first (`updated_at` DESC, bumped on every new rating —
     *   not just an importance-weight edit).
     *
     * @api
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingQueryTransfer>
     */
    public function getQueries(): array;

    /**
     * Specification:
     * - Returns null if the id no longer exists.
     *
     * @api
     *
     * @param int $idSearchRankingQuery
     */
    public function findQueryById(int $idSearchRankingQuery): ?SearchRankingQueryTransfer;

    /**
     * Specification:
     * - Sets `importanceWeight` on the given query. A safe no-op if the id no longer exists.
     *
     * @api
     *
     * @param int $idSearchRankingQuery
     * @param float $importanceWeight
     */
    public function updateQueryImportanceWeight(int $idSearchRankingQuery, float $importanceWeight): void;

    /**
     * Specification:
     * - Fires one batched `_rank_eval` evaluation across every rated query for (storeName, localeName),
     *   persists a query-importance-weighted nDCG aggregate, and returns it.
     * - Returns null (persists nothing) when there is nothing to evaluate for that store/locale.
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     */
    public function runRankEvaluation(string $storeName, string $localeName): ?SearchRankingEvaluationTransfer;

    /**
     * Specification:
     * - P4's own lexical-vs-hybrid comparison (`search-ranking-optimizer:evaluate-hybrid`): runs the SAME
     *   judged query set for (storeName, localeName) through two ranking configurations, both cloned from
     *   the LIVE synced configuration — one with `alpha` forced to `1.0` ("lexical", an unambiguous
     *   baseline regardless of what the live config's own alpha currently is), one with `alpha` set to
     *   `$alpha` ("hybrid").
     * - Never persists anything (unlike {@see runRankEvaluation()}) — this is a read-only, on-demand
     *   comparison report.
     * - Returns an EMPTY transfer (empty `queryComparisons`, `0.0` aggregates), never `null`, when there is
     *   nothing to evaluate for that store/locale.
     *
     * - $fusionMode selects the "hybrid" side's own fusion mode (see
     *   `SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig::FUSION_MODE_*`) —
     *   defaults to `FUSION_MODE_LINEAR`, matching every pre-RRF caller unchanged. The "lexical" baseline
     *   is ALWAYS `FUSION_MODE_LINEAR` with alpha forced to `1.0`, regardless of $fusionMode.
     * - $brandShift/$categoryShift set the "hybrid" side's candidate `brandMatchRelevanceWeightShift`/
     *   `categoryMatchRelevanceWeightShift` ONLY — both default to `0.0` (today's real production default,
     *   inert), matching every pre-existing caller unchanged. The "lexical" baseline is ALWAYS forced to
     *   `0.0` for both, regardless of $brandShift/$categoryShift.
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     * @param float $alpha
     * @param string $fusionMode
     * @param float $brandShift
     * @param float $categoryShift
     */
    public function compareLexicalVsHybrid(
        string $storeName,
        string $localeName,
        float $alpha,
        string $fusionMode = SearchRankingOptimizerConfig::FUSION_MODE_LINEAR,
        float $brandShift = 0.0,
        float $categoryShift = 0.0,
    ): SearchRankingHybridComparisonTransfer;

    /**
     * Specification:
     * - Returns the most recently persisted evaluation run for (storeName, localeName), or null when none
     *   has ever run.
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     */
    public function findLatestEvaluation(string $storeName, string $localeName): ?SearchRankingEvaluationTransfer;

    /**
     * Specification:
     * - Returns every persisted evaluation run, newest first.
     * - Null $storeName/$localeName means "no filter" (every store/locale); a non-null value narrows to
     *   that scope only.
     *
     * @api
     *
     * @param string|null $storeName
     * @param string|null $localeName
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingEvaluationTransfer>
     */
    public function findEvaluationHistory(?string $storeName = null, ?string $localeName = null): array;

    /**
     * Specification:
     * - Reads the CURRENT state directly from `search-ranking`'s own facade — relevanceWeight, every
     *   metric's own weight, the 4 specificity-weighting knobs, and whether specificity weighting is currently
     *   enabled at the code level — and persists it as one new weight checkpoint.
     *
     * @api
     *
     * @param string $source
     * @param string $storeName
     * @param string $localeName
     */
    public function recordWeightCheckpoint(string $source, string $storeName, string $localeName): SearchRankingWeightCheckpointTransfer;

    /**
     * Specification:
     * - Writes a past checkpoint's relevanceWeight, metric weights, and 4 specificity knobs back through
     *   `search-ranking`'s own facade, for the given (store, locale) — independent of whichever scope the
     *   checkpoint was originally recorded for, skipping any metric that no longer exists.
     * - Never writes back `isSpecificityWeightingEnabled` — it's a pure code-level project flag with no
     *   corresponding save method.
     * - Records a NEW checkpoint of the resulting state (source `manual`), for the same (store, locale) it
     *   was just restored into, and returns it — restoring IS applying, so it gets its own checkpoint like
     *   any other applied change.
     * - Returns null (writes nothing) when the given id doesn't exist.
     *
     * @api
     *
     * @param int $idSearchRankingWeightCheckpoint
     * @param string $storeName
     * @param string $localeName
     */
    public function restoreWeightCheckpoint(
        int $idSearchRankingWeightCheckpoint,
        string $storeName,
        string $localeName,
    ): ?SearchRankingWeightCheckpointTransfer;

    /**
     * Specification:
     * - Returns every persisted weight checkpoint, newest first.
     * - Null $storeName/$localeName means "no filter" (every store/locale); a non-null value narrows to
     *   that scope only.
     *
     * @api
     *
     * @param string|null $storeName
     * @param string|null $localeName
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer>
     */
    public function findWeightCheckpointHistory(?string $storeName = null, ?string $localeName = null): array;

    /**
     * Specification:
     * - Returns null when the metric has no auto-tune config yet FOR THIS (store, locale) — a safe,
     *   expected state for most metric+store+locale combinations, not an error. Auto-tune config is
     *   store+locale scoped, matching `search-ranking`'s own formula/shape scoping: for an
     *   `isLocaleScoped=false` metric (the common case) {@see saveAutoTuneMetricConfig()} fans one save out
     *   to every real locale of the store, so any locale's row reflects the same config; for an
     *   `isLocaleScoped=true` metric each locale is independent and may simply have no row yet.
     *
     * @api
     *
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     */
    public function findAutoTuneMetricConfigByMetricId(
        int $idSearchRankingMetric,
        string $storeName,
        string $localeName,
    ): ?SearchRankingAutoTuneMetricConfigTransfer;

    /**
     * Specification:
     * - Only configs with a real threshold set for THIS store — a metric with no config row for a given
     *   (store, locale), or an explicit NULL threshold, has opted out of auto-tune entirely for that scope
     *   and is simply absent here. Store-scoped only, not locale-filtered — can return several rows for
     *   the same metric, one per locale it's been independently configured at.
     *
     * @api
     *
     * @param string $storeName
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer>
     */
    public function findAutoTuneMetricConfigsWithThresholdSet(string $storeName): array;

    /**
     * Specification:
     * - Upserts by `(idSearchRankingMetric, storeName, localeName)` — at most one config row per
     *   metric+store+locale, but a single call here can write MORE than one row: for an
     *   `isLocaleScoped=false` metric (the common case), the given config is fanned out to every real
     *   locale of the store (same fan-out `search-ranking` itself already applies to formula/isActive/shape/
     *   weight); for an `isLocaleScoped=true` metric, only the one named (store, locale) is written. The
     *   returned transfer is always the one for the caller's own requested (store, locale).
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     */
    public function saveAutoTuneMetricConfig(
        SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer,
    ): SearchRankingAutoTuneMetricConfigTransfer;

    /**
     * Specification:
     * - Runs the monthly auto-tune check across every metric with an auto-tune threshold set — see
     *   {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunnerInterface::run()}
     *   for the full specification.
     *
     * @api
     */
    public function runAutoTune(): SearchRankingAutoTuneResultTransfer;

    /**
     * Specification:
     * - Reports whether the auto-tune summary email can actually reach anybody, and if not, why — see
     *   {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationDiagnoserInterface::diagnose()}
     *   for the full specification.
     * - A read-only diagnostic, not part of the auto-tune run itself: {@see runAutoTune()} never consults
     *   it and its own behavior is unchanged by anything here. Exists because every way the notification
     *   can reach nobody is silent — the run still succeeds — so something has to be able to ask.
     *
     * @api
     */
    public function getAutoTuneNotificationDiagnosis(): SearchRankingAutoTuneNotificationDiagnosisTransfer;

    /**
     * Specification:
     * - Queues a new optimization run in status=queued — nothing runs yet, that happens the next time
     *   {@see runNextOptimization()} is called (a console command tick, in practice).
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     * @param string $algorithm SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_*.
     * @param string $terminationMode One of `SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_*`.
     *   Defaults to `OPTIMIZATION_TERMINATION_MODE_FIXED_BUDGET`. See `AlgorithmFactoryInterface::create()`'s
     *   own docblock for what each value does.
     * @param float $warmStartFraction How much of the search is seeded from the live configuration instead
     *   of starting cold, between 0.0 and 1.0. Defaults to 0.0.
     * @param float|null $fixedRelevanceWeight A human's own choice, made on the run form's parameter
     *   checklist, to pin relevanceWeight at exactly this value instead of searching it. Null (the default)
     *   preserves the original always-free behavior.
     * @param float|null $fixedSpecificityCurveExponent Same pin choice as $fixedRelevanceWeight, for
     *   specificityCurveExponent. Meaningless (ignored) when specificity weighting is disabled.
     * @param float|null $fixedSpecificityWeightExponent Same, for specificityWeightExponent.
     * @param float|null $fixedSpecificityWeightShiftMagnitude Same, for specificityWeightShiftMagnitude.
     * @param float|null $fixedSpecificityBlendWeight Same, for specificityBlendWeight.
     * @param array<\Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer> $fixedMetricWeights
     *   Metrics a human chose to pin at queue time, at whatever value they entered (not necessarily the
     *   live one). A metric NOT listed here can still end up held constant anyway if its own formula turns
     *   out non-deterministic — that's {@see runNextOptimization()}'s own orthogonal decision.
     */
    public function queueOptimizationRun(
        string $storeName,
        string $localeName,
        string $algorithm,
        string $terminationMode = SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_FIXED_BUDGET,
        float $warmStartFraction = 0.0,
        ?float $fixedRelevanceWeight = null,
        ?float $fixedSpecificityCurveExponent = null,
        ?float $fixedSpecificityWeightExponent = null,
        ?float $fixedSpecificityWeightShiftMagnitude = null,
        ?float $fixedSpecificityBlendWeight = null,
        array $fixedMetricWeights = [],
    ): SearchRankingOptimizerRunTransfer;

    /**
     * Specification:
     * - Read-only snapshot of every scalar/metric an optimization run for this (store, locale) would work
     *   with RIGHT NOW — feeds the Automated Weight Optimization run form's own parameter checklist
     *   (which one of these the human wants to search vs. pin), never creates or touches a run.
     * - `metrics` omits any metric whose formula is non-deterministic: that metric is held fixed at its
     *   live weight unconditionally, no checklist choice can ever change that.
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return array{
     *     relevanceWeight: float,
     *     isSpecificityWeightingEnabled: bool,
     *     specificityCurveExponent: float,
     *     specificityWeightExponent: float,
     *     specificityWeightShiftMagnitude: float,
     *     specificityBlendWeight: float,
     *     metrics: array<int, array{idSearchRankingMetric: int, name: string, weight: float}>,
     * }
     */
    public function listOptimizableParameters(string $storeName, string $localeName): array;

    /**
     * Specification:
     * - One unconfigured instance per known `SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_*` value,
     *   keyed by that value — metadata only (`getName()`/`getDescription()`), never `optimize()`d. Used to
     *   render algorithm choices/help text; {@see queueOptimizationRun()} is what actually runs one.
     *
     * @api
     *
     * @return array<string, \BlackboxOptimizer\Algorithm\OptimizerAlgorithmInterface>
     */
    public function getOptimizationAlgorithms(): array;

    /**
     * Specification:
     * - Picks up and fully processes the oldest still-queued optimization run — see
     *   {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationRunnerInterface::runNext()}
     *   for the full specification. Returns null when nothing is queued.
     *
     * @api
     */
    public function runNextOptimization(): ?SearchRankingOptimizerRunTransfer;

    /**
     * Specification:
     * - The run currently being worked, if any — backs a live Zed-page progress counter, safe to poll.
     *
     * @api
     */
    public function findOptimizerRunInProgress(): ?SearchRankingOptimizerRunTransfer;

    /**
     * Specification:
     * - The most recently created optimization run for a given store/locale, regardless of status.
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     */
    public function findLatestOptimizerRunByStoreLocale(string $storeName, string $localeName): ?SearchRankingOptimizerRunTransfer;

    /**
     * Specification:
     * - Writes a done run's winning candidate through search-ranking's own facade and records a new
     *   weight checkpoint (source = optimizer) — see
     *   {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationApplierInterface::apply()}
     *   for the full specification. Returns null when the run doesn't exist or isn't status=done yet.
     * - Callers MUST also republish (search-ranking-storage's own facade) afterward for this to actually
     *   reach the live storefront — same discipline the Calibration apply action and the Checkpoint
     *   restore action both already follow; this Facade method only writes Zed-side settings.
     *
     * @api
     *
     * @param int $idSearchRankingOptimizerRun
     */
    public function applyOptimizationRun(int $idSearchRankingOptimizerRun): ?SearchRankingOptimizerRunTransfer;
}
