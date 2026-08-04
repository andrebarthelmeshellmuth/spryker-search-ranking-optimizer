<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query;

use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchResponseTransfer;

interface ProductRelevanceJudgmentReaderInterface
{
    /**
     * Canonicalizes the request's raw search term, looks up the matching query row (never creates one —
     * a query nobody has ever rated has no ratings to return, not a reason to create it), and returns this
     * customer's own ratings for whichever of the requested product-abstract ids they have actually rated.
     * A query that does not exist yet, or an empty $idProductAbstracts list, both return successfully with
     * an empty ratings array — neither is an error, just "nothing to show".
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer $requestTransfer
     */
    public function getJudgments(SearchRankingProductRelevanceJudgmentBatchRequestTransfer $requestTransfer): SearchRankingProductRelevanceJudgmentBatchResponseTransfer;
}
