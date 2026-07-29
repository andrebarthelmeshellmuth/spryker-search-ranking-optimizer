<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Elastica\Client;
use Elastica\Query\AbstractQuery;
use Elastica\Request;
use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationQueryScoreTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface;
use SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientInterface;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

class RankEvalRunner implements RankEvalRunnerInterface
{
    /**
     * @var \Elastica\Client
     */
    protected Client $elasticaClient;

    /**
     * @var \Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface
     */
    protected IndexNameResolverInterface $indexNameResolver;

    /**
     * @var \SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilderInterface
     */
    protected LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder;

    /**
     * @var \SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface
     */
    protected FunctionScoreBuilderInterface $functionScoreBuilder;

    /**
     * @var \SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientInterface
     */
    protected SearchRankingOptimizerToSearchRankingStorageClientInterface $searchRankingStorageClient;

    /**
     * @param \Elastica\Client $elasticaClient
     * @param \Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface $indexNameResolver
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder
     * @param \SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface $functionScoreBuilder
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientInterface $searchRankingStorageClient
     */
    public function __construct(
        Client $elasticaClient,
        IndexNameResolverInterface $indexNameResolver,
        LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder,
        FunctionScoreBuilderInterface $functionScoreBuilder,
        SearchRankingOptimizerToSearchRankingStorageClientInterface $searchRankingStorageClient,
    ) {
        $this->elasticaClient = $elasticaClient;
        $this->indexNameResolver = $indexNameResolver;
        $this->liveCatalogSearchQueryBuilder = $liveCatalogSearchQueryBuilder;
        $this->functionScoreBuilder = $functionScoreBuilder;
        $this->searchRankingStorageClient = $searchRankingStorageClient;
    }

    /**
     * {@inheritDoc}
     *
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer $requestTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer
     */
    public function evaluate(SearchRankingEvaluationRequestTransfer $requestTransfer): SearchRankingEvaluationResponseTransfer
    {
        $responseTransfer = new SearchRankingEvaluationResponseTransfer();

        $storeName = $requestTransfer->getStoreNameOrFail();
        $localeName = $requestTransfer->getLocaleNameOrFail();
        $indexName = $this->indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, $storeName);
        $configurationTransfer = $requestTransfer->getRankingConfiguration() ?? $this->searchRankingStorageClient->findRankingConfiguration();

        $rankEvalRequests = $this->buildRankEvalRequests($requestTransfer, $storeName, $localeName, $indexName, $configurationTransfer);

        if ($rankEvalRequests === []) {
            return $responseTransfer;
        }

        $responseData = $this->elasticaClient->request(sprintf('%s/_rank_eval', $indexName), Request::POST, [
            'requests' => $rankEvalRequests,
            'metric' => [
                'dcg' => [
                    'k' => $requestTransfer->getCutoffOrFail(),
                    'normalize' => true,
                ],
            ],
        ])->getData();

        $details = is_array($responseData['details'] ?? null) ? $responseData['details'] : [];

        foreach ($rankEvalRequests as $rankEvalRequest) {
            $queryId = $rankEvalRequest['id'];
            $metricScore = (float)($details[$queryId]['metric_score'] ?? 0.0);

            $responseTransfer->addQueryScore(
                (new SearchRankingEvaluationQueryScoreTransfer())
                    ->setIdSearchRankingQuery((int)$queryId)
                    ->setMetricScore($metricScore),
            );
        }

        return $responseTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer $requestTransfer
     * @param string $storeName
     * @param string $localeName
     * @param string $indexName
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildRankEvalRequests(
        SearchRankingEvaluationRequestTransfer $requestTransfer,
        string $storeName,
        string $localeName,
        string $indexName,
        ?SearchRankingConfigurationStorageTransfer $configurationTransfer,
    ): array {
        $rankEvalRequests = [];

        foreach ($requestTransfer->getQueries() as $queryTransfer) {
            $ratings = [];

            foreach ($queryTransfer->getProductGains() as $productGainTransfer) {
                $ratings[] = [
                    '_index' => $indexName,
                    '_id' => $this->buildProductDocumentId($storeName, $localeName, $productGainTransfer->getIdProductAbstractOrFail()),
                    'rating' => $productGainTransfer->getGainOrFail(),
                ];
            }

            if ($ratings === []) {
                continue;
            }

            $elasticaQuery = $this->liveCatalogSearchQueryBuilder->build($queryTransfer->getSearchTermOrFail(), $storeName, $localeName);
            $queryClause = $this->applyRankingFormula($elasticaQuery->getQuery(), $configurationTransfer);

            $rankEvalRequests[] = [
                'id' => (string)$queryTransfer->getIdSearchRankingQueryOrFail(),
                'request' => [
                    'query' => $queryClause instanceof AbstractQuery ? $queryClause->toArray() : $queryClause,
                ],
                'ratings' => $ratings,
            ];
        }

        return $rankEvalRequests;
    }

    /**
     * Wraps the base catalog query in search-ranking's own business-signal function_score — the same
     * blend `SearchRankingFunctionScoreQueryExpanderPlugin` applies to every real storefront search — so
     * this evaluation measures the ACTUAL configured (or candidate) ranking formula's quality, not raw
     * Elasticsearch text relevance. Falls back to the unwrapped query whenever there's nothing to blend
     * (no configuration at all, or a configuration with no active non-zero metric weight) — exactly
     * mirroring that plugin's own "leave the query untouched" fallback.
     *
     * @param mixed $queryClause
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     *
     * @return mixed
     */
    protected function applyRankingFormula(
        mixed $queryClause,
        ?SearchRankingConfigurationStorageTransfer $configurationTransfer,
    ): mixed {
        if ($configurationTransfer === null || !($queryClause instanceof AbstractQuery)) {
            return $queryClause;
        }

        $functionScore = $this->functionScoreBuilder->build($queryClause, $configurationTransfer);

        return $functionScore ?? $queryClause;
    }

    /**
     * The `page` index's own document id format, confirmed live against this shop's real OpenSearch index
     * (`product_abstract:{store}:{locale}:{idProductAbstract}`, store/locale lowercased) — computed
     * directly rather than looked up, since the id_product_abstract is already exactly what
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface}
     * stores per rating.
     *
     * @param string $storeName
     * @param string $localeName
     * @param int $idProductAbstract
     *
     * @return string
     */
    protected function buildProductDocumentId(string $storeName, string $localeName, int $idProductAbstract): string
    {
        return sprintf(
            'product_abstract:%s:%s:%d',
            strtolower($storeName),
            strtolower($localeName),
            $idProductAbstract,
        );
    }
}
