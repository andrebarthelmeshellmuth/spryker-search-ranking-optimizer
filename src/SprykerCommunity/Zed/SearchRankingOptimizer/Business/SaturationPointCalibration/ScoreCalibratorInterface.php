<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration;

use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer;

interface ScoreCalibratorInterface
{
    /**
     * Specification:
     * - Looks up every calibration run in status=uploaded. Returns null when there is none. Exactly one
     *   of them runs per tick, system-wide: the newest (highest id).
     * - Marks as status=skipped, without ever firing a search query for them, only those OTHER uploaded
     *   runs that share the newest one's (storeName, localeName, calibrationType) — the ones it genuinely
     *   supersedes, because they would compute the same constant for the same scope. Uploads for any
     *   other store, locale or calibrationType are left in status=uploaded for a later tick to run: a
     *   DE/de_DE upload says nothing about what AT/en_US's constant should be, and a `relevance_score`
     *   upload tunes `relevanceSaturationPoint` while a `specificity` one tunes
     *   `specificitySaturationPoint`.
     * - Runs the newest one, branching on its `calibrationType`:
     *   - `relevance_score` (the default): for each search term, fires the real, fully-wired catalog
     *     search-string query with `explain: true` and the term's own top-N limit (the run's
     *     relevantProductCount), extracts each hit's raw text-relevance score (BEFORE any function_score
     *     wrapper, regardless of whether one happens to be wired in) and persists them against that
     *     search term row.
     *   - `specificity`: for each search term, fires ONE lightweight `_termvectors` probe (no real catalog
     *     query at all) and persists the single resulting raw specificity value.
     *   A single search term's probe/query failing is logged and treated as no value found — it does not
     *   abort the run.
     * - Pools every collected value across every search term and persists the statistics
     *   (see {@see StatisticsCalculatorInterface}), setting status=calculated.
     * - When not a single value was collected across every search term (e.g. every term failed or matched
     *   nothing), sets status=failed with an explanatory errorMessage instead.
     */
    public function runNextCalibration(): ?SearchRankingSaturationPointCalibrationTransfer;
}
