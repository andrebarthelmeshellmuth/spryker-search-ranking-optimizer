<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Persistence;

use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;

interface SearchRankingOptimizerEntityManagerInterface
{
    /**
     * Creates a calibration run in status=uploaded together with one child row per
     * `$calibrationTransfer->getSearchTerms()` entry (search term text only, no scores yet).
     *
     * @param \Generated\Shared\Transfer\SearchRankingCalibrationTransfer $calibrationTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer
     */
    public function createCalibration(SearchRankingCalibrationTransfer $calibrationTransfer): SearchRankingCalibrationTransfer;

    /**
     * @param int $idSearchRankingCalibration
     * @param string $status
     *
     * @return void
     */
    public function updateCalibrationStatus(int $idSearchRankingCalibration, string $status): void;

    /**
     * @param int $idSearchRankingCalibrationSearchTerm
     * @param int $productsFound
     * @param array<float> $scores
     *
     * @return void
     */
    public function saveCalibrationSearchTermResult(int $idSearchRankingCalibrationSearchTerm, int $productsFound, array $scores): void;

    /**
     * Adds 1 to the calibration's `processedCount` — called once per search term as the calculation loop
     * works through them, the numerator half of the live progress counter. A safe no-op if the id no
     * longer exists.
     *
     * @param int $idSearchRankingCalibration
     *
     * @return void
     */
    public function incrementCalibrationProcessedCount(int $idSearchRankingCalibration): void;

    /**
     * Persists the pooled statistics onto the calibration row and sets status=calculated.
     *
     * @param int $idSearchRankingCalibration
     * @param \Generated\Shared\Transfer\SearchRankingCalibrationTransfer $statisticsTransfer
     *
     * @return void
     */
    public function saveCalibrationStatistics(int $idSearchRankingCalibration, SearchRankingCalibrationTransfer $statisticsTransfer): void;

    /**
     * Sets status=failed with an explanatory error message.
     *
     * @param int $idSearchRankingCalibration
     * @param string $errorMessage
     *
     * @return void
     */
    public function markCalibrationFailed(int $idSearchRankingCalibration, string $errorMessage): void;

    /**
     * Creates a new query row. `$queryTransfer` must already carry a CANONICAL `searchTerm` — canonicalize
     * before calling this (see {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizer}),
     * this layer does not re-derive it. `importanceWeight`, if unset, defaults to 1 via the column's own
     * schema default.
     *
     * @param \Generated\Shared\Transfer\SearchRankingQueryTransfer $queryTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryTransfer
     */
    public function createQuery(SearchRankingQueryTransfer $queryTransfer): SearchRankingQueryTransfer;

    /**
     * @param int $idSearchRankingQuery
     * @param float $importanceWeight
     *
     * @return void
     */
    public function updateQueryImportanceWeight(int $idSearchRankingQuery, float $importanceWeight): void;

    /**
     * Bumps `updated_at` with no other column change — called after every rating upsert so the query's
     * "last activity" sort (see {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface::findAllQueriesOrderedByUpdatedAt()})
     * reflects real rating activity, not just importance-weight edits.
     *
     * @param int $idSearchRankingQuery
     *
     * @return void
     */
    public function touchQuery(int $idSearchRankingQuery): void;

    /**
     * UPSERTs one rater's judgment for a (query, product) pair: the same rater re-submitting for the same
     * pair updates the existing row in place (idempotent — a misclick is trivially fixed by clicking a
     * different button); a DIFFERENT rater on the same pair always gets their own row (see README for why
     * disagreement across raters is preserved, never overwritten). Also touches the parent query.
     *
     * @param \Generated\Shared\Transfer\SearchRankingQueryRatingTransfer $ratingTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryRatingTransfer
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
     *
     * @return void
     */
    public function deleteRating(int $fkSearchRankingQuery, string $customerReference, int $fkProductAbstract): void;
}
