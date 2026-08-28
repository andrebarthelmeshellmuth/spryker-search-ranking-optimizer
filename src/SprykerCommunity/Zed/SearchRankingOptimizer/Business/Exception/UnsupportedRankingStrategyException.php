<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception;

use RuntimeException;

/**
 * Thrown by {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\RankingStrategyGuardInterface}
 * before any path that would write ranking-formula parameters (`relevanceWeight`, the metric-weight
 * simplex, the specificity knobs, or an auto-tuned metric formula) to live `search-ranking` configuration,
 * when a ranking strategy other than `adaptive_formula` is registered in the project and this package has
 * no {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\ParameterVectorMapperInterface}
 * for it.
 *
 * The optimizer's whole parameter space is the adaptive `function_score` formula; a non-formula strategy
 * (a neural rerank, an RRF fusion pass, a `search_pipeline`) never reads those settings. Optimizing and
 * writing them while such a strategy is live would leave the live configuration changed, the active
 * strategy unaffected, and an "improved nDCG" recorded for parameters nothing consumes — silent,
 * plausible-looking garbage. Refusing loudly is the correct outcome until a mapper is registered for the
 * active strategy, or the project reverts to `adaptive_formula`.
 */
class UnsupportedRankingStrategyException extends RuntimeException
{
}
