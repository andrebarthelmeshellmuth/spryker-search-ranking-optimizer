<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf;

use Elastica\Query\AbstractQuery;

interface RrfEvaluationQueryBuilderInterface
{
    /**
     * Builds the RRF (Reciprocal Rank Fusion) evaluation query -- an ALTERNATIVE to the linear-blend hybrid
     * formula `FunctionScoreBuilder` applies (`relevanceWeight * saturatedBM25 + ... alpha * saturatedBM25 +
     * (1-alpha) * cosineSimilarity ...`). Linear blending combines two RAW SCORES (BM25 unbounded, cosine
     * bounded to `[-1;1]`) with no single alpha that calibrates well across queries — RRF instead fuses two
     * independently-retrieved RANK-POSITION lists (lexical, semantic/kNN), a better-evidenced standard
     * alternative.
     *
     * Fires the lexical candidate query and a separate kNN-only candidate query independently (each capped
     * at {@see \SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig::getRrfCandidateDepth()}),
     * fuses the rank order via {@see RrfScoreCalculatorInterface::fuse()}, then returns a NEW query via
     * {@see RrfCandidateQueryBuilderInterface::build()} whose natural ES result order already reflects that
     * fused order — entirely without touching `FunctionScoreBuilder` or doing any Painless doc-value/map
     * lookup. That RRF-ordered query is handed by the caller to the SAME, UNCHANGED
     * `RankEvalRunner::applyRankingFormula()`/`FunctionScoreBuilder::build()` call the linear path already
     * makes — `FunctionScoreBuilder` needs ZERO changes, it happily saturates whatever `_score` this query
     * produces, exactly like it already saturates BM25's `_score` today.
     *
     * Gracefully degrades — never throws, never aborts the whole evaluation run — in every failure mode: no
     * `RrfScoreCalculator`/`RrfCandidateQueryBuilder` wired in at all falls back to the plain unwrapped
     * lexical query; a `null` `$queryVector` (embedding service down) falls back to an empty semantic
     * candidate list, which {@see RrfScoreCalculatorInterface::fuse()} itself degrades to exactly the
     * lexical list's own order; a failed lexical or semantic candidate retrieval itself (transient ES
     * error) degrades to an empty candidate list on that side only.
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param string $indexName
     * @param array<int, float>|null $queryVector Already resolved with `$ignoreAlphaGate = true` by the
     *   caller -- `null` when unavailable.
     */
    public function build(
        string $searchTerm,
        string $storeName,
        string $localeName,
        string $indexName,
        ?array $queryVector,
    ): AbstractQuery;
}
