<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation;

use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;

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
}
