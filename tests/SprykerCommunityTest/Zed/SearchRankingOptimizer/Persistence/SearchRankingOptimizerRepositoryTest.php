<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Persistence;

use Codeception\Test\Unit;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfig;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingEvaluation;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingOptimizerRun;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRating;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingSaturationPointCalibration;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingSaturationPointCalibrationSearchTerm;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingWeightCheckpoint;
use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig as SharedSearchRankingConfig;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepository;

/**
 * INTEGRATION TEST — real database, real rows, never mocked: every query here has real filtering/ordering
 * behavior worth protecting (status filtering, DESC ordering, "no row found" returning null instead of
 * throwing), none of which a mocked query builder could actually confirm.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Persistence
 * @group SearchRankingOptimizerRepositoryTest
 * Add your own group annotations below this line
 */
class SearchRankingOptimizerRepositoryTest extends Unit
{
    /**
     * @var array<\Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingSaturationPointCalibration>
     */
    protected array $calibrationEntities = [];

    /**
     * @var array<\Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQuery>
     */
    protected array $queryEntities = [];

    /**
     * @var array<\Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRating>
     */
    protected array $ratingEntities = [];

    /**
     * @var array<\Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingEvaluation>
     */
    protected array $evaluationEntities = [];

    /**
     * @var array<\Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfig>
     */
    protected array $autoTuneMetricConfigEntities = [];

    /**
     * @var array<\Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingOptimizerRun>
     */
    protected array $optimizerRunEntities = [];

    /**
     * @var array<\Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingWeightCheckpoint>
     */
    protected array $weightCheckpointEntities = [];

    protected function _after(): void
    {
        foreach ($this->calibrationEntities as $calibrationEntity) {
            $calibrationEntity->delete();
        }

        foreach ($this->ratingEntities as $ratingEntity) {
            $ratingEntity->delete();
        }

        foreach ($this->queryEntities as $queryEntity) {
            $queryEntity->delete();
        }

        foreach ($this->evaluationEntities as $evaluationEntity) {
            $evaluationEntity->delete();
        }

        foreach ($this->autoTuneMetricConfigEntities as $autoTuneMetricConfigEntity) {
            $autoTuneMetricConfigEntity->delete();
        }

        foreach ($this->optimizerRunEntities as $optimizerRunEntity) {
            $optimizerRunEntity->delete();
        }

        foreach ($this->weightCheckpointEntities as $weightCheckpointEntity) {
            $weightCheckpointEntity->delete();
        }

        parent::_after();
    }

