<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business;

use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;

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
}
