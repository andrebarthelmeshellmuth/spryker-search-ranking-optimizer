<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business;

use Spryker\Zed\Kernel\Business\AbstractBusinessFactory;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneMetricConfigWriter;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneMetricConfigWriterInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolver;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolverInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunner;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunnerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorder;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorderInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRestorer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRestorerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunner;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunnerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RelevanceJudgmentGainMapper;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RelevanceJudgmentGainMapperInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismChecker;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismCheckerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\AlgorithmFactory;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\AlgorithmFactoryInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationApplier;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationApplierInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationRunner;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationRunnerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentReader;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentReaderInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentWriter;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentWriterInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\QueryImportanceWeightUpdater;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\QueryImportanceWeightUpdaterInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\CsvSearchTermParser;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\CsvSearchTermParserInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\SaturationPointCalibrationUploadHandler;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\SaturationPointCalibrationUploadHandlerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\ScoreCalibrator;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\ScoreCalibratorInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\StatisticsCalculator;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\StatisticsCalculatorInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToAclFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToStoreFacadeInterface;
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
    public function createCsvSearchTermParser(): CsvSearchTermParserInterface
    {
        return new CsvSearchTermParser();
    }

    public function createStatisticsCalculator(): StatisticsCalculatorInterface
    {
        return new StatisticsCalculator();
    }

    public function createCalibrationUploadHandler(): SaturationPointCalibrationUploadHandlerInterface
    {
        return new SaturationPointCalibrationUploadHandler(
            $this->createCsvSearchTermParser(),
            $this->getRepository(),
            $this->getEntityManager(),
        );
    }

    public function createScoreCalibrator(): ScoreCalibratorInterface
    {
        return new ScoreCalibrator(
            $this->getRepository(),
            $this->getEntityManager(),
            $this->getSearchRankingClient(),
            $this->createStatisticsCalculator(),
            $this->getSearchRankingFacade(),
        );
    }

    public function getSearchRankingClient(): SearchRankingOptimizerToSearchRankingClientInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::CLIENT_SEARCH_RANKING_OPTIMIZER);
    }

    public function createSearchTermCanonicalizer(): SearchTermCanonicalizerInterface
    {
        return new SearchTermCanonicalizer();
    }

    public function createProductRelevanceJudgmentWriter(): ProductRelevanceJudgmentWriterInterface
    {
        return new ProductRelevanceJudgmentWriter(
            $this->createSearchTermCanonicalizer(),
            $this->getRepository(),
            $this->getEntityManager(),
            $this->getSearchRankingClient(),
        );
    }

    public function createQueryImportanceWeightUpdater(): QueryImportanceWeightUpdaterInterface
    {
        return new QueryImportanceWeightUpdater($this->getEntityManager());
    }

    public function createProductRelevanceJudgmentReader(): ProductRelevanceJudgmentReaderInterface
    {
        return new ProductRelevanceJudgmentReader(
            $this->createSearchTermCanonicalizer(),
            $this->getRepository(),
        );
    }

    public function createRelevanceJudgmentGainMapper(): RelevanceJudgmentGainMapperInterface
    {
        return new RelevanceJudgmentGainMapper();
    }

    public function createRankEvaluationRunner(): RankEvaluationRunnerInterface
    {
        return new RankEvaluationRunner(
            $this->getRepository(),
            $this->getEntityManager(),
            $this->getSearchRankingClient(),
            $this->createRelevanceJudgmentGainMapper(),
        );
    }

    public function createWeightCheckpointRecorder(): WeightCheckpointRecorderInterface
    {
        return new WeightCheckpointRecorder(
            $this->getSearchRankingFacade(),
            $this->getEntityManager(),
        );
    }

    public function createWeightCheckpointRestorer(): WeightCheckpointRestorerInterface
    {
        return new WeightCheckpointRestorer(
            $this->getRepository(),
            $this->getSearchRankingFacade(),
            $this->createWeightCheckpointRecorder(),
        );
    }

    public function getSearchRankingFacade(): SearchRankingOptimizerToSearchRankingFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_SEARCH_RANKING);
    }

    public function getStoreFacade(): SearchRankingOptimizerToStoreFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_STORE);
    }

    public function getAclFacade(): SearchRankingOptimizerToAclFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_ACL);
    }

    public function getAclQueryContainer(): SearchRankingOptimizerToAclQueryContainerInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::QUERY_CONTAINER_ACL);
    }

    public function getSymfonyMailerFacade(): SearchRankingOptimizerToSymfonyMailerFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_SYMFONY_MAILER);
    }

    public function createAutoTuneNotificationRecipientResolver(): AutoTuneNotificationRecipientResolverInterface
    {
        return new AutoTuneNotificationRecipientResolver(
            $this->getAclFacade(),
            $this->getAclQueryContainer(),
        );
    }

    public function createAutoTuneRunner(): AutoTuneRunnerInterface
    {
        return new AutoTuneRunner(
            $this->getRepository(),
            $this->getSearchRankingFacade(),
            $this->getStoreFacade(),
            $this->createAutoTuneNotificationRecipientResolver(),
            $this->getSymfonyMailerFacade(),
            $this->createFormulaDeterminismChecker(),
        );
    }

    public function createFormulaDeterminismChecker(): FormulaDeterminismCheckerInterface
    {
        return new FormulaDeterminismChecker();
    }

    public function createAutoTuneMetricConfigWriter(): AutoTuneMetricConfigWriterInterface
    {
        return new AutoTuneMetricConfigWriter(
            $this->getEntityManager(),
            $this->getSearchRankingFacade(),
        );
    }

    public function createOptimizationRunner(): OptimizationRunnerInterface
    {
        return new OptimizationRunner(
            $this->getRepository(),
            $this->getEntityManager(),
            $this->getSearchRankingFacade(),
            $this->createRankEvaluationRunner(),
            $this->createFormulaDeterminismChecker(),
            $this->createAlgorithmFactory(),
        );
    }

    public function createAlgorithmFactory(): AlgorithmFactoryInterface
    {
        return new AlgorithmFactory();
    }

    public function createOptimizationApplier(): OptimizationApplierInterface
    {
        return new OptimizationApplier(
            $this->getRepository(),
            $this->getSearchRankingFacade(),
            $this->createWeightCheckpointRecorder(),
            $this->getEntityManager(),
        );
    }
}
