<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration;

use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;

interface ScoreCalibratorInterface
{
    /**
     * Specification:
     * - Looks up every calibration run in status=uploaded. Returns null when there is none.
     * - Marks every one of them EXCEPT the newest (highest id) as status=skipped, without ever firing a
     *   search query for them.
     * - Runs the newest one: for each of its search terms, fires the real, fully-wired catalog
     *   search-string query with `explain: true` and the term's own top-N limit (the run's
     *   relevantProductCount), extracts each hit's raw text-relevance score (BEFORE any function_score
     *   wrapper, regardless of whether one happens to be wired in) and persists it against that search
     *   term row. A single search term's query failing is logged and treated as 0 products found —
     *   it does not abort the run.
     * - Pools every collected score across every search term and persists the statistics
     *   (see {@see StatisticsCalculatorInterface}), setting status=calculated.
     * - When not a single score was collected across every search term (e.g. every term failed or
     *   matched nothing), sets status=failed with an explanatory errorMessage instead.
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer|null
     */
    public function runNextCalibration(): ?SearchRankingCalibrationTransfer;
}
