<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business;

use Spryker\Zed\Kernel\Business\AbstractBusinessFactory;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolver;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolverInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunner;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunnerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CalibrationUploadHandler;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CalibrationUploadHandlerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CsvSearchTermParser;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CsvSearchTermParserInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\ScoreCalibrator;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\ScoreCalibratorInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\StatisticsCalculator;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\StatisticsCalculatorInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorder;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorderInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRestorer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRestorerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunner;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunnerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RelevanceJudgmentGainMapper;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RelevanceJudgmentGainMapperInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentWriter;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentWriterInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\QueryImportanceWeightUpdater;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\QueryImportanceWeightUpdaterInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToAclFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSymfonyMailerFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\QueryContainer\SearchRankingOptimizerToAclQueryContainerInterface;
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

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RelevanceJudgmentGainMapperInterface
     */
    public function createRelevanceJudgmentGainMapper(): RelevanceJudgmentGainMapperInterface
    {
        return new RelevanceJudgmentGainMapper();
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunnerInterface
     */
    public function createRankEvaluationRunner(): RankEvaluationRunnerInterface
    {
        return new RankEvaluationRunner(
            $this->getRepository(),
            $this->getEntityManager(),
            $this->getSearchRankingClient(),
            $this->createRelevanceJudgmentGainMapper(),
        );
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorderInterface
     */
    public function createWeightCheckpointRecorder(): WeightCheckpointRecorderInterface
    {
        return new WeightCheckpointRecorder(
            $this->getSearchRankingFacade(),
            $this->getEntityManager(),
        );
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRestorerInterface
     */
    public function createWeightCheckpointRestorer(): WeightCheckpointRestorerInterface
    {
        return new WeightCheckpointRestorer(
            $this->getRepository(),
            $this->getSearchRankingFacade(),
            $this->createWeightCheckpointRecorder(),
        );
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface
     */
    public function getSearchRankingFacade(): SearchRankingOptimizerToSearchRankingFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_SEARCH_RANKING);
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToAclFacadeInterface
     */
    public function getAclFacade(): SearchRankingOptimizerToAclFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_ACL);
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\QueryContainer\SearchRankingOptimizerToAclQueryContainerInterface
     */
    public function getAclQueryContainer(): SearchRankingOptimizerToAclQueryContainerInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::QUERY_CONTAINER_ACL);
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSymfonyMailerFacadeInterface
     */
    public function getSymfonyMailerFacade(): SearchRankingOptimizerToSymfonyMailerFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_SYMFONY_MAILER);
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolverInterface
     */
    public function createAutoTuneNotificationRecipientResolver(): AutoTuneNotificationRecipientResolverInterface
    {
        return new AutoTuneNotificationRecipientResolver(
            $this->getAclFacade(),
            $this->getAclQueryContainer(),
        );
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunnerInterface
     */
    public function createAutoTuneRunner(): AutoTuneRunnerInterface
    {
        return new AutoTuneRunner(
            $this->getRepository(),
            $this->getSearchRankingFacade(),
            $this->createAutoTuneNotificationRecipientResolver(),
            $this->getSymfonyMailerFacade(),
        );
    }
}
