<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Persistence;

use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfigQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationSearchTermQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingEvaluationQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingOptimizerRunQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRatingQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingWeightCheckpointQuery;
use Spryker\Zed\Kernel\Persistence\AbstractPersistenceFactory;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\Propel\Mapper\SearchRankingOptimizerMapper;

class SearchRankingOptimizerPersistenceFactory extends AbstractPersistenceFactory
{
    public function createSearchRankingCalibrationQuery(): SpySearchRankingCalibrationQuery
    {
        return SpySearchRankingCalibrationQuery::create();
    }

    public function createSearchRankingCalibrationSearchTermQuery(): SpySearchRankingCalibrationSearchTermQuery
    {
        return SpySearchRankingCalibrationSearchTermQuery::create();
    }

    public function createSearchRankingQueryQuery(): SpySearchRankingQueryQuery
    {
        return SpySearchRankingQueryQuery::create();
    }

    public function createSearchRankingQueryRatingQuery(): SpySearchRankingQueryRatingQuery
    {
        return SpySearchRankingQueryRatingQuery::create();
    }

    public function createSearchRankingEvaluationQuery(): SpySearchRankingEvaluationQuery
    {
        return SpySearchRankingEvaluationQuery::create();
    }

    public function createSearchRankingWeightCheckpointQuery(): SpySearchRankingWeightCheckpointQuery
    {
        return SpySearchRankingWeightCheckpointQuery::create();
    }

    public function createSearchRankingAutoTuneMetricConfigQuery(): SpySearchRankingAutoTuneMetricConfigQuery
    {
        return SpySearchRankingAutoTuneMetricConfigQuery::create();
    }

    public function createSearchRankingOptimizerRunQuery(): SpySearchRankingOptimizerRunQuery
    {
        return SpySearchRankingOptimizerRunQuery::create();
    }

    public function createSearchRankingOptimizerMapper(): SearchRankingOptimizerMapper
    {
        return new SearchRankingOptimizerMapper();
    }
}
