<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer;

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
}
