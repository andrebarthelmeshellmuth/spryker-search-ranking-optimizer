<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business;

use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;

interface SearchRankingOptimizerFacadeInterface
{
    /**
     * Specification:
     * - Parses $csvContent (one search term per line) and creates a new calibration run in
     *   status=uploaded, with one child row per search term.
     * - Fires no search queries — {@see runNextCalibration()} does that, on its own schedule.
     *
     * @api
     *
     * @param int $relevantProductCount
     * @param string $storeName
     * @param string $localeName
     * @param string $csvContent
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer
     */
    public function createCalibration(int $relevantProductCount, string $storeName, string $localeName, string $csvContent): SearchRankingCalibrationTransfer;

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
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryRatingTransfer
     */
    public function submitProductRelevanceJudgment(SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer): SearchRankingQueryRatingTransfer;

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
}
