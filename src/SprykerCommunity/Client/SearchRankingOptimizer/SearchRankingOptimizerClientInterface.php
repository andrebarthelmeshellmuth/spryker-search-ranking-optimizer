<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer;

use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer;

interface SearchRankingOptimizerClientInterface
{
    /**
     * Specification:
     * - Used only by the calibration feature. Fires the calibration query for $searchTerm directly
     *   against Elasticsearch (bypassing `Client\Catalog`/`Client\Search`, which are unusable from Zed in
     *   this shop — see
     *   {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\CalibrationSearcherInterface} for
     *   why), and returns each matched product's raw text-relevance score, up to $limit.
     *
     * @api
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $limit
     *
     * @return array<float>
     */
    public function getCalibrationScores(string $searchTerm, string $storeName, string $localeName, int $limit): array;

    /**
     * Specification:
     * - Submits a Relevance Rater's heart/checkmark/X judgment for a (query, product) pair via a
     *   synchronous Zed gateway call. Zed independently re-authorizes the caller — this method does not
     *   itself check the RateSearchRelevancePermissionPlugin permission, that only gates whether the
     *   widget renders/is interactive on Yves.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer
     */
    public function submitProductRelevanceJudgment(
        SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer,
    ): SearchRankingProductRelevanceJudgmentResponseTransfer;

    /**
     * Specification:
     * - Clears a Relevance Rater's previously submitted judgment for a (query, product) pair via a
     *   synchronous Zed gateway call — backs the widget's "click an already-pressed button to unselect"
     *   affordance. Zed independently re-authorizes the caller, same as
     *   {@see submitProductRelevanceJudgment()}.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer
     */
    public function clearProductRelevanceJudgment(
        SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer,
    ): SearchRankingProductRelevanceJudgmentResponseTransfer;

    /**
     * Specification:
     * - Fires one batched `_rank_eval` call directly against Elasticsearch (same bypass reasoning as
     *   {@see getCalibrationScores()}) covering every query in $requestTransfer, returning each query's own
     *   nDCG@cutoff score.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer $requestTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer
     */
    public function evaluateRankings(SearchRankingEvaluationRequestTransfer $requestTransfer): SearchRankingEvaluationResponseTransfer;

    /**
     * Specification:
     * - Runs the same live catalog search {@see getCalibrationScores()} bypasses `Client\Catalog`/`Client\Search`
     *   for, narrowed to one candidate document, and reports whether $idProductAbstract is actually among
     *   the real, current results for $searchTerm -- used to reject a submitted relevance judgment whose
     *   (searchTerm, idProductAbstract) pair was fabricated rather than genuinely observed on a real SRP.
     *
     * @api
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $idProductAbstract
     *
     * @return bool
     */
    public function productMatchesSearch(string $searchTerm, string $storeName, string $localeName, int $idProductAbstract): bool;
}
