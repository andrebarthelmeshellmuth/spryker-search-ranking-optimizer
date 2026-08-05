<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Table;

use Orm\Zed\Product\Persistence\Map\SpyProductAbstractTableMap;
use Orm\Zed\SearchRankingOptimizer\Persistence\Map\SpySearchRankingQueryRatingTableMap;
use Orm\Zed\SearchRankingOptimizer\Persistence\Map\SpySearchRankingQueryTableMap;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRating;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRatingQuery;
use Spryker\Zed\Gui\Communication\Table\AbstractTable;
use Spryker\Zed\Gui\Communication\Table\TableConfiguration;

/**
 * Every individual admin rating input (one row per heart/check/X click from the storefront widget),
 * joined through to its query's own search term and the rated product's SKU, newest activity first — the
 * raw per-row audit view {@see AssessRatedQueryTable} deliberately does NOT provide (that page aggregates
 * to one row per query). Filtering here goes through the same stock DataTables single search box every
 * other table in these two packages uses (searchable across search term, SKU, and admin/customer
 * reference simultaneously), not independent per-column filters.
 */
class RatingTable extends AbstractTable
{
    /**
     * @var string
     */
    protected const COL_SEARCH_TERM = 'searchTerm';

    /**
     * @var string
     */
    protected const COL_SKU = 'sku';

    /**
     * @var string
     */
    protected const COL_RATING_TYPE = 'ratingType';

    protected SpySearchRankingQueryRatingQuery $ratingQuery;

    /**
     * @param \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRatingQuery $ratingQuery
     */
    public function __construct(SpySearchRankingQueryRatingQuery $ratingQuery)
    {
        $this->ratingQuery = $ratingQuery;
    }

    /**
     * @param \Spryker\Zed\Gui\Communication\Table\TableConfiguration $config
     */
    protected function configure(TableConfiguration $config): TableConfiguration
    {
        $config->setHeader([
            SpySearchRankingQueryRatingTableMap::COL_ID_SEARCH_RANKING_QUERY_RATING => 'ID',
            static::COL_SEARCH_TERM => 'Search term',
            static::COL_SKU => 'SKU',
            SpySearchRankingQueryRatingTableMap::COL_CUSTOMER_REFERENCE => 'Admin',
            static::COL_RATING_TYPE => 'Rating',
            SpySearchRankingQueryRatingTableMap::COL_UPDATED_AT => 'Recorded At',
        ]);

        $config->setSortable([
            SpySearchRankingQueryRatingTableMap::COL_ID_SEARCH_RANKING_QUERY_RATING,
            SpySearchRankingQueryTableMap::COL_SEARCH_TERM,
            SpyProductAbstractTableMap::COL_SKU,
            SpySearchRankingQueryRatingTableMap::COL_CUSTOMER_REFERENCE,
            SpySearchRankingQueryRatingTableMap::COL_RATING_TYPE,
            SpySearchRankingQueryRatingTableMap::COL_UPDATED_AT,
        ]);

        $config->setSearchable([
            SpySearchRankingQueryTableMap::COL_SEARCH_TERM,
            SpyProductAbstractTableMap::COL_SKU,
            SpySearchRankingQueryRatingTableMap::COL_CUSTOMER_REFERENCE,
        ]);

        $config->setRawColumns([
            static::COL_RATING_TYPE,
        ]);

        // Newest-activity-first by default -- upsertRating() genuinely updates the existing row (not a
        // delete+reinsert) when the same admin re-rates the same (query, product) pair, so updated_at
        // honestly reflects the most recent judgment, same convention AssessRatedQueryTable's own default
        // sort already established.
        $config->setDefaultSortField(SpySearchRankingQueryRatingTableMap::COL_UPDATED_AT, TableConfiguration::SORT_DESC);

        return $config;
    }

    /**
     * @param \Spryker\Zed\Gui\Communication\Table\TableConfiguration $config
     *
     * @return array<int, array<string, mixed>>
     */
    protected function prepareData(TableConfiguration $config): array
    {
        $this->ratingQuery
            ->joinSearchRankingQuery()
            ->joinSpyProductAbstract();

        $ratingEntities = $this->runQuery($this->ratingQuery, $config, true);
        $rows = [];

        /** @var \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRating $ratingEntity */
        foreach ($ratingEntities as $ratingEntity) {
            $rows[] = [
                SpySearchRankingQueryRatingTableMap::COL_ID_SEARCH_RANKING_QUERY_RATING => $ratingEntity->getIdSearchRankingQueryRating(),
                static::COL_SEARCH_TERM => $ratingEntity->getSearchRankingQuery()->getSearchTerm(),
                static::COL_SKU => $ratingEntity->getSpyProductAbstract()->getSku(),
                SpySearchRankingQueryRatingTableMap::COL_CUSTOMER_REFERENCE => $ratingEntity->getCustomerReference(),
                static::COL_RATING_TYPE => $this->formatRatingType($ratingEntity),
                SpySearchRankingQueryRatingTableMap::COL_UPDATED_AT => $ratingEntity->getUpdatedAt('Y-m-d H:i:s'),
            ];
        }

        return $rows;
    }

    /**
     * @param \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRating $ratingEntity
     */
    protected function formatRatingType(SpySearchRankingQueryRating $ratingEntity): string
    {
        $labelsByRatingType = [
            'heart' => ['Heart', 'label-danger'],
            'check' => ['Check', 'label-success'],
            'x' => ['X', 'label-warning'],
        ];

        [$label, $cssClass] = $labelsByRatingType[$ratingEntity->getRatingType()] ?? [$ratingEntity->getRatingType(), 'label-warning'];

        return $this->generateLabel($label, $cssClass);
    }
}
