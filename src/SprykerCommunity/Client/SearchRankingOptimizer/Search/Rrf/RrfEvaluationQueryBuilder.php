<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf;

use Elastica\Client;
use Elastica\Query\AbstractQuery;
use Elastica\Query\MatchAll;
use Elastica\Request;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilderInterface;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use Throwable;

/**
 * Split out of {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner} (which grew
 * too many orthogonal responsibilities across several build passes) — pure extraction, no behavioral
 * change; see that class's git history for the original single-class shape. This cluster's OpenSearch
 * 1.3.4 has no native RRF/hybrid-query support (a 2.10+ feature), so the fusion is computed here in PHP,
 * not delegated to Elasticsearch itself -- see {@see RrfEvaluationQueryBuilderInterface} for the full
 * mechanism and degradation contract.
 */
class RrfEvaluationQueryBuilder implements RrfEvaluationQueryBuilderInterface
{
    /**
     * @param \Elastica\Client $elasticaClient
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf\RrfScoreCalculatorInterface|null $rrfScoreCalculator
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf\RrfCandidateQueryBuilderInterface|null $rrfCandidateQueryBuilder
     */
    public function __construct(
        protected Client $elasticaClient,
        protected LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder,
        protected ?RrfScoreCalculatorInterface $rrfScoreCalculator = null,
        protected ?RrfCandidateQueryBuilderInterface $rrfCandidateQueryBuilder = null,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param string $indexName
     * @param array<int, float>|null $queryVector
     */
    public function build(
        string $searchTerm,
        string $storeName,
        string $localeName,
        string $indexName,
        ?array $queryVector,
    ): AbstractQuery {
        if ($this->rrfScoreCalculator === null || $this->rrfCandidateQueryBuilder === null) {
            $fallbackQuery = $this->liveCatalogSearchQueryBuilder->build($searchTerm, $storeName, $localeName)->getQuery();

            return $fallbackQuery instanceof AbstractQuery ? $fallbackQuery : new MatchAll();
        }

        $candidateDepth = SearchRankingOptimizerConfig::getRrfCandidateDepth();

        $lexicalRankedDocIds = $this->fetchLexicalCandidateDocIds($searchTerm, $storeName, $localeName, $indexName, $candidateDepth);
        $semanticRankedDocIds = $queryVector !== null
            ? $this->fetchSemanticCandidateDocIds($queryVector, $indexName, $candidateDepth)
            : [];

        $fusedRankedDocIds = $this->rrfScoreCalculator->fuse(
            $lexicalRankedDocIds,
            $semanticRankedDocIds,
            SearchRankingOptimizerConfig::getRrfK(),
        );

        return $this->rrfCandidateQueryBuilder->build($fusedRankedDocIds);
    }

    /**
     * Fires the plain (unwrapped) live catalog query capped at $candidateDepth hits and returns just the
     * ordered `_id` list -- the lexical half of RRF's two independent candidate retrievals. A failure here
     * (transient ES error) degrades to an empty list rather than aborting the whole evaluation run.
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param string $indexName
     * @param int $candidateDepth
     *
     * @return array<int, string>
     */
    protected function fetchLexicalCandidateDocIds(
        string $searchTerm,
        string $storeName,
        string $localeName,
        string $indexName,
        int $candidateDepth,
    ): array {
        try {
            $elasticaQuery = $this->liveCatalogSearchQueryBuilder->build($searchTerm, $storeName, $localeName, $candidateDepth);
            $resultSet = $this->elasticaClient->getIndex($indexName)->search($elasticaQuery);
        } catch (Throwable) {
            return [];
        }

        return array_map(
            static fn ($result): string => $result->getId(),
            $resultSet->getResults(),
        );
    }

    /**
     * Fires a pure kNN-only candidate query (no lexical component at all -- OpenSearch 1.3.4's own `knn`
     * query type, raw request body, same raw-Elastica-request pattern `RankEvalRunner` already uses
     * elsewhere for `_rank_eval`/`_termvectors`) and returns just the ordered `_id` list -- the semantic
     * half of RRF's two independent candidate retrievals. A failure here (embedding index missing,
     * transient ES error) degrades to an empty list rather than aborting the whole evaluation run.
     *
     * @param array<int, float> $queryVector
     * @param string $indexName
     * @param int $candidateDepth
     *
     * @return array<int, string>
     */
    protected function fetchSemanticCandidateDocIds(array $queryVector, string $indexName, int $candidateDepth): array
    {
        try {
            $responseData = $this->elasticaClient->request(
                sprintf('%s/_search', $indexName),
                Request::POST,
                [
                    'size' => $candidateDepth,
                    '_source' => false,
                    'query' => [
                        'knn' => [
                            'embedding' => [
                                'vector' => array_values($queryVector),
                                'k' => $candidateDepth,
                            ],
                        ],
                    ],
                ],
            )->getData();
        } catch (Throwable) {
            return [];
        }

        $hits = is_array($responseData['hits']['hits'] ?? null) ? $responseData['hits']['hits'] : [];

        $docIds = [];

        foreach ($hits as $hit) {
            if (!is_array($hit) || !isset($hit['_id']) || !is_string($hit['_id'])) {
                continue;
            }

            $docIds[] = $hit['_id'];
        }

        return $docIds;
    }
}
