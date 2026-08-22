<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Generated\Shared\Transfer\SearchRankingAutoTuneNotificationDiagnosisTransfer;
use Generated\Shared\Transfer\SearchRankingAutoTuneResultTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchResponseTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneMetricConfigWriterInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationDiagnoserInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunnerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRecorderInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Checkpoint\WeightCheckpointRestorerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunnerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\AlgorithmFactoryInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizableParameterListerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationApplierInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationRunnerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentReaderInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentWriterInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\QueryImportanceWeightUpdaterInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\SaturationPointCalibrationUploadHandlerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\ScoreCalibratorInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerBusinessFactory;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacade;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManager;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepository;

/**
 * Almost every public method here is a one-hop delegation to a factory-built collaborator or the
 * repository/entity manager, returning exactly what that collaborator returns -- every collaborator's own
 * real logic already has its own dedicated test. `queueOptimizationRun()` and `saveAutoTuneMetricConfig()`
 * carry real logic of their own (building the run transfer, picking one locale out of a per-locale save
 * result) and are covered accordingly, not just as passthroughs.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group SearchRankingOptimizerFacadeTest
 * @group Portable
 */
class SearchRankingOptimizerFacadeTest extends Unit
{
    public function testCreateCalibrationDelegatesToTheCalibrationUploadHandler(): void
    {
        $calibrationTransfer = new SearchRankingSaturationPointCalibrationTransfer();

        $handlerMock = $this->createMock(SaturationPointCalibrationUploadHandlerInterface::class);
        $handlerMock->method('createCalibration')->with('organic', 5, 'DE', 'de_DE', 'csv')->willReturn($calibrationTransfer);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createCalibrationUploadHandler' => $handlerMock]));

        $this->assertSame($calibrationTransfer, $facade->createCalibration('organic', 5, 'DE', 'de_DE', 'csv'));
    }

    public function testRunNextCalibrationDelegatesToTheScoreCalibrator(): void
    {
        $calibrationTransfer = new SearchRankingSaturationPointCalibrationTransfer();

        $calibratorMock = $this->createMock(ScoreCalibratorInterface::class);
        $calibratorMock->method('runNextCalibration')->willReturn($calibrationTransfer);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createScoreCalibrator' => $calibratorMock]));

        $this->assertSame($calibrationTransfer, $facade->runNextCalibration());
    }

    public function testFindLatestCalculatedCalibrationDelegatesToTheRepository(): void
    {
        $calibrationTransfer = new SearchRankingSaturationPointCalibrationTransfer();

        $repositoryMock = $this->createRepositoryMock();
        $repositoryMock->method('findLatestCalculatedCalibration')->with('DE', 'de_DE')->willReturn($calibrationTransfer);

        $facade = $this->buildFacadeWithRepository($repositoryMock);

        $this->assertSame($calibrationTransfer, $facade->findLatestCalculatedCalibration('DE', 'de_DE'));
    }

    public function testFindCalibrationInProgressDelegatesToTheRepository(): void
    {
        $calibrationTransfer = new SearchRankingSaturationPointCalibrationTransfer();

        $repositoryMock = $this->createRepositoryMock();
        $repositoryMock->method('findCalibrationInProgress')->with('DE', 'de_DE')->willReturn($calibrationTransfer);

        $facade = $this->buildFacadeWithRepository($repositoryMock);

        $this->assertSame($calibrationTransfer, $facade->findCalibrationInProgress('DE', 'de_DE'));
    }

    public function testSubmitProductRelevanceJudgmentDelegatesToTheProductRelevanceJudgmentWriter(): void
    {
        $requestTransfer = new SearchRankingProductRelevanceJudgmentRequestTransfer();
        $ratingTransfer = new SearchRankingQueryRatingTransfer();

        $writerMock = $this->createMock(ProductRelevanceJudgmentWriterInterface::class);
        $writerMock->method('submitJudgment')->with($requestTransfer)->willReturn($ratingTransfer);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createProductRelevanceJudgmentWriter' => $writerMock]));

        $this->assertSame($ratingTransfer, $facade->submitProductRelevanceJudgment($requestTransfer));
    }

    public function testClearProductRelevanceJudgmentDelegatesToTheProductRelevanceJudgmentWriter(): void
    {
        $requestTransfer = new SearchRankingProductRelevanceJudgmentRequestTransfer();

        $writerMock = $this->createMock(ProductRelevanceJudgmentWriterInterface::class);
        $writerMock->expects($this->once())->method('clearJudgment')->with($requestTransfer);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createProductRelevanceJudgmentWriter' => $writerMock]));

        $facade->clearProductRelevanceJudgment($requestTransfer);
    }

