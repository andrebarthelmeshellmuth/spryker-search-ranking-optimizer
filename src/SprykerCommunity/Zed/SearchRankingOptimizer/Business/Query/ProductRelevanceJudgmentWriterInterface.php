<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query;

use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;

interface ProductRelevanceJudgmentWriterInterface
{
    /**
     * Canonicalizes the request's raw search term, finds-or-creates the matching query row, then upserts
     * the rater's judgment for that (query, product) pair. Idempotent per (query, customer, product) — see
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface::upsertRating()}.
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\InvalidRatingTypeException
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryRatingTransfer
     */
    public function submitJudgment(SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer): SearchRankingQueryRatingTransfer;
}
