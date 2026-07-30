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
     * Canonicalizes the request's raw search term, verifies $idProductAbstract is actually among the real,
     * current search results for the request's raw (uncanonicalized) search term -- rejecting the judgment
     * outright if not, since that pair could never have come from a genuine SRP -- then finds-or-creates
     * the matching query row and upserts the rater's judgment for that (query, product) pair. Idempotent
     * per (query, customer, product) — see
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface::upsertRating()}.
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\InvalidRatingTypeException
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\ProductNotInSearchResultsException
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryRatingTransfer
     */
    public function submitJudgment(SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer): SearchRankingQueryRatingTransfer;

    /**
     * Canonicalizes the request's raw search term, looks up the matching query row (never creates one —
     * clearing a judgment that was never submitted is a safe no-op, not a reason to create a query), and
     * deletes the rater's row for that (query, product) pair, if any.
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @return void
     */
    public function clearJudgment(SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer): void;
}