    public function testGetProductRelevanceJudgmentsDelegatesToTheProductRelevanceJudgmentReader(): void
    {
        $requestTransfer = new SearchRankingProductRelevanceJudgmentBatchRequestTransfer();
        $responseTransfer = new SearchRankingProductRelevanceJudgmentBatchResponseTransfer();

        $readerMock = $this->createMock(ProductRelevanceJudgmentReaderInterface::class);
        $readerMock->method('getJudgments')->with($requestTransfer)->willReturn($responseTransfer);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createProductRelevanceJudgmentReader' => $readerMock]));

        $this->assertSame($responseTransfer, $facade->getProductRelevanceJudgments($requestTransfer));
    }

    public function testGetQueriesDelegatesToTheRepository(): void
    {
        $queries = [new SearchRankingQueryTransfer()];

        $repositoryMock = $this->createRepositoryMock();
        $repositoryMock->method('findAllQueriesOrderedByUpdatedAt')->willReturn($queries);

        $facade = $this->buildFacadeWithRepository($repositoryMock);

        $this->assertSame($queries, $facade->getQueries());
    }

    public function testFindQueryByIdDelegatesToTheRepository(): void
    {
        $queryTransfer = new SearchRankingQueryTransfer();

        $repositoryMock = $this->createRepositoryMock();
        $repositoryMock->method('findQueryById')->with(1)->willReturn($queryTransfer);

        $facade = $this->buildFacadeWithRepository($repositoryMock);

        $this->assertSame($queryTransfer, $facade->findQueryById(1));
    }

    public function testUpdateQueryImportanceWeightDelegatesToTheQueryImportanceWeightUpdater(): void
    {
        $updaterMock = $this->createMock(QueryImportanceWeightUpdaterInterface::class);
        $updaterMock->expects($this->once())->method('update')->with(1, 2.5);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createQueryImportanceWeightUpdater' => $updaterMock]));

        $facade->updateQueryImportanceWeight(1, 2.5);
    }

    public function testRunRankEvaluationDelegatesToTheRankEvaluationRunner(): void
    {
        $evaluationTransfer = new SearchRankingEvaluationTransfer();

        $runnerMock = $this->createMock(RankEvaluationRunnerInterface::class);
        $runnerMock->method('evaluate')->with('DE', 'de_DE')->willReturn($evaluationTransfer);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createRankEvaluationRunner' => $runnerMock]));

        $this->assertSame($evaluationTransfer, $facade->runRankEvaluation('DE', 'de_DE'));
    }

    public function testFindLatestEvaluationDelegatesToTheRepository(): void
    {
        $evaluationTransfer = new SearchRankingEvaluationTransfer();

        $repositoryMock = $this->createRepositoryMock();
        $repositoryMock->method('findLatestEvaluation')->with('DE', 'de_DE')->willReturn($evaluationTransfer);

        $facade = $this->buildFacadeWithRepository($repositoryMock);

        $this->assertSame($evaluationTransfer, $facade->findLatestEvaluation('DE', 'de_DE'));
    }

    public function testFindEvaluationHistoryDelegatesToTheRepository(): void
    {
        $history = [new SearchRankingEvaluationTransfer()];

        $repositoryMock = $this->createRepositoryMock();
        $repositoryMock->method('findEvaluationHistory')->with('DE', 'de_DE')->willReturn($history);

        $facade = $this->buildFacadeWithRepository($repositoryMock);

        $this->assertSame($history, $facade->findEvaluationHistory('DE', 'de_DE'));
    }

    public function testRecordWeightCheckpointDelegatesToTheWeightCheckpointRecorder(): void
    {
        $checkpointTransfer = new SearchRankingWeightCheckpointTransfer();

        $recorderMock = $this->createMock(WeightCheckpointRecorderInterface::class);
        $recorderMock->method('record')->with('manual', 'DE', 'de_DE')->willReturn($checkpointTransfer);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createWeightCheckpointRecorder' => $recorderMock]));

        $this->assertSame($checkpointTransfer, $facade->recordWeightCheckpoint('manual', 'DE', 'de_DE'));
    }

    public function testRestoreWeightCheckpointDelegatesToTheWeightCheckpointRestorer(): void
    {
        $checkpointTransfer = new SearchRankingWeightCheckpointTransfer();

        $restorerMock = $this->createMock(WeightCheckpointRestorerInterface::class);
        $restorerMock->method('restore')->with(1, 'DE', 'de_DE')->willReturn($checkpointTransfer);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createWeightCheckpointRestorer' => $restorerMock]));

        $this->assertSame($checkpointTransfer, $facade->restoreWeightCheckpoint(1, 'DE', 'de_DE'));
    }

