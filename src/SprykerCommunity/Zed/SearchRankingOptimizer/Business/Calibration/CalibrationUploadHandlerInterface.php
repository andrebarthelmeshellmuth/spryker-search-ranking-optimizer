<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration;

use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;

interface CalibrationUploadHandlerInterface
{
    /**
     * Specification:
     * - Parses $csvContent into search terms and creates a new calibration run in status=uploaded, with
     *   one child row per parsed search term.
     * - Does not fire any search queries — that happens later, when `search-ranking:calibrate` picks
     *   this run up.
     *
     * @param int $relevantProductCount
     * @param string $storeName
     * @param string $localeName
     * @param string $csvContent
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer
     */
    public function createCalibration(int $relevantProductCount, string $storeName, string $localeName, string $csvContent): SearchRankingCalibrationTransfer;
}
