<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint;

use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;

interface WeightCheckpointRecorderInterface
{
    /**
     * Specification:
     * - Reads the CURRENT state directly from `search-ranking`'s own facade — relevanceWeight, every
     *   metric's own weight, the 3 entropy-weighting knobs, and whether entropy weighting is currently
     *   enabled at the code level — and persists it as one new weight checkpoint.
     *
     * @param string $source
     *
     * @return \Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer
     */
    public function record(string $source): SearchRankingWeightCheckpointTransfer;
}
