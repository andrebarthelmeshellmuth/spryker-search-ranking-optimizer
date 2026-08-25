<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation;

use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Generated\Shared\Transfer\SearchRankingHybridComparisonTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

interface RankEvaluationRunnerInterface
{
    /**
     * Specification:
     * - Groups every individual rating for this store/locale into a mean gain per (query, product) pair
     *   (a query rated by multiple admins is averaged, never overwritten — the same "disagreement is a
     *   signal, not noise" design {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentWriterInterface}
     *   already applies at write time), fires one batched `_rank_eval` request covering every query that
     *   has at least one rated product, and persists a query-importance-weighted nDCG aggregate.
     * - The weighting uses each query's OWN `importanceWeight` (Query Curator-editable), NOT rank_eval's
     *   own top-level `metric_score` (confirmed a plain unweighted mean, unusable directly).
     * - Uses the LIVE synced search-ranking configuration (never an override) — this is the normal
     *   Evaluation-page path, answering "how good is our currently-configured ranking formula, really?".
     * - Returns null (persists nothing) when there is nothing to evaluate: no queries at all for this
     *   store/locale, or none of them have a single rated product.
     *
     * @param string $storeName
     * @param string $localeName
     */
    public function evaluate(string $storeName, string $localeName): ?SearchRankingEvaluationTransfer;

    /**
     * Same underlying pipeline as {@see evaluate()} (rating aggregation, batched `_rank_eval` call,
     * PHP-side importance-weighted aggregate) but scored against an EXPLICIT candidate configuration
     * instead of the live one, and NEVER persists anything to `spy_search_ranking_evaluation` — built for
     * the automated weight-optimization loop, which may call this hundreds or thousands of times in a
     * single run and would otherwise flood that table with entries that were never real, human-triggered
     * evaluations.
     *
     * @param string $storeName
     * @param string $localeName
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer $candidateConfigurationTransfer
     *
     * @return float|null Null under the same "nothing to evaluate" conditions as {@see evaluate()}.
     */
    public function evaluateCandidate(
        string $storeName,
        string $localeName,
        SearchRankingConfigurationStorageTransfer $candidateConfigurationTransfer,
    ): ?float;

    /**
     * Specification:
     * - P4's own comparison entry point (`search-ranking-optimizer:evaluate-hybrid`): runs the SAME judged
     *   query set (same rating aggregation, same gain mapping) through TWO ranking configurations, both
     *   cloned from the LIVE synced configuration — one with `alpha` forced to `1.0` ("lexical", an
     *   unambiguous baseline regardless of what the live config's own alpha currently is), one with
     *   `alpha` set to `$alpha` ("hybrid") — via two separate `_rank_eval` calls.
     * - Captures each query's own nDCG@cutoff from BOTH configs (before any weighted-aggregate
     *   collapsing), tags each with its hardcoded query-type bucket (see
     *   {@see QueryBucketClassifierInterface}), and computes `delta = hybridScore - lexicalScore` — plus
     *   the same query-importance-weighted aggregate {@see evaluate()}/{@see evaluateCandidate()} already
     *   compute, for both configs.
     * - A query missing from either config's response (should not happen — both configs fire the exact
     *   same query set) is simply excluded from `queryComparisons`, never a fatal error.
     * - Returns an EMPTY transfer (empty `queryComparisons`, `0.0` aggregates) under the same "nothing to
     *   evaluate" conditions {@see evaluate()} returns `null` for — never `null` itself, since a console
     *   command consuming this always wants a reportable (if empty) result.
     * - $fusionMode selects which fusion mode the "hybrid" side's `_rank_eval` request uses (see
     *   {@see \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer::getFusionMode()} and
     *   `SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig::FUSION_MODE_*`).
     *   Defaults to `FUSION_MODE_LINEAR`, matching every pre-RRF caller unchanged. The "lexical" baseline
     *   request is ALWAYS `FUSION_MODE_LINEAR` with `alpha` forced to `1.0` regardless of $fusionMode —
     *   RRF/alpha are both irrelevant to a pure-lexical baseline.
     *
     * @param string $storeName
     * @param string $localeName
     * @param float $alpha
     * @param string $fusionMode
     */
    public function compareLexicalVsHybrid(
        string $storeName,
        string $localeName,
        float $alpha,
        string $fusionMode = SearchRankingOptimizerConfig::FUSION_MODE_LINEAR,
    ): SearchRankingHybridComparisonTransfer;
}
