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
use Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfigQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibration;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationSearchTerm;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationSearchTermQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingEvaluationQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingOptimizerRunQuery;
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
        (new SearchRankingOptimizerEntityManager())->completeOptimizerRun($idSearchRankingOptimizerRun, 0.8, $bestMetricWeightTransfers, 0.91);

        // Assert
        $entity = SpySearchRankingOptimizerRunQuery::create()->findOneByIdSearchRankingOptimizerRun($idSearchRankingOptimizerRun);
        $this->assertSame(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE, $entity->getStatus());
        $this->assertSame(0.8, $entity->getBestRelevanceWeight());
        $this->assertSame(0.91, $entity->getBestScore());
        $this->assertNotNull($entity->getCompletedAt());
        $this->assertStringContainsString('top_seller', (string)$entity->getBestMetricWeights());
    }

    /**
     * @return void
     */
    public function testCompleteOptimizerRunIsASafeNoOpForANonExistentId(): void
    {
        (new SearchRankingOptimizerEntityManager())->completeOptimizerRun(999999999, 0.8, [], 0.91);
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
}
