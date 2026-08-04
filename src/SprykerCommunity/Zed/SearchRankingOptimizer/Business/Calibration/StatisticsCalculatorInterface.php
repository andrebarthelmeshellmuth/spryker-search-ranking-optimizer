<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration;

use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;

interface StatisticsCalculatorInterface
{
    /**
     * Specification:
     * - Pools the given scores (the raw text-relevance scores of every sampled product across every
     *   search term of a calibration run) into min/max/mean/median/p25/p75 and a sample count.
     * - `computedK` is the pooled mean — the calibration's suggested `relevanceSaturationPoint`.
     * - Returns a transfer with ONLY the statistics properties set (no id, status, search terms).
     *
     * @param array<float> $scores
     */
    public function calculate(array $scores): SearchRankingCalibrationTransfer;
}
