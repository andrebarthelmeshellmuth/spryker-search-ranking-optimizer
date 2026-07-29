<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Persistence;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Generated\Shared\Transfer\SearchRankingCalibrationSearchTermTransfer;
use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfigQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibration;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationSearchTerm;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationSearchTermQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingEvaluationQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingOptimizerRunQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRatingQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingWeightCheckpointQuery;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManager;

/**
 * INTEGRATION TEST — real database, real rows, never mocked: every method here is a thin Propel
 * read-modify-write, so the one behavior actually worth protecting is that it persists and reads back
 * correctly (correct FK linkage, correct column mapping, safe no-op on a not-found id) — a mocked query
 * builder could confirm the right methods were called but never that.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Persistence
 * @group SearchRankingOptimizerEntityManagerTest
 * Add your own group annotations below this line
 */
class SearchRankingOptimizerEntityManagerTest extends Unit
{
    /**
     * @var array<\Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibration>
     */
    protected array $calibrationEntities = [];

    /**
     * @var array<int>
     */
    protected array $evaluationIds = [];

    /**
     * @var array<int>
     */
    protected array $autoTuneMetricConfigIds = [];

    /**
     * @var array<int>
     */
    protected array $optimizerRunIds = [];

    /**
     * @var array<int>
     */
    protected array $queryIds = [];

    /**
     * @var array<int>
     */
    protected array $weightCheckpointIds = [];

    /**
     * @return void
     */
    protected function _after(): void
    {
        foreach ($this->calibrationEntities as $calibrationEntity) {
            $calibrationEntity->delete();
        }

        foreach ($this->evaluationIds as $idSearchRankingEvaluation) {
            SpySearchRankingEvaluationQuery::create()->findOneByIdSearchRankingEvaluation($idSearchRankingEvaluation)?->delete();
        }

        foreach ($this->autoTuneMetricConfigIds as $idSearchRankingAutoTuneMetricConfig) {
            SpySearchRankingAutoTuneMetricConfigQuery::create()->findOneByIdSearchRankingAutoTuneMetricConfig($idSearchRankingAutoTuneMetricConfig)?->delete();
        }

        foreach ($this->optimizerRunIds as $idSearchRankingOptimizerRun) {
            SpySearchRankingOptimizerRunQuery::create()->findOneByIdSearchRankingOptimizerRun($idSearchRankingOptimizerRun)?->delete();
        }

        foreach ($this->queryIds as $idSearchRankingQuery) {
            SpySearchRankingQueryRatingQuery::create()->filterByFkSearchRankingQuery($idSearchRankingQuery)->find()->delete();
            SpySearchRankingQueryQuery::create()->findOneByIdSearchRankingQuery($idSearchRankingQuery)?->delete();
        }

        foreach ($this->weightCheckpointIds as $idSearchRankingWeightCheckpoint) {
            SpySearchRankingWeightCheckpointQuery::create()->findOneByIdSearchRankingWeightCheckpoint($idSearchRankingWeightCheckpoint)?->delete();
        }

        parent::_after();
    }

    /**
     * @return void
     */
    public function testCreateCalibrationPersistsTheCalibrationAndItsSearchTermsWithCorrectForeignKeys(): void
    {
        // Arrange
        $calibrationTransfer = (new SearchRankingCalibrationTransfer())
            ->setRelevantProductCount(6)
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED)
            ->addSearchTerm((new SearchRankingCalibrationSearchTermTransfer())->setSearchTerm('chair'))
            ->addSearchTerm((new SearchRankingCalibrationSearchTermTransfer())->setSearchTerm('desk'));

        // Act
        $resultTransfer = (new SearchRankingOptimizerEntityManager())->createCalibration($calibrationTransfer);
        $this->trackForCleanup((int)$resultTransfer->getIdSearchRankingCalibrationOrFail());

        // Assert
        $this->assertNotNull($resultTransfer->getIdSearchRankingCalibration());
        $this->assertSame(2, $resultTransfer->getTotalCount(), 'totalCount is set from the search term count at creation time.');
        $this->assertSame(0, $resultTransfer->getProcessedCount());

