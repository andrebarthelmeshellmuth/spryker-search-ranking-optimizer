<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;

interface OptimizationApplierInterface
{
    /**
     * Specification:
     * - Writes a done optimization run's winning candidate (relevanceWeight + every active metric's
     *   weight) through search-ranking's own facade, exactly like a manual edit would — same
     *   write-through-facade discipline as {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRestorerInterface::restore()}.
     * - Records a new weight checkpoint (source = SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_OPTIMIZER)
     *   of the resulting state, same "apply = write + checkpoint" pattern as any other weight change.
     * - Sets appliedAt on the run row.
     * - Never called automatically — only a human clicking Apply on the Zed page triggers this. NOT
     *   idempotent-guarded here (the Zed page itself hides/disables Apply once appliedAt is set); calling
     *   this twice just re-applies the same values and records a second checkpoint.
     * - Returns null when the run doesn't exist, or exists but isn't status=done (nothing to apply yet).
     *
     * @param int $idSearchRankingOptimizerRun
     */
    public function apply(int $idSearchRankingOptimizerRun): ?SearchRankingOptimizerRunTransfer;
}
