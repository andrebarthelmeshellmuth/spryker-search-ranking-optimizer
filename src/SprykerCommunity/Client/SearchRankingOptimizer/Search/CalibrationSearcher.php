<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Elastica\Client;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\MatchQuery;
use Generated\Shared\Search\PageIndexMap;
use Spryker\Client\Catalog\Plugin\Elasticsearch\Query\ProductCatalogSearchQueryPlugin;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface;
use Spryker\Client\SearchElasticsearch\Plugin\QueryExpander\IsActiveInDateRangeQueryExpanderPlugin;
use Spryker\Client\SearchElasticsearch\Plugin\QueryExpander\IsActiveQueryExpanderPlugin;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

/**
 * Builds and fires the calibration query DIRECTLY against Elasticsearch, composing the exact same
 * building blocks the live storefront query is built from — {@see ProductCatalogSearchQueryPlugin} plus
 * {@see IsActiveQueryExpanderPlugin}/{@see IsActiveInDateRangeQueryExpanderPlugin} (both safe to reuse
 * as-is: no `Client\Store`/`Client\Customer` dependency) and hand-built store/locale filters (NOT the
 * real `StoreQueryExpanderPlugin`/`LocalizedQueryExpanderPlugin` — confirmed live those, and
 * `Client\Catalog`/`Client\Search` entirely, throw when called from Zed in this shop: their query/index
 * resolution requires `Client\Store::getCurrentStore()`, which needs HTTP-request-scoped "current store"
 * state that plain Zed execution never has). See {@see CalibrationSearcherInterface} for the full story.
 */
class CalibrationSearcher implements CalibrationSearcherInterface
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
     * @var \SprykerCommunity\Client\SearchRankingOptimizer\Search\RawRelevanceScoreExtractorInterface
     */
    protected RawRelevanceScoreExtractorInterface $rawRelevanceScoreExtractor;

    /**
     * @param \Elastica\Client $elasticaClient
     * @param \Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface $indexNameResolver
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\RawRelevanceScoreExtractorInterface $rawRelevanceScoreExtractor
     */
    public function __construct(
        Client $elasticaClient,
        IndexNameResolverInterface $indexNameResolver,
        RawRelevanceScoreExtractorInterface $rawRelevanceScoreExtractor,
    ) {
        $this->elasticaClient = $elasticaClient;
        $this->indexNameResolver = $indexNameResolver;
        $this->rawRelevanceScoreExtractor = $rawRelevanceScoreExtractor;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $limit
     *
     * @return array<float>
     */
    public function searchScores(string $searchTerm, string $storeName, string $localeName, int $limit): array
    {
        $elasticaQuery = $this->buildQuery($searchTerm, $storeName, $localeName, $limit);
        $indexName = $this->indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, $storeName);

        $resultSet = $this->elasticaClient->getIndex($indexName)->search($elasticaQuery);

        $scores = [];

        foreach ($resultSet->getResults() as $result) {
            $scores[] = $this->rawRelevanceScoreExtractor->extract($result->getExplanation());
        }

        return $scores;
    }

    /**
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $limit
     *
     * @return \Elastica\Query
     */
    protected function buildQuery(string $searchTerm, string $storeName, string $localeName, int $limit): Query
    {
        $queryPlugin = new ProductCatalogSearchQueryPlugin();
        $queryPlugin->setSearchString($searchTerm);

        $elasticaQuery = $queryPlugin->getSearchQuery();
        $boolQuery = $elasticaQuery->getQuery();

        if ($boolQuery instanceof BoolQuery) {
            $this->addMatchFilter($boolQuery, PageIndexMap::STORE, $storeName);
            $this->addMatchFilter($boolQuery, PageIndexMap::LOCALE, $localeName);
        }

        $queryPlugin = (new IsActiveQueryExpanderPlugin())->expandQuery($queryPlugin, []);
        (new IsActiveInDateRangeQueryExpanderPlugin())->expandQuery($queryPlugin, []);

        $elasticaQuery->setExplain(true);
        $elasticaQuery->setSize($limit);

        return $elasticaQuery;
    }

    /**
     * @param \Elastica\Query\BoolQuery $boolQuery
     * @param string $field
     * @param string $value
     *
     * @return void
     */
    protected function addMatchFilter(BoolQuery $boolQuery, string $field, string $value): void
    {
        $matchQuery = new MatchQuery();
        $matchQuery->setField($field, $value);
        $boolQuery->addMust($matchQuery);
    }
}
