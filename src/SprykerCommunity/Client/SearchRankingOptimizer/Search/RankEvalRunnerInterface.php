<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer;

interface RankEvalRunnerInterface
{
    /**
     * Specification:
     * - Fires ONE `_rank_eval` request covering every query in $requestTransfer, batched — one rank_eval
     *   "request" per query, built from the SAME live catalog query
     *   {@see LiveCatalogSearchQueryBuilderInterface} builds for Calibration, paired with that query's
     *   rated products as `ratings` (`{_index, _id, rating}` triples, `_id` computed directly rather than
     *   looked up — see {@see buildProductDocumentId()}).
     * - Uses `metric.dcg.normalize=true` (nDCG), NOT the response's own top-level `metric_score` (a plain
     *   unweighted mean across queries) — returns each query's OWN score from the response's `details` map
     *   instead, so the caller can compute a query-importance-weighted aggregate itself.
     * - A query with zero rated products is skipped entirely (nothing to rate it against) rather than
     *   sent to rank_eval with an empty `ratings` array, which the API itself rejects.
     *
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer $requestTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer
     */
    public function evaluate(SearchRankingEvaluationRequestTransfer $requestTransfer): SearchRankingEvaluationResponseTransfer;
}