    public function testGetUploadedCalibrationsReturnsOnlyUploadedStatusRowsNewestFirst(): void
    {
        // Arrange
        $older = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);
        $newer = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);
        $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATED);

        // Act
        $calibrationTransfers = (new SearchRankingOptimizerRepository())->getUploadedCalibrations();
        $returnedIds = array_map(fn ($transfer) => $transfer->getIdSearchRankingSaturationPointCalibration(), $calibrationTransfers);

        // Assert — both uploaded rows present, newest first, calculated row excluded
        $newerPosition = array_search($newer->getIdSearchRankingSaturationPointCalibration(), $returnedIds, true);
        $olderPosition = array_search($older->getIdSearchRankingSaturationPointCalibration(), $returnedIds, true);

        $this->assertNotFalse($newerPosition);
        $this->assertNotFalse($olderPosition);
        $this->assertLessThan($olderPosition, $newerPosition);
    }

    public function testFindCalibrationWithSearchTermsReturnsTheCalibrationWithItsSearchTermsAttached(): void
    {
        // Arrange
        $calibrationEntity = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);

        $firstSearchTermEntity = new SpySearchRankingSaturationPointCalibrationSearchTerm();
        $firstSearchTermEntity->setFkSearchRankingSaturationPointCalibration($calibrationEntity->getIdSearchRankingSaturationPointCalibration());
        $firstSearchTermEntity->setSearchTerm('chair');
        $firstSearchTermEntity->save();

        $secondSearchTermEntity = new SpySearchRankingSaturationPointCalibrationSearchTerm();
        $secondSearchTermEntity->setFkSearchRankingSaturationPointCalibration($calibrationEntity->getIdSearchRankingSaturationPointCalibration());
        $secondSearchTermEntity->setSearchTerm('desk');
        $secondSearchTermEntity->save();

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findCalibrationWithSearchTerms(
            $calibrationEntity->getIdSearchRankingSaturationPointCalibration(),
        );

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertCount(2, $resultTransfer->getSearchTerms());
        $this->assertEqualsCanonicalizing(
            ['chair', 'desk'],
            array_map(fn ($searchTermTransfer) => $searchTermTransfer->getSearchTerm(), iterator_to_array($resultTransfer->getSearchTerms())),
        );
    }

    public function testFindCalibrationWithSearchTermsReturnsNullForANonExistentId(): void
    {
        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findCalibrationWithSearchTerms(-1);

        // Assert
        $this->assertNull($resultTransfer);
    }

    public function testFindLatestCalculatedCalibrationReturnsTheMostRecentlyCalculatedRow(): void
    {
        // Arrange — "newer" uses a far-future date so it outranks any pre-existing real calibration row
        // in this shared demo database, not just the "older" row created alongside it in this test.
        $older = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATED);
        $older->setCalculatedAt('2026-01-01 00:00:00');
        $older->save();

        $newer = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATED);
        $newer->setCalculatedAt('2099-01-01 00:00:00');
        $newer->save();

        $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findLatestCalculatedCalibration();

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertSame($newer->getIdSearchRankingSaturationPointCalibration(), $resultTransfer->getIdSearchRankingSaturationPointCalibration());
    }

    public function testFindCalibrationInProgressReturnsTheCalculatingRowWithItsProgressCounts(): void
    {
        // Arrange
        $inProgress = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATING);
        $inProgress->setTotalCount(8);
        $inProgress->setProcessedCount(3);
        $inProgress->save();

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findCalibrationInProgress();

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertSame($inProgress->getIdSearchRankingSaturationPointCalibration(), $resultTransfer->getIdSearchRankingSaturationPointCalibration());
        $this->assertSame(8, $resultTransfer->getTotalCount());
        $this->assertSame(3, $resultTransfer->getProcessedCount());
    }

    public function testFindCalibrationInProgressReturnsNullWhenNothingIsCalculating(): void
    {
        // Arrange
        $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);
        $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATED);

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findCalibrationInProgress();

        // Assert
        $this->assertNull($resultTransfer);
    }

    public function testFindDistinctSearchTermsByStoreLocaleReturnsEachTermOnceForTheGivenStoreLocale(): void
    {
        // Arrange — an isolated store name so this never collides with real organic ratings in the shared
        // demo database.
        $storeName = 'DE-TEST-DISTINCT-TERMS';

        $this->createTestQuery('chair', $storeName, 'en_US');
        $this->createTestQuery('desk', $storeName, 'en_US');
        $this->createTestQuery('chair', $storeName, 'de_DE');
        $this->createTestQuery('lamp', 'DE-TEST-OTHER-STORE', 'en_US');

        // Act
        $searchTerms = (new SearchRankingOptimizerRepository())->findDistinctSearchTermsByStoreLocale($storeName, 'en_US');

        // Assert
        $this->assertEqualsCanonicalizing(['chair', 'desk'], $searchTerms);
    }

    public function testFindQueriesByStoreLocaleReturnsOnlyQueriesForThatStoreLocale(): void
    {
        // Arrange
        $storeName = 'DE-TEST-FIND-QUERIES';
        $matching = $this->createTestQuery('chair', $storeName, 'en_US');
        $this->createTestQuery('desk', $storeName, 'de_DE');
        $this->createTestQuery('lamp', 'DE-TEST-OTHER-STORE', 'en_US');

        // Act
        $queryTransfers = (new SearchRankingOptimizerRepository())->findQueriesByStoreLocale($storeName, 'en_US');

        // Assert
        $this->assertCount(1, $queryTransfers);
        $this->assertSame($matching->getIdSearchRankingQuery(), $queryTransfers[0]->getIdSearchRankingQuery());
    }

    public function testFindRatingsByStoreLocaleReturnsRatingsJoinedThroughTheQuery(): void
    {
        // Arrange — id 9 is a real seeded product abstract (M1006811), needed to satisfy the rating
        // table's real FK constraint to spy_product_abstract.
        $storeName = 'DE-TEST-FIND-RATINGS';
        $queryEntity = $this->createTestQuery('chair', $storeName, 'en_US');
        $otherStoreQueryEntity = $this->createTestQuery('chair', 'DE-TEST-OTHER-STORE', 'en_US');

        $matchingRating = $this->createTestRating($queryEntity->getIdSearchRankingQuery(), 'CUST-1', 9, 'heart');
        $this->createTestRating($otherStoreQueryEntity->getIdSearchRankingQuery(), 'CUST-1', 9, 'heart');

        // Act
        $ratingTransfers = (new SearchRankingOptimizerRepository())->findRatingsByStoreLocale($storeName, 'en_US');

        // Assert
        $this->assertCount(1, $ratingTransfers);
        $this->assertSame($matchingRating->getIdSearchRankingQueryRating(), $ratingTransfers[0]->getIdSearchRankingQueryRating());
    }

    public function testFindRatingsByQueryCustomerAndProductsReturnsOnlyThatCustomersRatingsForTheGivenProducts(): void
    {
        // Arrange — ids 9/10 are real seeded product abstracts, needed to satisfy the rating table's real
        // FK constraint to spy_product_abstract.
        $queryEntity = $this->createTestQuery('chair', 'DE-TEST-FIND-BY-QUERY-CUSTOMER', 'en_US');

        $matchingRating = $this->createTestRating($queryEntity->getIdSearchRankingQuery(), 'CUST-1', 9, 'heart');
        // Same query, different customer — must not leak into CUST-1's own result.
        $this->createTestRating($queryEntity->getIdSearchRankingQuery(), 'CUST-2', 9, 'check');
        // Same query, same customer, but a product NOT in the requested id list — must be excluded.
        $this->createTestRating($queryEntity->getIdSearchRankingQuery(), 'CUST-1', 10, 'x');

        // Act
        $ratingTransfers = (new SearchRankingOptimizerRepository())
            ->findRatingsByQueryCustomerAndProducts($queryEntity->getIdSearchRankingQuery(), 'CUST-1', [9]);

        // Assert
        $this->assertCount(1, $ratingTransfers);
        $this->assertSame($matchingRating->getIdSearchRankingQueryRating(), $ratingTransfers[0]->getIdSearchRankingQueryRating());
    }

    public function testFindRatingsByQueryCustomerAndProductsReturnsEmptyWithoutQueryingWhenNoProductIdsAreGiven(): void
    {
        // Act
        $ratingTransfers = (new SearchRankingOptimizerRepository())
            ->findRatingsByQueryCustomerAndProducts(999999, 'CUST-1', []);

        // Assert
        $this->assertSame([], $ratingTransfers);
    }

    public function testFindLatestEvaluationReturnsTheMostRecentRow(): void
    {
        // Arrange
        $storeName = 'DE-TEST-LATEST-EVAL';
        $older = $this->createTestEvaluation($storeName, 'en_US', 0.5, 3);
        $older->setCreatedAt('2026-01-01 00:00:00');
        $older->save();

        $newer = $this->createTestEvaluation($storeName, 'en_US', 0.7, 5);
        $newer->setCreatedAt('2099-01-01 00:00:00');
        $newer->save();

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findLatestEvaluation($storeName, 'en_US');

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertSame($newer->getIdSearchRankingEvaluation(), $resultTransfer->getIdSearchRankingEvaluation());
    }

    public function testFindLatestEvaluationReturnsNullWhenNoneExists(): void
    {
        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findLatestEvaluation('DE-TEST-NO-EVAL-EXISTS', 'en_US');

        // Assert
        $this->assertNull($resultTransfer);
    }

    public function testFindEvaluationHistoryByStoreLocaleReturnsNewestFirst(): void
    {
        // Arrange
        $storeName = 'DE-TEST-EVAL-HISTORY';
        $older = $this->createTestEvaluation($storeName, 'en_US', 0.5, 3);
        $older->setCreatedAt('2026-01-01 00:00:00');
        $older->save();

        $newer = $this->createTestEvaluation($storeName, 'en_US', 0.7, 5);
        $newer->setCreatedAt('2099-01-01 00:00:00');
        $newer->save();

        // Act
        $historyTransfers = (new SearchRankingOptimizerRepository())->findEvaluationHistoryByStoreLocale($storeName, 'en_US');

        // Assert
        $this->assertCount(2, $historyTransfers);
        $this->assertSame($newer->getIdSearchRankingEvaluation(), $historyTransfers[0]->getIdSearchRankingEvaluation());
        $this->assertSame($older->getIdSearchRankingEvaluation(), $historyTransfers[1]->getIdSearchRankingEvaluation());
    }

    public function testFindAutoTuneMetricConfigByMetricIdReturnsTheConfigForThatMetric(): void
    {
        // Arrange
        $this->createTestAutoTuneMetricConfig(90101, 0.8, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, true);

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findAutoTuneMetricConfigByMetricId(90101);

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertSame(90101, $resultTransfer->getIdSearchRankingMetric());
        $this->assertSame(0.8, $resultTransfer->getAutoTuneThreshold());
        $this->assertFalse($resultTransfer->getIsAutoUpdateEnabled());
        $this->assertSame(SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, $resultTransfer->getAutoUpdateScope());
        $this->assertTrue($resultTransfer->getIsNotifyEnabled());
    }

    public function testFindAutoTuneMetricConfigByMetricIdReturnsNullWhenTheMetricHasNoConfigYet(): void
    {
        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findAutoTuneMetricConfigByMetricId(90102);

        // Assert
        $this->assertNull($resultTransfer);
    }

    public function testFindAutoTuneMetricConfigsWithThresholdSetExcludesConfigsWithNoThreshold(): void
    {
        // Arrange
        $withThreshold = $this->createTestAutoTuneMetricConfig(90103, 0.8, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, false);
        $withoutThreshold = $this->createTestAutoTuneMetricConfig(90104, null, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, false);

        // Act
        $resultTransfers = (new SearchRankingOptimizerRepository())->findAutoTuneMetricConfigsWithThresholdSet();
        $returnedMetricIds = array_map(fn ($transfer) => $transfer->getIdSearchRankingMetric(), $resultTransfers);

        // Assert
        $this->assertContains($withThreshold->getFkSearchRankingMetric(), $returnedMetricIds);
        $this->assertNotContains($withoutThreshold->getFkSearchRankingMetric(), $returnedMetricIds);
    }

    /**
     * @param int $idSearchRankingMetric
     * @param float|null $autoTuneThreshold
     * @param bool $isAutoUpdateEnabled
     * @param string $autoUpdateScope
     * @param bool $isNotifyEnabled
     */
    protected function createTestAutoTuneMetricConfig(
        int $idSearchRankingMetric,
        ?float $autoTuneThreshold,
        bool $isAutoUpdateEnabled,
        string $autoUpdateScope,
        bool $isNotifyEnabled,
    ): SpySearchRankingAutoTuneMetricConfig {
        $autoTuneMetricConfigEntity = new SpySearchRankingAutoTuneMetricConfig();
        $autoTuneMetricConfigEntity->setFkSearchRankingMetric($idSearchRankingMetric);
        $autoTuneMetricConfigEntity->setAutoTuneThreshold($autoTuneThreshold);
        $autoTuneMetricConfigEntity->setIsAutoUpdateEnabled($isAutoUpdateEnabled);
        $autoTuneMetricConfigEntity->setAutoUpdateScope($autoUpdateScope);
        $autoTuneMetricConfigEntity->setIsNotifyEnabled($isNotifyEnabled);
        $autoTuneMetricConfigEntity->save();

        $this->autoTuneMetricConfigEntities[] = $autoTuneMetricConfigEntity;

        return $autoTuneMetricConfigEntity;
    }

    /**
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     */
    protected function createTestQuery(string $searchTerm, string $storeName, string $localeName): SpySearchRankingQuery
    {
        $queryEntity = new SpySearchRankingQuery();
        $queryEntity->setSearchTerm($searchTerm);
        $queryEntity->setStoreName($storeName);
        $queryEntity->setLocaleName($localeName);
        $queryEntity->save();

        $this->queryEntities[] = $queryEntity;

        return $queryEntity;
    }

    /**
     * @param string $status
     */
    protected function createTestCalibration(string $status): SpySearchRankingSaturationPointCalibration
    {
        $calibrationEntity = new SpySearchRankingSaturationPointCalibration();
        $calibrationEntity->setRelevantProductCount(6);
        $calibrationEntity->setStoreName('DE');
        $calibrationEntity->setLocaleName('en_US');
        $calibrationEntity->setStatus($status);
        $calibrationEntity->save();

        $this->calibrationEntities[] = $calibrationEntity;

        return $calibrationEntity;
    }

    /**
     * @param int $fkSearchRankingQuery
     * @param string $customerReference
     * @param int $fkProductAbstract
     * @param string $ratingType
     */
    protected function createTestRating(
        int $fkSearchRankingQuery,
        string $customerReference,
        int $fkProductAbstract,
        string $ratingType,
    ): SpySearchRankingQueryRating {
        $ratingEntity = new SpySearchRankingQueryRating();
        $ratingEntity->setFkSearchRankingQuery($fkSearchRankingQuery);
        $ratingEntity->setCustomerReference($customerReference);
        $ratingEntity->setFkProductAbstract($fkProductAbstract);
        $ratingEntity->setRatingType($ratingType);
        $ratingEntity->save();

        $this->ratingEntities[] = $ratingEntity;

        return $ratingEntity;
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param float $metricScore
     * @param int $queryCount
     */
    protected function createTestEvaluation(string $storeName, string $localeName, float $metricScore, int $queryCount): SpySearchRankingEvaluation
    {
        $evaluationEntity = new SpySearchRankingEvaluation();
        $evaluationEntity->setStoreName($storeName);
        $evaluationEntity->setLocaleName($localeName);
        $evaluationEntity->setMetricScore($metricScore);
        $evaluationEntity->setQueryCount($queryCount);
        $evaluationEntity->save();

        $this->evaluationEntities[] = $evaluationEntity;

        return $evaluationEntity;
    }

    public function testFindOptimizerRunByIdReturnsTheMatchingRow(): void
    {
        // Arrange
        $entity = $this->createTestOptimizerRun('DE', 'en_US', SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_QUEUED);

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findOptimizerRunById($entity->getIdSearchRankingOptimizerRun());

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertSame($entity->getIdSearchRankingOptimizerRun(), $resultTransfer->getIdSearchRankingOptimizerRun());
    }

    public function testFindOptimizerRunByIdReturnsNullForANonExistentId(): void
    {
        $resultTransfer = (new SearchRankingOptimizerRepository())->findOptimizerRunById(999999999);

        $this->assertNull($resultTransfer);
    }

    public function testFindOldestQueuedOptimizerRunReturnsTheOldestOneFirst(): void
    {
        // Arrange -- a done run must never be picked up, only queued ones, and the OLDEST of those.
        $this->createTestOptimizerRun('DE-TEST-OLDEST-QUEUED', 'en_US', SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE);
        $older = $this->createTestOptimizerRun('DE-TEST-OLDEST-QUEUED', 'en_US', SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_QUEUED);
        $this->createTestOptimizerRun('DE-TEST-OLDEST-QUEUED', 'en_US', SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_QUEUED);

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findOldestQueuedOptimizerRun();

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertSame($older->getIdSearchRankingOptimizerRun(), $resultTransfer->getIdSearchRankingOptimizerRun());
    }

    public function testFindOptimizerRunInProgressReturnsTheRunningRow(): void
    {
        // Arrange
        $entity = $this->createTestOptimizerRun('DE', 'en_US', SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_RUNNING);

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findOptimizerRunInProgress();

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertSame($entity->getIdSearchRankingOptimizerRun(), $resultTransfer->getIdSearchRankingOptimizerRun());
    }

    public function testFindOldestQueuedOptimizerRunReturnsNullWhenNothingIsQueued(): void
    {
        // Arrange
        $this->createTestOptimizerRun('DE-TEST-NO-QUEUED-RUN', 'en_US', SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE);

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findOldestQueuedOptimizerRun();

        // Assert -- same "this shared demo database never has a leftover row in this transient status"
        // assumption testFindCalibrationInProgressReturnsNullWhenNothingIsCalculating already relies on.
        $this->assertNull($resultTransfer);
    }

    public function testFindOptimizerRunInProgressReturnsNullWhenNothingIsRunning(): void
    {
        // Arrange
        $this->createTestOptimizerRun('DE-TEST-NO-RUN-IN-PROGRESS', 'en_US', SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_QUEUED);

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findOptimizerRunInProgress();

        // Assert -- same "this shared demo database never has a leftover row in this transient status"
        // assumption testFindCalibrationInProgressReturnsNullWhenNothingIsCalculating already relies on.
        $this->assertNull($resultTransfer);
    }

    public function testFindLatestOptimizerRunByStoreLocaleReturnsNullWhenNoneExistsForThatStoreLocale(): void
    {
        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findLatestOptimizerRunByStoreLocale('DE-TEST-NO-RUN-AT-ALL', 'en_US');

        // Assert
        $this->assertNull($resultTransfer);
    }

    public function testFindLatestOptimizerRunByStoreLocaleReturnsTheMostRecentRegardlessOfStatus(): void
    {
        // Arrange
        $storeName = 'DE-TEST-LATEST-RUN';
        $older = $this->createTestOptimizerRun($storeName, 'en_US', SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE);
        $older->setCreatedAt('2026-01-01 00:00:00');
        $older->save();

        $newer = $this->createTestOptimizerRun($storeName, 'en_US', SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_QUEUED);
        $newer->setCreatedAt('2099-01-01 00:00:00');
        $newer->save();

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findLatestOptimizerRunByStoreLocale($storeName, 'en_US');

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertSame($newer->getIdSearchRankingOptimizerRun(), $resultTransfer->getIdSearchRankingOptimizerRun());
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param string $status
     */
    protected function createTestOptimizerRun(string $storeName, string $localeName, string $status): SpySearchRankingOptimizerRun
    {
        $optimizerRunEntity = new SpySearchRankingOptimizerRun();
        $optimizerRunEntity->setStoreName($storeName);
        $optimizerRunEntity->setLocaleName($localeName);
        $optimizerRunEntity->setAlgorithm(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES);
        $optimizerRunEntity->setStatus($status);
        $optimizerRunEntity->save();

        $this->optimizerRunEntities[] = $optimizerRunEntity;

        return $optimizerRunEntity;
    }

    public function testFindQueryByTermStoreLocaleReturnsTheMatchingQuery(): void
    {
        // Arrange
        $storeName = 'DE-TEST-FIND-QUERY-BY-TERM';
        $matching = $this->createTestQuery('chair', $storeName, 'en_US');
        $this->createTestQuery('chair', $storeName, 'de_DE');
        $this->createTestQuery('desk', $storeName, 'en_US');

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findQueryByTermStoreLocale('chair', $storeName, 'en_US');

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertSame($matching->getIdSearchRankingQuery(), $resultTransfer->getIdSearchRankingQuery());
    }

    public function testFindQueryByTermStoreLocaleReturnsNullWhenNoQueryMatches(): void
    {
        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findQueryByTermStoreLocale('nonexistent-term', 'DE-TEST-NO-SUCH-QUERY', 'en_US');

        // Assert
        $this->assertNull($resultTransfer);
    }

    public function testFindQueryByIdReturnsTheMatchingQuery(): void
    {
        // Arrange
        $queryEntity = $this->createTestQuery('chair', 'DE-TEST-FIND-QUERY-BY-ID', 'en_US');

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findQueryById($queryEntity->getIdSearchRankingQuery());

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertSame($queryEntity->getIdSearchRankingQuery(), $resultTransfer->getIdSearchRankingQuery());
    }

    public function testFindQueryByIdReturnsNullForANonExistentId(): void
    {
        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findQueryById(999999999);

        // Assert
        $this->assertNull($resultTransfer);
    }

    public function testFindAllQueriesOrderedByUpdatedAtReturnsNewestFirst(): void
    {
        // Arrange
        $older = $this->createTestQuery('chair', 'DE-TEST-ALL-QUERIES-1', 'en_US');
        $older->setUpdatedAt('2026-01-01 00:00:00');
        $older->save();

        $newer = $this->createTestQuery('desk', 'DE-TEST-ALL-QUERIES-2', 'en_US');
        $newer->setUpdatedAt('2099-01-01 00:00:00');
        $newer->save();

        // Act
        $queryTransfers = (new SearchRankingOptimizerRepository())->findAllQueriesOrderedByUpdatedAt();
        $returnedIds = array_map(fn ($transfer) => $transfer->getIdSearchRankingQuery(), $queryTransfers);

        // Assert -- both present, newer strictly before older
        $newerPosition = array_search($newer->getIdSearchRankingQuery(), $returnedIds, true);
        $olderPosition = array_search($older->getIdSearchRankingQuery(), $returnedIds, true);

        $this->assertNotFalse($newerPosition);
        $this->assertNotFalse($olderPosition);
        $this->assertLessThan($olderPosition, $newerPosition);
    }

    public function testFindWeightCheckpointHistoryReturnsNewestFirst(): void
    {
        // Arrange
        $older = $this->createTestWeightCheckpoint(SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_MANUAL, 0.7);
        $older->setCreatedAt('2026-01-01 00:00:00');
        $older->save();

        $newer = $this->createTestWeightCheckpoint(SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_MANUAL, 0.9);
        $newer->setCreatedAt('2099-01-01 00:00:00');
        $newer->save();

        // Act
        $historyTransfers = (new SearchRankingOptimizerRepository())->findWeightCheckpointHistory();
        $returnedIds = array_map(fn ($transfer) => $transfer->getIdSearchRankingWeightCheckpoint(), $historyTransfers);

        // Assert -- both present, newer strictly before older (the shared demo database may hold other
        // real checkpoints too, so this only asserts relative order between these two).
        $newerPosition = array_search($newer->getIdSearchRankingWeightCheckpoint(), $returnedIds, true);
        $olderPosition = array_search($older->getIdSearchRankingWeightCheckpoint(), $returnedIds, true);

        $this->assertNotFalse($newerPosition);
        $this->assertNotFalse($olderPosition);
        $this->assertLessThan($olderPosition, $newerPosition);
    }

    public function testFindWeightCheckpointByIdReturnsTheMatchingCheckpoint(): void
    {
        // Arrange
        $weightCheckpointEntity = $this->createTestWeightCheckpoint(SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_OPTIMIZER, 0.85);

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findWeightCheckpointById($weightCheckpointEntity->getIdSearchRankingWeightCheckpoint());

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertSame($weightCheckpointEntity->getIdSearchRankingWeightCheckpoint(), $resultTransfer->getIdSearchRankingWeightCheckpoint());
        $this->assertSame(SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_OPTIMIZER, $resultTransfer->getSource());
        $this->assertSame(0.85, $resultTransfer->getRelevanceWeight());
    }

    public function testFindWeightCheckpointByIdReturnsNullForANonExistentId(): void
    {
        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findWeightCheckpointById(999999999);

        // Assert
        $this->assertNull($resultTransfer);
    }

    /**
     * @param string $source
     * @param float $relevanceWeight
     */
    protected function createTestWeightCheckpoint(string $source, float $relevanceWeight): SpySearchRankingWeightCheckpoint
    {
        $weightCheckpointEntity = new SpySearchRankingWeightCheckpoint();
        $weightCheckpointEntity->setSource($source);
        $weightCheckpointEntity->setStoreName(SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME);
        $weightCheckpointEntity->setLocaleName(SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME);
        $weightCheckpointEntity->setRelevanceWeight($relevanceWeight);
        $weightCheckpointEntity->setSpecificityBlendWeight(0.7);
        $weightCheckpointEntity->setSpecificityWeightExponent(1.5);
        $weightCheckpointEntity->setSpecificityWeightShiftMagnitude(0.1);
        $weightCheckpointEntity->setIsSpecificityWeightingEnabled(true);
        $weightCheckpointEntity->setMetricWeights(json_encode([['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'weight' => 1.0]]));
        $weightCheckpointEntity->save();

        $this->weightCheckpointEntities[] = $weightCheckpointEntity;

        return $weightCheckpointEntity;
    }
}
