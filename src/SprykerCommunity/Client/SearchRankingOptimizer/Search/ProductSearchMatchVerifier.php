<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Elastica\Client;
use Elastica\Query\BoolQuery;
use Elastica\Query\Ids;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

/**
 * Same raw-Elastica-bypass reasoning as {@see CalibrationSearcher}/{@see RankEvalRunner} -- fires directly
 * against Elasticsearch with an explicit index name, no `Client\Catalog`/`Client\Search`/Store-singleton
 * involved, so this is safe to call from Zed (via the bridge) as well as from Yves.
 */
class ProductSearchMatchVerifier implements ProductSearchMatchVerifierInterface
{
    /**
     * @param \Elastica\Client $elasticaClient
     * @param \Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface $indexNameResolver
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder
     */
    public function __construct(
        protected Client $elasticaClient,
        protected IndexNameResolverInterface $indexNameResolver,
        protected LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $idProductAbstract
     */
    public function matches(string $searchTerm, string $storeName, string $localeName, int $idProductAbstract): bool
    {
        $elasticaQuery = $this->liveCatalogSearchQueryBuilder->build($searchTerm, $storeName, $localeName);
        $boolQuery = $elasticaQuery->getQuery();

        if ($boolQuery instanceof BoolQuery) {
            $boolQuery->addFilter(new Ids([$this->buildProductDocumentId($storeName, $localeName, $idProductAbstract)]));
        }

        $elasticaQuery->setSize(1);

        $indexName = $this->indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, $storeName);
        $resultSet = $this->elasticaClient->getIndex($indexName)->search($elasticaQuery);

        return $resultSet->getTotalHits() > 0;
    }

    /**
     * Mirrors {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner::buildProductDocumentId()}
     * exactly -- both classes independently rely on this document `_id` format, keep them in sync if it
     * ever changes.
     *
     * @param string $storeName
     * @param string $localeName
     * @param int $idProductAbstract
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
