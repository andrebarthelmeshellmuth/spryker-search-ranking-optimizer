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
     * - For every metric with an auto-tune threshold set: reads the metric's CURRENT fit quality fresh
     *   (no side effect); if it's still at or above the threshold, appends an isChange=false audit row
     *   and moves on. If it's below the threshold, computes a refit (parameters-only or program's-choice,
     *   per that metric's own configured scope), applies it through search-ranking's own facade when
     *   isAutoUpdateEnabled is on (otherwise only proposes it — formula left untouched, but still logged
     *   via an isChange=false row), and includes it in the run's result either way.
     * - A metric deleted since its config was set, or with no digest yet, is silently skipped — absent
     *   from the result, not an error.
     * - Sends exactly ONE combined before/after summary email — never one per metric — to every admin
     *   holding {@see \SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig::AUTO_TUNE_NOTIFICATION_ROLE_NAME},
     *   covering every metric that crossed its threshold AND has isNotifyEnabled on. Sends nothing (and
     *   returns notifiedEmailCount=0) when no metric needs one, or when no admin holds that role yet.
     *
     * @return \Generated\Shared\Transfer\SearchRankingAutoTuneResultTransfer
     */
    public function run(): SearchRankingAutoTuneResultTransfer;
}
