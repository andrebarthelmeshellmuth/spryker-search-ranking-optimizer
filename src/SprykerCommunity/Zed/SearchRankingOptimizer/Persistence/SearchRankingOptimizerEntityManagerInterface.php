<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Persistence;

use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use Spryker\Zed\Kernel\Persistence\EntityManager\TransactionHandlerInterface;

interface SearchRankingOptimizerEntityManagerInterface
{
    /**
     * Exposes the standard Spryker Propel transaction handler so multi-step business writes spanning
     * this entity manager and a cross-package facade (e.g. {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationApplier})
     * can be wrapped in one atomic transaction — both ultimately share the same underlying Propel
     * connection, so a transaction opened here also covers facade calls made inside the same callback.
     */
    public function getTransactionHandler(): TransactionHandlerInterface;

    /**
     * Creates a calibration run in status=uploaded together with one child row per
     * `$calibrationTransfer->getSearchTerms()` entry (search term text only, no scores yet).
     *
     * @param \Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer $calibrationTransfer
     */
    public function createCalibration(SearchRankingSaturationPointCalibrationTransfer $calibrationTransfer): SearchRankingSaturationPointCalibrationTransfer;

    /**
     * @param int $idSearchRankingSaturationPointCalibration
     * @param string $status
     */
    public function updateCalibrationStatus(int $idSearchRankingSaturationPointCalibration, string $status): void;

    /**
     * @param int $idSearchRankingSaturationPointCalibrationSearchTerm
     * @param int $productsFound
     * @param array<float> $scores
     */
    public function saveCalibrationSearchTermResult(int $idSearchRankingSaturationPointCalibrationSearchTerm, int $productsFound, array $scores): void;

    /**
     * Adds 1 to the calibration's `processedCount` — called once per search term as the calculation loop
     * works through them, the numerator half of the live progress counter. A safe no-op if the id no
     * longer exists.
     *
     * @param int $idSearchRankingSaturationPointCalibration
     */
    public function incrementCalibrationProcessedCount(int $idSearchRankingSaturationPointCalibration): void;

    /**
     * Persists the pooled statistics onto the calibration row and sets status=calculated.
     *
     * @param int $idSearchRankingSaturationPointCalibration
     * @param \Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer $statisticsTransfer
     */
    public function saveCalibrationStatistics(
        int $idSearchRankingSaturationPointCalibration,
        SearchRankingSaturationPointCalibrationTransfer $statisticsTransfer,
    ): void;

    /**
     * Sets status=failed with an explanatory error message.
     *
     * @param int $idSearchRankingSaturationPointCalibration
     * @param string $errorMessage
     */
    public function markCalibrationFailed(int $idSearchRankingSaturationPointCalibration, string $errorMessage): void;

    /**
     * Creates a new query row. `$queryTransfer` must already carry a CANONICAL `searchTerm` — canonicalize
     * before calling this (see {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizer}),
     * this layer does not re-derive it. `importanceWeight`, if unset, defaults to 1 via the column's own
     * schema default.
     *
     * @param \Generated\Shared\Transfer\SearchRankingQueryTransfer $queryTransfer
     */
    public function createQuery(SearchRankingQueryTransfer $queryTransfer): SearchRankingQueryTransfer;

    /**
     * @param int $idSearchRankingQuery
     * @param float $importanceWeight
     */
    public function updateQueryImportanceWeight(int $idSearchRankingQuery, float $importanceWeight): void;

    /**
     * Bumps `updated_at` with no other column change — called after every rating upsert so the query's
     * "last activity" sort (see {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface::findAllQueriesOrderedByUpdatedAt()})
     * reflects real rating activity, not just importance-weight edits.
     *
     * @param int $idSearchRankingQuery
     */
    public function touchQuery(int $idSearchRankingQuery): void;

    /**
     * UPSERTs one rater's judgment for a (query, product) pair: the same rater re-submitting for the same
     * pair updates the existing row in place (idempotent — a misclick is trivially fixed by clicking a
     * different button); a DIFFERENT rater on the same pair always gets their own row (see README for why
     * disagreement across raters is preserved, never overwritten). Also touches the parent query.
     *
     * @param \Generated\Shared\Transfer\SearchRankingQueryRatingTransfer $ratingTransfer
     */
    public function upsertRating(SearchRankingQueryRatingTransfer $ratingTransfer): SearchRankingQueryRatingTransfer;

