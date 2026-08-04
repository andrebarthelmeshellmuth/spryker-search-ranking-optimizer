<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business;

use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Generated\Shared\Transfer\SearchRankingAutoTuneResultTransfer;
use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;

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
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer
     */
    public function createCalibration(
        string $calibrationType,
        int $relevantProductCount,
        string $storeName,
        string $localeName,
        ?string $csvContent = null,
    ): SearchRankingCalibrationTransfer;

    /**
     * Specification:
     * - Picks the newest calibration run in status=uploaded (if any), marks every OTHER uploaded run as
     *   status=skipped, fires the calibration query per search term, pools the scores and persists the
     *   statistics (status=calculated), or status=failed when no score could be collected. Returns null
     *   when there is nothing to run.
     *
     * @api
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer|null
     */
    public function runNextCalibration(): ?SearchRankingCalibrationTransfer;

    /**
     * Specification:
     * - Returns the most recently finished (status=calculated) calibration run, or null when none has
     *   ever finished.
     *
     * @api
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer|null
     */
    public function findLatestCalculatedCalibration(): ?SearchRankingCalibrationTransfer;

    /**
     * Specification:
     * - Returns the run currently in status=calculating, if any — at most one at a time by design. Backs
     *   the Calibration page's live progress counter.
     *
     * @api
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer|null
     */
    public function findCalibrationInProgress(): ?SearchRankingCalibrationTransfer;

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
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryRatingTransfer
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
     *
     * @return void
     */
    public function clearProductRelevanceJudgment(SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer): void;

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
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryTransfer|null
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
     *
     * @return void
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
     *
     * @return \Generated\Shared\Transfer\SearchRankingEvaluationTransfer|null
     */
    public function runRankEvaluation(string $storeName, string $localeName): ?SearchRankingEvaluationTransfer;

    /**
     * Specification:
     * - Returns the most recently persisted evaluation run for (storeName, localeName), or null when none
     *   has ever run.
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return \Generated\Shared\Transfer\SearchRankingEvaluationTransfer|null
     */
    public function findLatestEvaluation(string $storeName, string $localeName): ?SearchRankingEvaluationTransfer;

    /**
     * Specification:
     * - Returns every persisted evaluation run for (storeName, localeName), newest first.
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingEvaluationTransfer>
     */
    public function findEvaluationHistory(string $storeName, string $localeName): array;

    /**
     * Specification:
     * - Reads the CURRENT state directly from `search-ranking`'s own facade — relevanceWeight, every
     *   metric's own weight, the 3 specificity-weighting knobs, and whether specificity weighting is currently
     *   enabled at the code level — and persists it as one new weight checkpoint.
     *
     * @api
     *
     * @param string $source
     * @param string $storeName
     * @param string $localeName
     *
     * @return \Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer
     */
    public function recordWeightCheckpoint(string $source, string $storeName, string $localeName): SearchRankingWeightCheckpointTransfer;

    /**
     * Specification:
     * - Writes a past checkpoint's relevanceWeight, metric weights, and 3 specificity knobs back through
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
     *
     * @return \Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer|null
     */
    public function restoreWeightCheckpoint(
        int $idSearchRankingWeightCheckpoint,
        string $storeName,
        string $localeName,
    ): ?SearchRankingWeightCheckpointTransfer;

    /**
     * Specification:
     * - Returns every persisted weight checkpoint, newest first.
     *
     * @api
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer>
     */
    public function findWeightCheckpointHistory(): array;

    /**
     * Specification:
     * - Returns null when the metric has no auto-tune config yet (has never had a threshold set) — a
     *   safe, expected state for most metrics, not an error.
     *
     * @api
     *
     * @param int $idSearchRankingMetric
     *
     * @return \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer|null
     */
    public function findAutoTuneMetricConfigByMetricId(int $idSearchRankingMetric): ?SearchRankingAutoTuneMetricConfigTransfer;

    /**
     * Specification:
     * - Only configs with a real threshold set — a metric with no config row, or an explicit NULL
     *   threshold, has opted out of auto-tune entirely and is simply absent here.
     *
     * @api
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer>
     */
    public function findAutoTuneMetricConfigsWithThresholdSet(): array;

    /**
     * Specification:
     * - Upserts by `idSearchRankingMetric` — at most one config row per metric.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer
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
     *
     * @return \Generated\Shared\Transfer\SearchRankingAutoTuneResultTransfer
     */
    public function runAutoTune(): SearchRankingAutoTuneResultTransfer;

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
     *
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer
     */
    public function queueOptimizationRun(string $storeName, string $localeName, string $algorithm): SearchRankingOptimizerRunTransfer;

    /**
     * Specification:
     * - Picks up and fully processes the oldest still-queued optimization run — see
     *   {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationRunnerInterface::runNext()}
     *   for the full specification. Returns null when nothing is queued.
     *
     * @api
     *
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer|null
     */
    public function runNextOptimization(): ?SearchRankingOptimizerRunTransfer;

    /**
     * Specification:
     * - The run currently being worked, if any — backs a live Zed-page progress counter, safe to poll.
     *
     * @api
     *
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer|null
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
     *
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer|null
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
     *
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer|null
     */
    public function applyOptimizationRun(int $idSearchRankingOptimizerRun): ?SearchRankingOptimizerRunTransfer;
}
