<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business;

use Spryker\Zed\Kernel\Business\AbstractBusinessFactory;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CalibrationUploadHandler;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CalibrationUploadHandlerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CsvSearchTermParser;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CsvSearchTermParserInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\ScoreCalibrator;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\ScoreCalibratorInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\StatisticsCalculator;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\StatisticsCalculatorInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentWriter;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentWriterInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\QueryImportanceWeightUpdater;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\QueryImportanceWeightUpdaterInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\SearchRankingOptimizerDependencyProvider;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface getRepository()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface getEntityManager()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\SearchRankingOptimizerConfig getConfig()
 */
class SearchRankingOptimizerBusinessFactory extends AbstractBusinessFactory
{
    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CsvSearchTermParserInterface
     */
    public function createCsvSearchTermParser(): CsvSearchTermParserInterface
    {
        return new CsvSearchTermParser();
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\StatisticsCalculatorInterface
     */
    public function createStatisticsCalculator(): StatisticsCalculatorInterface
    {
        return new StatisticsCalculator();
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CalibrationUploadHandlerInterface
     */
    public function createCalibrationUploadHandler(): CalibrationUploadHandlerInterface
    {
        return new CalibrationUploadHandler(
            $this->createCsvSearchTermParser(),
            $this->getRepository(),
            $this->getEntityManager(),
        );
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\ScoreCalibratorInterface
     */
    public function createScoreCalibrator(): ScoreCalibratorInterface
    {
        return new ScoreCalibrator(
            $this->getRepository(),
            $this->getEntityManager(),
            $this->getSearchRankingClient(),
            $this->createStatisticsCalculator(),
        );
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface
     */
    public function getSearchRankingClient(): SearchRankingOptimizerToSearchRankingClientInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::CLIENT_SEARCH_RANKING_OPTIMIZER);
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizerInterface
     */
    public function createSearchTermCanonicalizer(): SearchTermCanonicalizerInterface
    {
        return new SearchTermCanonicalizer();
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentWriterInterface
     */
    public function createProductRelevanceJudgmentWriter(): ProductRelevanceJudgmentWriterInterface
    {
        return new ProductRelevanceJudgmentWriter(
            $this->createSearchTermCanonicalizer(),
            $this->getRepository(),
            $this->getEntityManager(),
        );
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\QueryImportanceWeightUpdaterInterface
     */
    public function createQueryImportanceWeightUpdater(): QueryImportanceWeightUpdaterInterface
    {
        return new QueryImportanceWeightUpdater($this->getEntityManager());
    }
}
