<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception;

use InvalidArgumentException;

/**
 * Thrown by {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentWriter::submitJudgment()}
 * when the submitted (searchTerm, idProductAbstract) pair does not correspond to a real, current search
 * result -- rejects fabricated pairs outright rather than persisting a rating that could never have come
 * from an actual SRP.
 */
class ProductNotInSearchResultsException extends InvalidArgumentException
{
}
