<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Elastica\Client;
use Elastica\Request;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface;
use SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculatorInterface;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use Throwable;

/**
 * Fires the `_termvectors` probe DIRECTLY against Elasticsearch, the same reasoning as
 * {@see CalibrationSearcher}'s own docblock for why: `Client\Catalog`/`Client\Search`, and even
 * `search-ranking`'s own `QueryTermFrequencyFetcher` (which resolves its index via
 * `Client\Store::getCurrentStore()`), are unusable from Zed/console execution — no HTTP-request-scoped
 * "current store" exists there. $storeName is passed in explicitly instead (picked by the admin at upload
 * time), resolved via the same {@see IndexNameResolverInterface} `CalibrationSearcher` uses. Only the IO is
 * reimplemented; the blend/normalize math itself reuses `QuerySpecificityCalculatorInterface` directly —
 * that class has no Store dependency at all.
 */
class SpecificitySearcher implements SpecificitySearcherInterface
{
    /**
     * @param \Elastica\Client $elasticaClient
     * @param \Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface $indexNameResolver
     * @param \SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculatorInterface $querySpecificityCalculator
     * @param array<string, string> $fieldToSearchAnalyzer
     */
    public function __construct(
        protected Client $elasticaClient,
        protected IndexNameResolverInterface $indexNameResolver,
        protected QuerySpecificityCalculatorInterface $querySpecificityCalculator,
        protected array $fieldToSearchAnalyzer,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param float $blendWeight
     *
     * @return float
     */
    public function calculateRawSpecificity(string $searchTerm, string $storeName, float $blendWeight): float
    {
        if ($searchTerm === '' || $this->fieldToSearchAnalyzer === []) {
            return 0.0;
        }

        $indexName = $this->indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, $storeName);
        $idfByTerm = $this->fetchIdfByTerm($indexName, $searchTerm);

        if ($idfByTerm === []) {
            return 0.0;
        }

        return $this->querySpecificityCalculator->calculateRawSpecificity($idfByTerm, $blendWeight);
    }

    /**
     * A failing probe, or a corpus with zero documents, must never break the calibration run it was fired
     * alongside — any Throwable here is treated as "no usable signal".
     *
     * @param string $indexName
     * @param string $searchTerm
     *
     * @return array<string, float>
     */
    protected function fetchIdfByTerm(string $indexName, string $searchTerm): array
    {
        try {
            $responseData = $this->elasticaClient->request(
                sprintf('%s/_termvectors', $indexName),
                Request::POST,
                [
                    'doc' => array_fill_keys(array_keys($this->fieldToSearchAnalyzer), $searchTerm),
                    'fields' => array_keys($this->fieldToSearchAnalyzer),
                    'per_field_analyzer' => $this->fieldToSearchAnalyzer,
                    'term_statistics' => true,
                    'field_statistics' => true,
                ],
            )->getData();
        } catch (Throwable) {
            return [];
        }

        return $this->calculateIdfByTermFromResponse($responseData);
    }

    /**
     * Mirrors {@see \SprykerCommunity\Client\SearchRanking\Search\QueryTermFrequencyFetcher}'s own
     * `_termvectors` response parsing plus {@see \SprykerCommunity\Client\SearchRanking\Search\SpecificityWeightCalculator}'s
     * own idf derivation in one pass -- see {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner::calculateIdfByTermFromResponse()}
     * for the identical reimplementation this mirrors, and its docblock for why it's reimplemented rather
     * than reused.
     *
     * @param array<string, mixed> $responseData
     *
     * @return array<string, float>
     */
    protected function calculateIdfByTermFromResponse(array $responseData): array
    {
        $termVectorsByField = is_array($responseData['term_vectors'] ?? null) ? $responseData['term_vectors'] : [];

        $docCount = 0;
        $termDocumentFrequencies = [];

        foreach ($termVectorsByField as $fieldTermVector) {
            if (!is_array($fieldTermVector)) {
                continue;
            }

            $fieldStatistics = is_array($fieldTermVector['field_statistics'] ?? null) ? $fieldTermVector['field_statistics'] : [];
            $docCount = max($docCount, (int)($fieldStatistics['doc_count'] ?? 0));

            $terms = is_array($fieldTermVector['terms'] ?? null) ? $fieldTermVector['terms'] : [];

            foreach ($terms as $term => $termStatistics) {
                $documentFrequency = (int)($termStatistics['doc_freq'] ?? 0);
                $termDocumentFrequencies[$term] = max($termDocumentFrequencies[$term] ?? 0, $documentFrequency);
            }
        }

        if ($docCount <= 0) {
            return [];
        }

        $idfByTerm = [];

        foreach ($termDocumentFrequencies as $term => $documentFrequency) {
            if ($documentFrequency <= 0) {
                continue;
            }

            $idfByTerm[$term] = max(0.0, log($docCount / $documentFrequency));
        }

        return $idfByTerm;
    }
}