    public function testFindWeightCheckpointHistoryDelegatesToTheRepository(): void
    {
        $history = [new SearchRankingWeightCheckpointTransfer()];

        $repositoryMock = $this->createRepositoryMock();
        $repositoryMock->method('findWeightCheckpointHistory')->with('DE', 'de_DE')->willReturn($history);

        $facade = $this->buildFacadeWithRepository($repositoryMock);

        $this->assertSame($history, $facade->findWeightCheckpointHistory('DE', 'de_DE'));
    }

    public function testFindAutoTuneMetricConfigByMetricIdDelegatesToTheRepository(): void
    {
        $configTransfer = new SearchRankingAutoTuneMetricConfigTransfer();

        $repositoryMock = $this->createRepositoryMock();
        $repositoryMock->method('findAutoTuneMetricConfigByMetricId')->with(1, 'DE', 'de_DE')->willReturn($configTransfer);

        $facade = $this->buildFacadeWithRepository($repositoryMock);

        $this->assertSame($configTransfer, $facade->findAutoTuneMetricConfigByMetricId(1, 'DE', 'de_DE'));
    }

    public function testFindAutoTuneMetricConfigsWithThresholdSetDelegatesToTheRepository(): void
    {
        $configs = [new SearchRankingAutoTuneMetricConfigTransfer()];

        $repositoryMock = $this->createRepositoryMock();
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->with('DE')->willReturn($configs);

        $facade = $this->buildFacadeWithRepository($repositoryMock);

        $this->assertSame($configs, $facade->findAutoTuneMetricConfigsWithThresholdSet('DE'));
    }

    /**
     * The one real bit of logic here: `save()` returns a per-locale MAP (a single metric config can be
     * saved across several effective-weight locales at once, see `resolveEffectiveWeightLocales()`), and
     * this method's own job is picking out exactly the ONE entry matching the transfer's own requested
     * locale -- not just forwarding the writer's return value unmodified.
     */
    public function testSaveAutoTuneMetricConfigReturnsOnlyTheEntryForTheRequestedLocale(): void
    {
        $requestedConfigTransfer = (new SearchRankingAutoTuneMetricConfigTransfer())->setLocaleName('de_AT');
        $savedForRequestedLocale = new SearchRankingAutoTuneMetricConfigTransfer();
        $savedForOtherLocale = new SearchRankingAutoTuneMetricConfigTransfer();

        $writerMock = $this->createMock(AutoTuneMetricConfigWriterInterface::class);
        $writerMock->method('save')->with($requestedConfigTransfer)->willReturn([
            'de_DE' => $savedForOtherLocale,
            'de_AT' => $savedForRequestedLocale,
        ]);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createAutoTuneMetricConfigWriter' => $writerMock]));

        $this->assertSame($savedForRequestedLocale, $facade->saveAutoTuneMetricConfig($requestedConfigTransfer));
    }

    public function testRunAutoTuneDelegatesToTheAutoTuneRunner(): void
    {
        $resultTransfer = new SearchRankingAutoTuneResultTransfer();

        $runnerMock = $this->createMock(AutoTuneRunnerInterface::class);
        $runnerMock->method('run')->willReturn($resultTransfer);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createAutoTuneRunner' => $runnerMock]));

        $this->assertSame($resultTransfer, $facade->runAutoTune());
    }

    public function testGetAutoTuneNotificationDiagnosisDelegatesToTheAutoTuneNotificationDiagnoser(): void
    {
        $diagnosisTransfer = new SearchRankingAutoTuneNotificationDiagnosisTransfer();

        $diagnoserMock = $this->createMock(AutoTuneNotificationDiagnoserInterface::class);
        $diagnoserMock->method('diagnose')->willReturn($diagnosisTransfer);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createAutoTuneNotificationDiagnoser' => $diagnoserMock]));

        $this->assertSame($diagnosisTransfer, $facade->getAutoTuneNotificationDiagnosis());
    }

    /**
     * The other real bit of logic in this Facade: builds the run transfer from every parameter before
     * handing it to the entity manager, rather than just forwarding a caller-built transfer -- worth
     * asserting the field mapping is right, not just that SOME transfer reaches the entity manager.
     */
    public function testQueueOptimizationRunBuildsTheRunTransferFromEveryParameter(): void
    {
        $entityManagerMock = $this->getMockBuilder(SearchRankingOptimizerEntityManager::class)
            ->onlyMethods(['createOptimizerRun'])
            ->getMock();

        $entityManagerMock->expects($this->once())
            ->method('createOptimizerRun')
            ->with($this->callback(fn (SearchRankingOptimizerRunTransfer $optimizerRunTransfer): bool => $optimizerRunTransfer->getStoreName() === 'DE'
                && $optimizerRunTransfer->getLocaleName() === 'de_DE'
                && $optimizerRunTransfer->getAlgorithm() === 'cma_es'
                && $optimizerRunTransfer->getTerminationMode() === 'fixed_budget'
                && $optimizerRunTransfer->getWarmStartFraction() === 0.25
                && $optimizerRunTransfer->getFixedRelevanceWeight() === 0.5))
            ->willReturnArgument(0);

        $facade = new SearchRankingOptimizerFacade();
        $facade->setEntityManager($entityManagerMock);

        $result = $facade->queueOptimizationRun('DE', 'de_DE', 'cma_es', 'fixed_budget', 0.25, 0.5);

        $this->assertSame('DE', $result->getStoreName());
    }

