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
     * - Reads the CURRENT state directly from `search-ranking`'s own facade for the given store+locale —
     *   relevanceWeight, every metric's own weight, the 3 specificity-weighting knobs, and whether
     *   specificity weighting is currently enabled at the code level — and persists it as one new weight
     *   checkpoint.
     * - Each metric weight row also captures that metric's `isLocaleScoped` flag AT RECORD TIME — a
     *   store-wide metric (`isLocaleScoped=false`) is the one whose weight `search-ranking`'s own
     *   `saveMetricWeight()` fans out to every real locale of the store on write, so a later restore needs
     *   to know which rows carry that wider blast radius (see `WeightCheckpointRestorer`).
     *
     * @param string $source
     * @param string $storeName
     * @param string $localeName
     */
    public function record(string $source, string $storeName, string $localeName): SearchRankingWeightCheckpointTransfer;
}