        $searchTermEntities = SpySearchRankingCalibrationSearchTermQuery::create()
            ->filterByFkSearchRankingCalibration($resultTransfer->getIdSearchRankingCalibrationOrFail())
            ->find();

        $this->assertCount(2, $searchTermEntities);
        $this->assertEqualsCanonicalizing(
            ['chair', 'desk'],
            array_map(fn (SpySearchRankingCalibrationSearchTerm $entity): string => $entity->getSearchTerm(), iterator_to_array($searchTermEntities)),
        );
    }

    /**
     * @return void
     */
    public function testUpdateCalibrationStatusChangesTheStatusOfAnExistingCalibration(): void
    {
        // Arrange
        $calibrationEntity = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);

        // Act
        (new SearchRankingOptimizerEntityManager())->updateCalibrationStatus(
            $calibrationEntity->getIdSearchRankingCalibration(),
            SearchRankingOptimizerConfig::CALIBRATION_STATUS_SKIPPED,
        );

        // Assert
        $calibrationEntity->reload();
        $this->assertSame(SearchRankingOptimizerConfig::CALIBRATION_STATUS_SKIPPED, $calibrationEntity->getStatus());
    }

    /**
     * @return void
     */
    public function testUpdateCalibrationStatusIsASafeNoOpForANonExistentId(): void
    {
        // Act & Assert (must not throw)
        (new SearchRankingOptimizerEntityManager())->updateCalibrationStatus(-1, SearchRankingOptimizerConfig::CALIBRATION_STATUS_SKIPPED);
    }

    /**
     * @return void
     */
    public function testIncrementCalibrationProcessedCountAddsOneEachCall(): void
    {
        // Arrange
        $calibrationEntity = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATING);
        $entityManager = new SearchRankingOptimizerEntityManager();

        // Act
        $entityManager->incrementCalibrationProcessedCount($calibrationEntity->getIdSearchRankingCalibration());
        $entityManager->incrementCalibrationProcessedCount($calibrationEntity->getIdSearchRankingCalibration());

        // Assert
        $calibrationEntity->reload();
        $this->assertSame(2, $calibrationEntity->getProcessedCount());
    }

    /**
     * @return void
     */
    public function testIncrementCalibrationProcessedCountIsASafeNoOpForANonExistentId(): void
    {
        // Act & Assert (must not throw)
        (new SearchRankingOptimizerEntityManager())->incrementCalibrationProcessedCount(-1);
    }

    /**
     * @return void
     */
    public function testSaveCalibrationSearchTermResultPersistsProductsFoundAndImplodedScores(): void
    {
        // Arrange
        $calibrationEntity = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);
        $searchTermEntity = new SpySearchRankingCalibrationSearchTerm();
        $searchTermEntity->setFkSearchRankingCalibration($calibrationEntity->getIdSearchRankingCalibration());
        $searchTermEntity->setSearchTerm('chair');
        $searchTermEntity->save();

        // Act
        (new SearchRankingOptimizerEntityManager())->saveCalibrationSearchTermResult(
            $searchTermEntity->getIdSearchRankingCalibrationSearchTerm(),
            3,
            [12.5, 13.5],
        );

        // Assert
        $searchTermEntity->reload();
        $this->assertSame(3, $searchTermEntity->getProductsFound());
        $this->assertSame('12.5,13.5', $searchTermEntity->getScores());
    }

    /**
     * @return void
     */
    public function testSaveCalibrationSearchTermResultIsASafeNoOpForANonExistentId(): void
    {
        // Act & Assert (must not throw)
        (new SearchRankingOptimizerEntityManager())->saveCalibrationSearchTermResult(-1, 3, [12.5, 13.5]);
    }

    /**
     * @return void
     */
    public function testSaveCalibrationStatisticsPersistsEveryStatisticAndFlipsStatusToCalculated(): void
    {
        // Arrange
        $calibrationEntity = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);
        $statisticsTransfer = (new SearchRankingCalibrationTransfer())
            ->setComputedK(18.5)
            ->setScoreMin(10.0)
            ->setScoreMax(28.0)
            ->setScoreMean(18.5)
            ->setScoreMedian(17.0)
            ->setScoreP25(14.0)
            ->setScoreP75(22.0)
            ->setSampleCount(10);

        // Act
        (new SearchRankingOptimizerEntityManager())->saveCalibrationStatistics(
            $calibrationEntity->getIdSearchRankingCalibration(),
            $statisticsTransfer,
        );

        // Assert
        $calibrationEntity->reload();
        $this->assertSame(18.5, $calibrationEntity->getComputedK());
        $this->assertSame(10.0, $calibrationEntity->getScoreMin());
        $this->assertSame(28.0, $calibrationEntity->getScoreMax());
        $this->assertSame(10, $calibrationEntity->getSampleCount());
        $this->assertNotNull($calibrationEntity->getCalculatedAt());
        $this->assertSame(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATED, $calibrationEntity->getStatus());
    }

    /**
     * @return void
     */
    public function testSaveCalibrationStatisticsIsASafeNoOpForANonExistentId(): void
    {
        // Act & Assert (must not throw)
        (new SearchRankingOptimizerEntityManager())->saveCalibrationStatistics(-1, new SearchRankingCalibrationTransfer());
    }

    /**
     * @return void
     */
    public function testMarkCalibrationFailedPersistsTheErrorMessageAndFlipsStatusToFailed(): void
    {
        // Arrange
        $calibrationEntity = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);

        // Act
        (new SearchRankingOptimizerEntityManager())->markCalibrationFailed(
            $calibrationEntity->getIdSearchRankingCalibration(),
            'No search term produced any score.',
        );

        // Assert
        $calibrationEntity->reload();
        $this->assertSame(SearchRankingOptimizerConfig::CALIBRATION_STATUS_FAILED, $calibrationEntity->getStatus());
        $this->assertSame('No search term produced any score.', $calibrationEntity->getErrorMessage());
    }

    /**
     * @return void
     */
    public function testMarkCalibrationFailedIsASafeNoOpForANonExistentId(): void
    {
        // Act & Assert (must not throw)
        (new SearchRankingOptimizerEntityManager())->markCalibrationFailed(-1, 'irrelevant');
    }

    /**
     * @return void
     */
    public function testCreateEvaluationPersistsTheEvaluation(): void
    {
        // Arrange
        $evaluationTransfer = (new SearchRankingEvaluationTransfer())
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setMetricScore(0.7123)
            ->setQueryCount(5);

        // Act
        $resultTransfer = (new SearchRankingOptimizerEntityManager())->createEvaluation($evaluationTransfer);
        $this->evaluationIds[] = $resultTransfer->getIdSearchRankingEvaluationOrFail();

        // Assert
        $this->assertNotNull($resultTransfer->getIdSearchRankingEvaluation());
        $this->assertSame('DE', $resultTransfer->getStoreName());
        $this->assertSame('en_US', $resultTransfer->getLocaleName());
        $this->assertSame(0.7123, $resultTransfer->getMetricScore());
        $this->assertSame(5, $resultTransfer->getQueryCount());
        $this->assertNotNull($resultTransfer->getCreatedAt());
    }

    /**
     * @return void
     */
    public function testSaveAutoTuneMetricConfigCreatesANewRowWhenNoneExistsYetForTheMetric(): void
    {
        // Arrange
        $autoTuneMetricConfigTransfer = (new SearchRankingAutoTuneMetricConfigTransfer())
            ->setIdSearchRankingMetric(90001)
            ->setAutoTuneThreshold(0.8)
            ->setIsAutoUpdateEnabled(true)
            ->setAutoUpdateScope(SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY)
            ->setIsNotifyEnabled(true);

        // Act
        $resultTransfer = (new SearchRankingOptimizerEntityManager())->saveAutoTuneMetricConfig($autoTuneMetricConfigTransfer);
        $this->autoTuneMetricConfigIds[] = $resultTransfer->getIdSearchRankingAutoTuneMetricConfigOrFail();

        // Assert
        $this->assertNotNull($resultTransfer->getIdSearchRankingAutoTuneMetricConfig());
        $this->assertSame(90001, $resultTransfer->getIdSearchRankingMetric());
        $this->assertSame(0.8, $resultTransfer->getAutoTuneThreshold());
        $this->assertTrue($resultTransfer->getIsAutoUpdateEnabled());
        $this->assertSame(SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY, $resultTransfer->getAutoUpdateScope());
        $this->assertTrue($resultTransfer->getIsNotifyEnabled());
    }

    /**
     * @return void
     */
    public function testSaveAutoTuneMetricConfigUpdatesTheExistingRowForThatMetricInsteadOfCreatingASecondOne(): void
    {
        // Arrange
        $firstSaveTransfer = (new SearchRankingOptimizerEntityManager())->saveAutoTuneMetricConfig(
            (new SearchRankingAutoTuneMetricConfigTransfer())
                ->setIdSearchRankingMetric(90002)
                ->setAutoTuneThreshold(0.8)
                ->setIsAutoUpdateEnabled(false)
                ->setAutoUpdateScope(SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE)
                ->setIsNotifyEnabled(false),
        );
        $this->autoTuneMetricConfigIds[] = $firstSaveTransfer->getIdSearchRankingAutoTuneMetricConfigOrFail();

        // Act
        $secondSaveTransfer = (new SearchRankingOptimizerEntityManager())->saveAutoTuneMetricConfig(
            (new SearchRankingAutoTuneMetricConfigTransfer())
                ->setIdSearchRankingMetric(90002)
                ->setAutoTuneThreshold(0.6)
                ->setIsAutoUpdateEnabled(true)
                ->setAutoUpdateScope(SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY)
                ->setIsNotifyEnabled(true),
        );

        // Assert
        $this->assertSame($firstSaveTransfer->getIdSearchRankingAutoTuneMetricConfig(), $secondSaveTransfer->getIdSearchRankingAutoTuneMetricConfig());
        $this->assertSame(0.6, $secondSaveTransfer->getAutoTuneThreshold());
        $this->assertTrue($secondSaveTransfer->getIsAutoUpdateEnabled());
        $this->assertSame(SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY, $secondSaveTransfer->getAutoUpdateScope());
        $this->assertTrue($secondSaveTransfer->getIsNotifyEnabled());
        $this->assertSame(1, SpySearchRankingAutoTuneMetricConfigQuery::create()->filterByFkSearchRankingMetric(90002)->count());
    }

    /**
     * @param string $status
     *
     * @return \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibration
     */
    protected function createTestCalibration(string $status): SpySearchRankingCalibration
    {
        $calibrationEntity = new SpySearchRankingCalibration();
        $calibrationEntity->setRelevantProductCount(6);
        $calibrationEntity->setStoreName('DE');
        $calibrationEntity->setLocaleName('en_US');
        $calibrationEntity->setStatus($status);
        $calibrationEntity->save();

        $this->calibrationEntities[] = $calibrationEntity;

        return $calibrationEntity;
    }

    /**
     * @param int $idSearchRankingCalibration
     *
     * @return void
     */
    protected function trackForCleanup(int $idSearchRankingCalibration): void
    {
        $calibrationEntity = SpySearchRankingCalibrationQuery::create()->findOneByIdSearchRankingCalibration($idSearchRankingCalibration);

        if ($calibrationEntity === null) {
            return;
        }

        $this->calibrationEntities[] = $calibrationEntity;
    }

    /**
     * @return void
     */
    public function testCreateOptimizerRunPersistsAQueuedRun(): void
    {
        // Arrange
        $optimizerRunTransfer = (new SearchRankingOptimizerRunTransfer())
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setAlgorithm(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES);

        // Act
        $resultTransfer = (new SearchRankingOptimizerEntityManager())->createOptimizerRun($optimizerRunTransfer);
        $this->optimizerRunIds[] = $resultTransfer->getIdSearchRankingOptimizerRunOrFail();

        // Assert
        $this->assertNotNull($resultTransfer->getIdSearchRankingOptimizerRun());
        $this->assertSame('DE', $resultTransfer->getStoreName());
        $this->assertSame('en_US', $resultTransfer->getLocaleName());
        $this->assertSame(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES, $resultTransfer->getAlgorithm());
        $this->assertSame(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_QUEUED, $resultTransfer->getStatus());
        $this->assertSame(0, $resultTransfer->getTotalCount());
        $this->assertSame(0, $resultTransfer->getProcessedCount());
    }

    /**
     * @return void
     */
    public function testStartOptimizerRunTransitionsToRunningAndSetsTotalCountAndBaselineScore(): void
    {
        // Arrange
        $idSearchRankingOptimizerRun = $this->createTestOptimizerRun();

        // Act
        (new SearchRankingOptimizerEntityManager())->startOptimizerRun($idSearchRankingOptimizerRun, 400, 0.65);

        // Assert
        $entity = SpySearchRankingOptimizerRunQuery::create()->findOneByIdSearchRankingOptimizerRun($idSearchRankingOptimizerRun);
        $this->assertSame(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_RUNNING, $entity->getStatus());
        $this->assertSame(400, $entity->getTotalCount());
        $this->assertSame(0.65, $entity->getBaselineScore());
    }

    /**
     * @return void
     */
    public function testStartOptimizerRunIsASafeNoOpForANonExistentId(): void
    {
        (new SearchRankingOptimizerEntityManager())->startOptimizerRun(999999999, 400, 0.65);
        $this->addToAssertionCount(1);
    }

    /**
     * @return void
     */
    public function testUpdateOptimizerRunProgressSetsProcessedCountToTheGivenValue(): void
    {
        // Arrange
        $idSearchRankingOptimizerRun = $this->createTestOptimizerRun();

        // Act
        (new SearchRankingOptimizerEntityManager())->updateOptimizerRunProgress($idSearchRankingOptimizerRun, 240);

        // Assert
        $entity = SpySearchRankingOptimizerRunQuery::create()->findOneByIdSearchRankingOptimizerRun($idSearchRankingOptimizerRun);
        $this->assertSame(240, $entity->getProcessedCount());
    }

    /**
     * @return void
     */
    public function testUpdateOptimizerRunProgressIsASafeNoOpForANonExistentId(): void
    {
        (new SearchRankingOptimizerEntityManager())->updateOptimizerRunProgress(999999999, 240);
        $this->addToAssertionCount(1);
    }

    /**
     * @return void
     */
    public function testCompleteOptimizerRunPersistsTheWinningCandidateAndSetsStatusDone(): void
    {
        // Arrange
        $idSearchRankingOptimizerRun = $this->createTestOptimizerRun();
        $bestMetricWeightTransfers = [
            (new SearchRankingWeightCheckpointMetricWeightTransfer())->setIdSearchRankingMetric(1)->setName('top_seller')->setWeight(0.6),
            (new SearchRankingWeightCheckpointMetricWeightTransfer())->setIdSearchRankingMetric(2)->setName('pdp_impressions')->setWeight(0.4),
        ];

        // Act
        (new SearchRankingOptimizerEntityManager())->completeOptimizerRun($idSearchRankingOptimizerRun, 0.8, $bestMetricWeightTransfers, 0.91, 1.2, 0.25, 12);

        // Assert
        $entity = SpySearchRankingOptimizerRunQuery::create()->findOneByIdSearchRankingOptimizerRun($idSearchRankingOptimizerRun);
        $this->assertSame(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE, $entity->getStatus());
        $this->assertSame(0.8, $entity->getBestRelevanceWeight());
        $this->assertSame(0.91, $entity->getBestScore());
        $this->assertSame(1.2, $entity->getBestEntropyWeightExponent());
        $this->assertSame(0.25, $entity->getBestEntropyWeightShiftMagnitude());
        $this->assertSame(12, $entity->getBestEntropyProbeResultSize());
        $this->assertNotNull($entity->getCompletedAt());
        $this->assertStringContainsString('top_seller', (string)$entity->getBestMetricWeights());
    }

    /**
     * @return void
     */
    public function testCompleteOptimizerRunIsASafeNoOpForANonExistentId(): void
    {
        (new SearchRankingOptimizerEntityManager())->completeOptimizerRun(999999999, 0.8, [], 0.91, 1.2, 0.25, 12);
        $this->addToAssertionCount(1);
    }

    /**
     * @return void
     */
    public function testFailOptimizerRunSetsStatusFailedAndTheErrorMessage(): void
    {
        // Arrange
        $idSearchRankingOptimizerRun = $this->createTestOptimizerRun();

        // Act
        (new SearchRankingOptimizerEntityManager())->failOptimizerRun($idSearchRankingOptimizerRun, 'Elasticsearch timed out.');

        // Assert
        $entity = SpySearchRankingOptimizerRunQuery::create()->findOneByIdSearchRankingOptimizerRun($idSearchRankingOptimizerRun);
        $this->assertSame(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_FAILED, $entity->getStatus());
        $this->assertSame('Elasticsearch timed out.', $entity->getErrorMessage());
    }

    /**
     * @return void
     */
    public function testFailOptimizerRunIsASafeNoOpForANonExistentId(): void
    {
        (new SearchRankingOptimizerEntityManager())->failOptimizerRun(999999999, 'irrelevant');
        $this->addToAssertionCount(1);
    }

    /**
     * @return void
     */
    public function testMarkOptimizerRunAppliedSetsAppliedAt(): void
    {
        // Arrange
        $idSearchRankingOptimizerRun = $this->createTestOptimizerRun();

        // Act
        (new SearchRankingOptimizerEntityManager())->markOptimizerRunApplied($idSearchRankingOptimizerRun);

        // Assert
        $entity = SpySearchRankingOptimizerRunQuery::create()->findOneByIdSearchRankingOptimizerRun($idSearchRankingOptimizerRun);
        $this->assertNotNull($entity->getAppliedAt());
    }

    /**
     * @return void
     */
    public function testMarkOptimizerRunAppliedIsASafeNoOpForANonExistentId(): void
    {
        (new SearchRankingOptimizerEntityManager())->markOptimizerRunApplied(999999999);
        $this->addToAssertionCount(1);
    }

    /**
     * @return int
     */
    protected function createTestOptimizerRun(): int
    {
        $resultTransfer = (new SearchRankingOptimizerEntityManager())->createOptimizerRun(
            (new SearchRankingOptimizerRunTransfer())
                ->setStoreName('DE')
                ->setLocaleName('en_US')
                ->setAlgorithm(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES),
        );

        $idSearchRankingOptimizerRun = $resultTransfer->getIdSearchRankingOptimizerRunOrFail();
        $this->optimizerRunIds[] = $idSearchRankingOptimizerRun;

        return $idSearchRankingOptimizerRun;
    }

    /**
     * @return void
     */
    public function testCreateQueryPersistsTheQuery(): void
    {
        // Arrange
        $queryTransfer = (new SearchRankingQueryTransfer())
            ->setSearchTerm('chair')
            ->setStoreName('DE-TEST-CREATE-QUERY')
            ->setLocaleName('en_US')
            ->setImportanceWeight(2.5);

        // Act
        $resultTransfer = (new SearchRankingOptimizerEntityManager())->createQuery($queryTransfer);
        $this->queryIds[] = $resultTransfer->getIdSearchRankingQueryOrFail();

        // Assert
        $this->assertNotNull($resultTransfer->getIdSearchRankingQuery());
        $this->assertSame('chair', $resultTransfer->getSearchTerm());
        $this->assertSame('DE-TEST-CREATE-QUERY', $resultTransfer->getStoreName());
        $this->assertSame('en_US', $resultTransfer->getLocaleName());
        $this->assertSame(2.5, $resultTransfer->getImportanceWeight());
    }

    /**
     * @return void
     */
    public function testCreateQueryDefaultsImportanceWeightWhenNoneGiven(): void
    {
        // Arrange
        $queryTransfer = (new SearchRankingQueryTransfer())
            ->setSearchTerm('desk')
            ->setStoreName('DE-TEST-CREATE-QUERY-DEFAULT')
            ->setLocaleName('en_US');

        // Act
        $resultTransfer = (new SearchRankingOptimizerEntityManager())->createQuery($queryTransfer);
        $this->queryIds[] = $resultTransfer->getIdSearchRankingQueryOrFail();

        // Assert -- the entity's own column default applies, this must not have thrown or forced null through.
        $this->assertNotNull($resultTransfer->getImportanceWeight());
    }

    /**
     * @return void
     */
    public function testUpdateQueryImportanceWeightChangesTheWeightOfAnExistingQuery(): void
    {
        // Arrange
        $queryEntity = $this->createTestQueryEntity('lamp', 'DE-TEST-UPDATE-IMPORTANCE', 'en_US');

        // Act
        (new SearchRankingOptimizerEntityManager())->updateQueryImportanceWeight($queryEntity->getIdSearchRankingQuery(), 4.0);

        // Assert
        $queryEntity->reload();
        $this->assertSame(4.0, $queryEntity->getImportanceWeight());
    }

    /**
     * @return void
     */
    public function testUpdateQueryImportanceWeightIsASafeNoOpForANonExistentId(): void
    {
        (new SearchRankingOptimizerEntityManager())->updateQueryImportanceWeight(999999999, 4.0);
        $this->addToAssertionCount(1);
    }

    /**
     * @return void
     */
    public function testTouchQuerySetsUpdatedAtToNow(): void
    {
        // Arrange
        $queryEntity = $this->createTestQueryEntity('sofa', 'DE-TEST-TOUCH-QUERY', 'en_US');
        $queryEntity->setUpdatedAt('2026-01-01 00:00:00');
        $queryEntity->save();

        // Act
        (new SearchRankingOptimizerEntityManager())->touchQuery($queryEntity->getIdSearchRankingQuery());

        // Assert
        $queryEntity->reload();
        $this->assertGreaterThan(strtotime('2026-01-01 00:00:00'), (int)$queryEntity->getUpdatedAt('U'));
    }

    /**
     * @return void
     */
    public function testTouchQueryIsASafeNoOpForANonExistentId(): void
    {
        (new SearchRankingOptimizerEntityManager())->touchQuery(999999999);
        $this->addToAssertionCount(1);
    }

    /**
     * @return void
     */
    public function testUpsertRatingCreatesANewRatingAndTouchesTheQuery(): void
    {
        // Arrange -- id 9 is a real seeded product abstract (M1006811), needed to satisfy the rating
        // table's real FK constraint to spy_product_abstract.
        $queryEntity = $this->createTestQueryEntity('chair', 'DE-TEST-UPSERT-RATING-NEW', 'en_US');
        $queryEntity->setUpdatedAt('2026-01-01 00:00:00');
        $queryEntity->save();

        $ratingTransfer = (new SearchRankingQueryRatingTransfer())
            ->setFkSearchRankingQuery($queryEntity->getIdSearchRankingQuery())
            ->setCustomerReference('CUST-UPSERT-1')
            ->setFkProductAbstract(9)
            ->setRatingType('heart');

        // Act
        $resultTransfer = (new SearchRankingOptimizerEntityManager())->upsertRating($ratingTransfer);

        // Assert
        $this->assertNotNull($resultTransfer->getIdSearchRankingQueryRating());
        $this->assertSame('heart', $resultTransfer->getRatingType());

        $queryEntity->reload();
        $this->assertGreaterThan(strtotime('2026-01-01 00:00:00'), (int)$queryEntity->getUpdatedAt('U'), 'upsertRating() must touch the parent query.');
    }

    /**
     * @return void
     */
    public function testUpsertRatingUpdatesTheExistingRatingInsteadOfCreatingASecondOneForTheSameTriple(): void
    {
        // Arrange
        $queryEntity = $this->createTestQueryEntity('chair', 'DE-TEST-UPSERT-RATING-UPDATE', 'en_US');
        $entityManager = new SearchRankingOptimizerEntityManager();

        $firstRatingTransfer = (new SearchRankingQueryRatingTransfer())
            ->setFkSearchRankingQuery($queryEntity->getIdSearchRankingQuery())
            ->setCustomerReference('CUST-UPSERT-2')
            ->setFkProductAbstract(9)
            ->setRatingType('heart');
        $entityManager->upsertRating($firstRatingTransfer);

        // Act -- same identifying triple, different rating type
        $secondRatingTransfer = (new SearchRankingQueryRatingTransfer())
            ->setFkSearchRankingQuery($queryEntity->getIdSearchRankingQuery())
            ->setCustomerReference('CUST-UPSERT-2')
            ->setFkProductAbstract(9)
            ->setRatingType('cross');
        $entityManager->upsertRating($secondRatingTransfer);

        // Assert
        $ratingEntities = SpySearchRankingQueryRatingQuery::create()
            ->filterByFkSearchRankingQuery($queryEntity->getIdSearchRankingQuery())
            ->filterByCustomerReference('CUST-UPSERT-2')
            ->filterByFkProductAbstract(9)
            ->find();

        $this->assertCount(1, $ratingEntities, 'the second upsert must update the existing row, not create a second one.');
        $this->assertSame('cross', $ratingEntities->getFirst()->getRatingType());
    }

    /**
     * @return void
     */
    public function testDeleteRatingRemovesTheMatchingRating(): void
    {
        // Arrange
        $queryEntity = $this->createTestQueryEntity('chair', 'DE-TEST-DELETE-RATING', 'en_US');
        (new SearchRankingOptimizerEntityManager())->upsertRating(
            (new SearchRankingQueryRatingTransfer())
                ->setFkSearchRankingQuery($queryEntity->getIdSearchRankingQuery())
                ->setCustomerReference('CUST-DELETE-1')
                ->setFkProductAbstract(9)
                ->setRatingType('heart'),
        );

        // Act
        (new SearchRankingOptimizerEntityManager())->deleteRating($queryEntity->getIdSearchRankingQuery(), 'CUST-DELETE-1', 9);

        // Assert
        $this->assertSame(
            0,
            SpySearchRankingQueryRatingQuery::create()
                ->filterByFkSearchRankingQuery($queryEntity->getIdSearchRankingQuery())
                ->filterByCustomerReference('CUST-DELETE-1')
                ->filterByFkProductAbstract(9)
                ->count(),
        );
    }

    /**
     * @return void
     */
    public function testDeleteRatingIsASafeNoOpWhenNoMatchingRatingExists(): void
    {
        (new SearchRankingOptimizerEntityManager())->deleteRating(999999999, 'CUST-NONE', 9);
        $this->addToAssertionCount(1);
    }

    /**
     * @return void
     */
    public function testCreateWeightCheckpointPersistsEveryFieldAndEncodesTheMetricWeights(): void
    {
        // Arrange
        $weightCheckpointTransfer = (new SearchRankingWeightCheckpointTransfer())
            ->setSource(SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_OPTIMIZER)
            ->setRelevanceWeight(0.85)
            ->setEntropyProbeResultSize(50)
            ->setEntropyWeightExponent(1.5)
            ->setEntropyWeightShiftMagnitude(0.1)
            ->setIsEntropyWeightingEnabled(true)
            ->addMetricWeight(
                (new SearchRankingWeightCheckpointMetricWeightTransfer())
                    ->setIdSearchRankingMetric(1)
                    ->setName('top_seller')
                    ->setWeight(0.6),
            );

        // Act
        $resultTransfer = (new SearchRankingOptimizerEntityManager())->createWeightCheckpoint($weightCheckpointTransfer);
        $this->weightCheckpointIds[] = $resultTransfer->getIdSearchRankingWeightCheckpointOrFail();

        // Assert
        $this->assertNotNull($resultTransfer->getIdSearchRankingWeightCheckpoint());
        $this->assertSame(SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_OPTIMIZER, $resultTransfer->getSource());
        $this->assertSame(0.85, $resultTransfer->getRelevanceWeight());
        $this->assertTrue($resultTransfer->getIsEntropyWeightingEnabled());
        $metricWeightTransfers = iterator_to_array($resultTransfer->getMetricWeights());
        $this->assertCount(1, $metricWeightTransfers);
        $this->assertSame('top_seller', $metricWeightTransfers[0]->getName());
        $this->assertSame(0.6, $metricWeightTransfers[0]->getWeight());
    }

    /**
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     *
     * @return \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQuery
     */
    protected function createTestQueryEntity(string $searchTerm, string $storeName, string $localeName): SpySearchRankingQuery
    {
        $queryEntity = new SpySearchRankingQuery();
        $queryEntity->setSearchTerm($searchTerm);
        $queryEntity->setStoreName($storeName);
        $queryEntity->setLocaleName($localeName);
        $queryEntity->save();

        $this->queryIds[] = $queryEntity->getIdSearchRankingQuery();

        return $queryEntity;
    }
}
