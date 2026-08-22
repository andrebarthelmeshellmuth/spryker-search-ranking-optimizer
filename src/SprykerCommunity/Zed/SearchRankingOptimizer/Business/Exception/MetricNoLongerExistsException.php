<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception;

use RuntimeException;

/**
 * Thrown mid-write when a metric a caller is about to save a weight for no longer exists. Two callers,
 * same reasoning: {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationApplier}
 * (a winning candidate's metric deleted between when its optimization run finished and when an admin
 * clicked Apply) and {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRestorer}
 * (a checkpointed metric deleted between when the checkpoint was recorded and when it's restored). Both
 * throw inside the same transaction the rest of their writes run in, so throwing rolls back every write
 * already made rather than leaving a partially-applied/-restored, sub-1.0 metric weight total live.
 */
class MetricNoLongerExistsException extends RuntimeException
{
}
