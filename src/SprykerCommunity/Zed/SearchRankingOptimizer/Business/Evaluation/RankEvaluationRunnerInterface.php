<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation;

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
     * - Returns null (persists nothing) when there is nothing to evaluate: no queries at all for this
     *   store/locale, or none of them have a single rated product.
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return \Generated\Shared\Transfer\SearchRankingEvaluationTransfer|null
     */
    public function evaluate(string $storeName, string $localeName): ?SearchRankingEvaluationTransfer;
}
