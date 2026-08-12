<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\MatchQuery;
use Generated\Shared\Search\PageIndexMap;
use Spryker\Client\Catalog\Plugin\Elasticsearch\Query\ProductCatalogSearchQueryPlugin;
use Spryker\Client\SearchElasticsearch\Plugin\QueryExpander\IsActiveInDateRangeQueryExpanderPlugin;
use Spryker\Client\SearchElasticsearch\Plugin\QueryExpander\IsActiveQueryExpanderPlugin;

/**
 * Extracted from {@see SaturationPointCalibrationSearcher}'s own original `buildQuery()` — see
 * {@see LiveCatalogSearchQueryBuilderInterface} for why this exists as its own component (shared by
 * Calibration and rank_eval evaluation) rather than living on one or the other, and for why this is a
 * deliberate SUBSET of the real live query (store/locale/is_active/is_active_in_date_range only), not a
 * byte-for-byte reproduction of every filter a real customer-facing search might additionally apply.
 */
class LiveCatalogSearchQueryBuilder implements LiveCatalogSearchQueryBuilderInterface
{
    /**
     * {@inheritDoc}
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int|null $limit
     */
    public function build(string $searchTerm, string $storeName, string $localeName, ?int $limit = null): Query
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

        if ($limit !== null) {
            $elasticaQuery->setSize($limit);
        }

        return $elasticaQuery;
    }

    /**
     * @param \Elastica\Query\BoolQuery $boolQuery
     * @param string $field
     * @param string $value
     */
    protected function addMatchFilter(BoolQuery $boolQuery, string $field, string $value): void
    {
        $matchQuery = new MatchQuery();
        $matchQuery->setField($field, $value);
        $boolQuery->addMust($matchQuery);
    }
}
