<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Elastica\Client;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

/**
 * Fires the calibration query DIRECTLY against Elasticsearch. The query itself (the exact same building
 * blocks the live storefront query is built from, NOT the real `StoreQueryExpanderPlugin`/
 * `LocalizedQueryExpanderPlugin` — both of those, and `Client\Catalog`/`Client\Search` entirely,
 * throw when called from Zed in this shop: their query/index resolution requires
 * `Client\Store::getCurrentStore()`, which needs HTTP-request-scoped "current store" state that plain Zed
 * execution never has) is built by {@see LiveCatalogSearchQueryBuilderInterface}, shared with the rank_eval
 * evaluation runner so both fire an identical query shape. See {@see SaturationPointCalibrationSearcherInterface} for the
 * full story.
 */
class SaturationPointCalibrationSearcher implements SaturationPointCalibrationSearcherInterface
{
    protected Client $elasticaClient;

    protected IndexNameResolverInterface $indexNameResolver;

    protected RawRelevanceScoreExtractorInterface $rawRelevanceScoreExtractor;

    protected LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder;

    /**
     * @param \Elastica\Client $elasticaClient
     * @param \Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface $indexNameResolver
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\RawRelevanceScoreExtractorInterface $rawRelevanceScoreExtractor
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder
     */
    public function __construct(
        Client $elasticaClient,
        IndexNameResolverInterface $indexNameResolver,
        RawRelevanceScoreExtractorInterface $rawRelevanceScoreExtractor,
        LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder,
    ) {
        $this->elasticaClient = $elasticaClient;
        $this->indexNameResolver = $indexNameResolver;
        $this->rawRelevanceScoreExtractor = $rawRelevanceScoreExtractor;
        $this->liveCatalogSearchQueryBuilder = $liveCatalogSearchQueryBuilder;
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
        $elasticaQuery = $this->liveCatalogSearchQueryBuilder->build($searchTerm, $storeName, $localeName, $limit);
        $elasticaQuery->setExplain(true);

        $indexName = $this->indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, $storeName);

        $resultSet = $this->elasticaClient->getIndex($indexName)->search($elasticaQuery);

        $scores = [];

        foreach ($resultSet->getResults() as $result) {
            $scores[] = $this->rawRelevanceScoreExtractor->extract($result->getExplanation());
        }

        return $scores;
    }
}
