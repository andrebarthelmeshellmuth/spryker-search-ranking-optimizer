<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;

interface OptimizationRunnerInterface
{
    /**
     * Specification:
     * - Picks up the oldest still-queued optimization run (skipping none — every queued run is processed
     *   in turn, one per call, matching this package's console command being invoked once per cron tick
     *   or manual trigger). Returns null when nothing is queued.
     * - Evaluates the LIVE configuration first (never persisted as a real evaluation — see
     *   {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunnerInterface::evaluateCandidate()})
     *   to record as this run's baselineScore, then runs the run's own algorithm (CMA-ES or Differential
     *   Evolution) against the metric-weight simplex + relevanceWeight trust region, scoring every
     *   candidate the same non-persisting way.
     * - Always propose-only: the winning candidate is persisted onto the run row for a human to review,
     *   NEVER written through search-ranking's own facade automatically, regardless of algorithm or how
     *   much it improved on the baseline.
     * - Fails the run (status=failed, a real error_message) rather than throwing, for: no rated queries
     *   to evaluate against, no active metrics to optimize, or any exception raised mid-run (e.g. an
     *   Elasticsearch error) — a queued run always ends in either done or failed, never queued/running
     *   forever.
     *
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer|null
     */
    public function runNext(): ?SearchRankingOptimizerRunTransfer;
}
