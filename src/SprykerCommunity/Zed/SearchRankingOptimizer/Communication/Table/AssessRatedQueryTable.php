<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Table;

use Orm\Zed\SearchRankingOptimizer\Persistence\Map\SpySearchRankingQueryTableMap;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryQuery;
use Spryker\Service\UtilText\Model\Url\Url;
use Spryker\Zed\Gui\Communication\Table\AbstractTable;
use Spryker\Zed\Gui\Communication\Table\TableConfiguration;

/**
 * Every rated query, newest-activity-first by default — the same sort
 * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface::findAllQueriesOrderedByUpdatedAt()}
 * uses for the Facade-level read, expressed here as an `AbstractTable` instead so a Query Curator gets
 * pagination/sorting/search for free — the same convention `spryker-community/search-ranking`'s own
 * MetricTable already established for this Zed theme.
 */
class AssessRatedQueryTable extends AbstractTable
{
    /**
     * @var string
     */
    public const URL_PARAM_ID_SEARCH_RANKING_QUERY = 'id-search-ranking-query';

    /**
     * @var string
     */
    protected const COL_ACTIONS = 'actions';

    protected SpySearchRankingQueryQuery $queryQuery;

    /**
     * @param \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryQuery $queryQuery
     */
    public function __construct(SpySearchRankingQueryQuery $queryQuery)
    {
        $this->queryQuery = $queryQuery;
    }

    /**
     * @param \Spryker\Zed\Gui\Communication\Table\TableConfiguration $config
     */
    protected function configure(TableConfiguration $config): TableConfiguration
    {
        $config->setHeader([
            SpySearchRankingQueryTableMap::COL_ID_SEARCH_RANKING_QUERY => 'ID',
            SpySearchRankingQueryTableMap::COL_SEARCH_TERM => 'Search term',
            SpySearchRankingQueryTableMap::COL_STORE_NAME => 'Store',
            SpySearchRankingQueryTableMap::COL_LOCALE_NAME => 'Locale',
            SpySearchRankingQueryTableMap::COL_IMPORTANCE_WEIGHT => 'Importance weight',
            SpySearchRankingQueryTableMap::COL_UPDATED_AT => 'Last activity',
            static::COL_ACTIONS => 'Actions',
        ]);

        $config->setSortable([
            SpySearchRankingQueryTableMap::COL_ID_SEARCH_RANKING_QUERY,
            SpySearchRankingQueryTableMap::COL_SEARCH_TERM,
            SpySearchRankingQueryTableMap::COL_STORE_NAME,
            SpySearchRankingQueryTableMap::COL_LOCALE_NAME,
            SpySearchRankingQueryTableMap::COL_IMPORTANCE_WEIGHT,
            SpySearchRankingQueryTableMap::COL_UPDATED_AT,
        ]);

        $config->setSearchable([
            SpySearchRankingQueryTableMap::COL_SEARCH_TERM,
        ]);

        $config->setRawColumns([
            static::COL_ACTIONS,
        ]);

        // Newest-activity-first by default (the same sort the Facade-level read uses) — a Query Curator's
        // triage question is "what got rated most recently", not alphabetical order.
        $config->setDefaultSortField(SpySearchRankingQueryTableMap::COL_UPDATED_AT, TableConfiguration::SORT_DESC);

        return $config;
    }

    /**
     * @param \Spryker\Zed\Gui\Communication\Table\TableConfiguration $config
     *
     * @return array<int, array<string, mixed>>
     */
    protected function prepareData(TableConfiguration $config): array
    {
        $queryEntities = $this->runQuery($this->queryQuery, $config, true);
        $rows = [];

        /** @var \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQuery $queryEntity */
        foreach ($queryEntities as $queryEntity) {
            $rows[] = [
                SpySearchRankingQueryTableMap::COL_ID_SEARCH_RANKING_QUERY => $queryEntity->getIdSearchRankingQuery(),
                SpySearchRankingQueryTableMap::COL_SEARCH_TERM => $queryEntity->getSearchTerm(),
                SpySearchRankingQueryTableMap::COL_STORE_NAME => $queryEntity->getStoreName(),
                SpySearchRankingQueryTableMap::COL_LOCALE_NAME => $queryEntity->getLocaleName(),
                SpySearchRankingQueryTableMap::COL_IMPORTANCE_WEIGHT => $queryEntity->getImportanceWeight(),
                SpySearchRankingQueryTableMap::COL_UPDATED_AT => $queryEntity->getUpdatedAt()?->format('Y-m-d H:i:s'),
                static::COL_ACTIONS => $this->createEditButton($queryEntity),
            ];
        }

        return $rows;
    }

    /**
     * @param \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQuery $queryEntity
     */
    protected function createEditButton(SpySearchRankingQuery $queryEntity): string
    {
        $urlParams = [
            static::URL_PARAM_ID_SEARCH_RANKING_QUERY => $queryEntity->getIdSearchRankingQuery(),
        ];

        return $this->generateEditButton(
            Url::generate('/search-ranking-optimizer/assess-rated-query/edit', $urlParams)->build(),
            'Edit importance',
        );
    }
}
