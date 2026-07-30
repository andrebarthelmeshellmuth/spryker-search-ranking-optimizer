<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception;

use RuntimeException;

/**
 * Thrown mid-apply when {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationApplier}
 * finds a winning candidate's metric was deleted between when its optimization run finished and when an
 * admin clicked Apply — inside the same transaction the rest of the apply runs in, so throwing this rolls
 * back every write already made for this run rather than leaving a partially-applied, sub-1.0 metric
 * weight total live.
 */
class MetricNoLongerExistsException extends RuntimeException
{
}
