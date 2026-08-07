<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune;

use Generated\Shared\Transfer\SearchRankingAutoTuneResultTransfer;

interface AutoTuneRunnerInterface
{
    /**
     * Specification:
     * - Runs independently for EVERY real configured store (via the Store facade's own `getAllStores()`)
     *   that has `search-ranking` set up for it — a store with no metric store-config at all is skipped
     *   entirely, not evaluated against empty/default state. Each store's own auto-tune configs
     *   (`spy_search_ranking_auto_tune_metric_config`, store-scoped) are checked independently: a
     *   threshold, auto-update setting, or notify setting on one store has no effect on any other.
     * - For every metric with an auto-tune threshold set (within a given store): reads the metric's
     *   CURRENT fit quality fresh (no side effect); if it's still at or above the threshold, appends an
     *   isChange=false audit row and moves on. If it's below the threshold, computes a refit
     *   (parameters-only or program's-choice, per that metric's own configured scope), applies it through
     *   search-ranking's own facade when isAutoUpdateEnabled is on (otherwise only proposes it — formula
     *   left untouched, but still logged via an isChange=false row), and includes it in the run's result
     *   either way.
     * - A metric deleted since its config was set, or with no digest yet (for that store), is silently
     *   skipped — absent from the result, not an error.
     * - Sends exactly ONE combined before/after summary email per RUN — never one per metric, and never
     *   one per store — to every admin holding
     *   {@see \SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig::AUTO_TUNE_NOTIFICATION_ROLE_NAME},
     *   covering every metric (across every store) that crossed its threshold AND has isNotifyEnabled on.
     *   Sends nothing (and returns notifiedEmailCount=0) when no metric needs one, or when no admin holds
     *   that role yet.
     */
    public function run(): SearchRankingAutoTuneResultTransfer;
}
