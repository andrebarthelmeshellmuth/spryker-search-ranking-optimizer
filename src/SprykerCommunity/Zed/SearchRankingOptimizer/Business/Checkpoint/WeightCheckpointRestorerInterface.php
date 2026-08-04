<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint;

use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;

interface WeightCheckpointRestorerInterface
{
    /**
     * Specification:
     * - Writes a past checkpoint's relevanceWeight, metric weights, and 3 specificity knobs back through
     *   `search-ranking`'s own facade — the SAME operation Calibration's own "Apply" already is (write
     *   current values + record the result as a new checkpoint), not a special "undo" mechanism.
     * - A metric referenced by the checkpoint that no longer exists is skipped silently — a safe,
     *   best-effort restore, not an all-or-nothing transaction.
     * - `isSpecificityWeightingEnabled` is NEVER written back — it's a pure code-level project flag with no
     *   corresponding save method on `search-ranking`'s facade, captured on checkpoints only for
     *   historical transparency.
     * - Records a NEW checkpoint (source `SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_MANUAL`) of the
     *   resulting state and returns it — restoring IS applying, per the roadmap's own finding, so it gets
     *   its own checkpoint like any other applied change.
     * - Returns null (writes nothing) when the given id doesn't exist.
     *
     * @param int $idSearchRankingWeightCheckpoint
     * @param string $storeName
     * @param string $localeName
     *
     * @return \Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer|null
     */
    public function restore(int $idSearchRankingWeightCheckpoint, string $storeName, string $localeName): ?SearchRankingWeightCheckpointTransfer;
}