    /**
     * Backs the widget's "click an already-pressed button to unselect" affordance — the same identifying
     * triple {@see upsertRating()} matches an existing row against. A safe no-op when there is nothing to
     * delete.
     *
     * @param int $fkSearchRankingQuery
     * @param string $customerReference
     * @param int $fkProductAbstract
     */
    public function deleteRating(int $fkSearchRankingQuery, string $customerReference, int $fkProductAbstract): void;

    /**
     * Persists one rank_eval evaluation run's query-importance-weighted aggregate score.
     *
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationTransfer $evaluationTransfer
     */
    public function createEvaluation(SearchRankingEvaluationTransfer $evaluationTransfer): SearchRankingEvaluationTransfer;

    /**
     * Persists one weight checkpoint (a full point-in-time snapshot).
     *
     * @param \Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer $weightCheckpointTransfer
     */
    public function createWeightCheckpoint(SearchRankingWeightCheckpointTransfer $weightCheckpointTransfer): SearchRankingWeightCheckpointTransfer;

    /**
     * Upserts by `(idSearchRankingMetric, storeName)` — at most one config row per metric+store.
     *
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     */
    public function saveAutoTuneMetricConfig(
        SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer,
    ): SearchRankingAutoTuneMetricConfigTransfer;

    /**
     * Queues a new optimization run — status=queued, store/locale/algorithm set, everything else at its
     * column default until the console command actually picks this run up.
     *
     * @param \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer $optimizerRunTransfer
     */
    public function createOptimizerRun(SearchRankingOptimizerRunTransfer $optimizerRunTransfer): SearchRankingOptimizerRunTransfer;

    /**
     * Transitions queued -> running: sets totalCount (the planned evaluation budget, population size *
     * generations) and baselineScore (the live configuration's own score at the moment this run starts,
     * for later "improved from X to Y" comparison) — both known only once the console command actually
     * starts working the run, never at queue time. A safe no-op if the id no longer exists.
     *
     * @param int $idSearchRankingOptimizerRun
     * @param int $totalCount
     * @param float $baselineScore
     */
    public function startOptimizerRun(int $idSearchRankingOptimizerRun, int $totalCount, float $baselineScore): void;

    /**
     * Sets processedCount to the given value (NOT an increment) — CMA-ES/DE naturally complete a whole
     * generation's worth of evaluations at once, so the console command calls this with the cumulative
     * total after each generation, not once per individual candidate. A safe no-op if the id no longer
     * exists.
     *
     * @param int $idSearchRankingOptimizerRun
     * @param int $processedCount
     */
    public function updateOptimizerRunProgress(int $idSearchRankingOptimizerRun, int $processedCount): void;

    /**
     * Transitions running -> done: persists the winning candidate and sets completedAt. A safe no-op if
     * the id no longer exists.
     *
     * @param int $idSearchRankingOptimizerRun
     * @param float $bestRelevanceWeight
     * @param array<\Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer> $bestMetricWeightTransfers
     * @param float $bestScore
     * @param float $bestSpecificityBlendWeight
     * @param float $bestSpecificityCurveExponent
     * @param float $bestSpecificityWeightExponent
     * @param float $bestSpecificityWeightShiftMagnitude
     */
    public function completeOptimizerRun(
        int $idSearchRankingOptimizerRun,
        float $bestRelevanceWeight,
        array $bestMetricWeightTransfers,
        float $bestScore,
        float $bestSpecificityBlendWeight,
        float $bestSpecificityCurveExponent,
        float $bestSpecificityWeightExponent,
        float $bestSpecificityWeightShiftMagnitude,
    ): void;

    /**
     * Transitions to failed with an explanatory error message. A safe no-op if the id no longer exists.
     *
     * @param int $idSearchRankingOptimizerRun
     * @param string $errorMessage
     */
    public function failOptimizerRun(int $idSearchRankingOptimizerRun, string $errorMessage): void;

    /**
     * Sets appliedAt to now — a run's winning candidate is applied at most meaningfully once (the Zed
     * page hides/disables Apply once this is set), but calling this again is harmless (just bumps the
     * timestamp). A safe no-op if the id no longer exists.
     *
     * @param int $idSearchRankingOptimizerRun
     */
    public function markOptimizerRunApplied(int $idSearchRankingOptimizerRun): void;
}
