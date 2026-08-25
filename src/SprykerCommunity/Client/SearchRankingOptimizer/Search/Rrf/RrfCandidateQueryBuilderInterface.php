<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf;

use Elastica\Query\AbstractQuery;

interface RrfCandidateQueryBuilderInterface
{
    /**
     * Specification:
     * - Builds an Elasticsearch/OpenSearch query whose NATURAL result order already reflects
     *   $fusedRankedDocIds's own precomputed RRF-fused order -- without any Painless doc-value/map lookup
     *   (this shop's `search-result-data.*` fields are `index:false`, and doc-value availability for a
     *   Painless map-lookup-by-product-id is uncertain), and without touching
     *   {@see \SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilder} at all.
     * - Wraps an {@see \Elastica\Query\Ids} query restricted to EXACTLY the RRF candidate pool (so only
     *   real candidates can appear at all) in a {@see \Elastica\Query\FunctionScore} with one `weight`
     *   function per candidate, filtered by a `term` query on the native `_id` field (always queryable, no
     *   custom mapping/doc-value concern) -- weight decreasing by 1 per rank position, best-ranked doc
     *   gets the highest weight. `boost_mode: replace` (the query's own text-relevance score is irrelevant
     *   here -- only the precomputed RRF weight matters), `score_mode: max` (only one `term` filter ever
     *   matches a given doc, since ids are unique, but `max` is the correct combinator regardless).
     * - The returned query is meant to be handed to `FunctionScoreBuilder::build($wrappedQuery,
     *   $configurationTransfer, null)` -- see this package's RRF evaluation path for why `$queryVector` is
     *   always `null` there (RRF already fused the semantic signal in at this stage; passing a vector too
     *   would double-count it). `FunctionScoreBuilder` itself needs zero changes: it saturates whatever
     *   `_score` this query produces, exactly like it already saturates BM25's `_score` today.
     * - An empty $fusedRankedDocIds returns a query that matches nothing ({@see \Elastica\Query\MatchNone})
     *   rather than erroring or matching every document.
     *
     * @param array<int, string> $fusedRankedDocIds Ordered best-to-worst (RrfScoreCalculator::fuse()'s
     *   output).
     */
    public function build(array $fusedRankedDocIds): AbstractQuery;
}
