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
     * - When $csvContent is null (the default path), sources search terms from the distinct, organically
     *   rated queries already collected via the SRP widget for this store/locale — no upload needed.
     * - When $csvContent is given (the bootstrap/test path, behind an explicit opt-in checkbox in the Zed
     *   form), parses it into search terms instead, bypassing organic queries entirely.
     * - Either way, creates a new calibration run in status=uploaded, with one child row per search term.
     * - Does not fire any search queries — that happens later, when `search-ranking-optimizer:calibrate`
     *   picks this run up.
     * - $calibrationType is one of `SearchRankingOptimizerConfig::CALIBRATION_TYPE_*` — decides which
     *   sampling path the cron takes (real per-product catalog query vs. a per-term `_termvectors` probe),
     *   see {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\ScoreCalibratorInterface}.
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
}