    public function testListOptimizableParametersDelegatesToTheOptimizableParameterLister(): void
    {
        $parameters = ['relevanceWeight' => 0.5];

        $listerMock = $this->createMock(OptimizableParameterListerInterface::class);
        $listerMock->method('list')->with('DE', 'de_DE')->willReturn($parameters);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createOptimizableParameterLister' => $listerMock]));

        $this->assertSame($parameters, $facade->listOptimizableParameters('DE', 'de_DE'));
    }

    public function testGetOptimizationAlgorithmsDelegatesToTheAlgorithmFactory(): void
    {
        $algorithms = [];

        $algorithmFactoryMock = $this->createMock(AlgorithmFactoryInterface::class);
        $algorithmFactoryMock->method('createAll')->willReturn($algorithms);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createAlgorithmFactory' => $algorithmFactoryMock]));

        $this->assertSame($algorithms, $facade->getOptimizationAlgorithms());
    }

    public function testRunNextOptimizationDelegatesToTheOptimizationRunner(): void
    {
        $runTransfer = new SearchRankingOptimizerRunTransfer();

        $runnerMock = $this->createMock(OptimizationRunnerInterface::class);
        $runnerMock->method('runNext')->willReturn($runTransfer);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createOptimizationRunner' => $runnerMock]));

        $this->assertSame($runTransfer, $facade->runNextOptimization());
    }

    public function testFindOptimizerRunInProgressDelegatesToTheRepository(): void
    {
        $runTransfer = new SearchRankingOptimizerRunTransfer();

        $repositoryMock = $this->createRepositoryMock();
        $repositoryMock->method('findOptimizerRunInProgress')->willReturn($runTransfer);

        $facade = $this->buildFacadeWithRepository($repositoryMock);

        $this->assertSame($runTransfer, $facade->findOptimizerRunInProgress());
    }

    public function testFindLatestOptimizerRunByStoreLocaleDelegatesToTheRepository(): void
    {
        $runTransfer = new SearchRankingOptimizerRunTransfer();

        $repositoryMock = $this->createRepositoryMock();
        $repositoryMock->method('findLatestOptimizerRunByStoreLocale')->with('DE', 'de_DE')->willReturn($runTransfer);

        $facade = $this->buildFacadeWithRepository($repositoryMock);

        $this->assertSame($runTransfer, $facade->findLatestOptimizerRunByStoreLocale('DE', 'de_DE'));
    }

    public function testApplyOptimizationRunDelegatesToTheOptimizationApplier(): void
    {
        $runTransfer = new SearchRankingOptimizerRunTransfer();

        $applierMock = $this->createMock(OptimizationApplierInterface::class);
        $applierMock->method('apply')->with(1)->willReturn($runTransfer);

        $facade = $this->buildFacadeWithFactory($this->createFactoryMock(['createOptimizationApplier' => $applierMock]));

        $this->assertSame($runTransfer, $facade->applyOptimizationRun(1));
    }

    /**
     * @param array<string, mixed> $returnMap Factory method name => the value that method should return.
     */
    protected function createFactoryMock(array $returnMap): SearchRankingOptimizerBusinessFactory
    {
        $factoryMock = $this->getMockBuilder(SearchRankingOptimizerBusinessFactory::class)
            ->onlyMethods(array_keys($returnMap))
            ->getMock();

        foreach ($returnMap as $methodName => $returnValue) {
            $factoryMock->method($methodName)->willReturn($returnValue);
        }

        return $factoryMock;
    }

    protected function createRepositoryMock(): SearchRankingOptimizerRepository
    {
        return $this->getMockBuilder(SearchRankingOptimizerRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    protected function buildFacadeWithFactory(SearchRankingOptimizerBusinessFactory $factoryMock): SearchRankingOptimizerFacade
    {
        $facade = new SearchRankingOptimizerFacade();
        $facade->setFactory($factoryMock);

        return $facade;
    }

    protected function buildFacadeWithRepository(SearchRankingOptimizerRepository $repositoryMock): SearchRankingOptimizerFacade
    {
        $facade = new SearchRankingOptimizerFacade();
        $facade->setRepository($repositoryMock);

        return $facade;
    }
}
