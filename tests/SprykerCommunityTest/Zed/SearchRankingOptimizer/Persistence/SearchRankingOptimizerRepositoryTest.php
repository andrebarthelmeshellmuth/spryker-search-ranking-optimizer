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
 * @group NeedsDatabase
 */
class SearchRankingOptimizerRepositoryTest extends Unit
{
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
        // for this same (store, locale) in this shared demo database, not just the "older" row created
        // alongside it in this test.
        $older = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATED);
        $older->setCalculatedAt('2026-01-01 00:00:00');
        $older->save();

        $newer = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATED);
        $newer->setCalculatedAt('2099-01-01 00:00:00');
        $newer->save();

        $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findLatestCalculatedCalibration('DE', 'en_US');

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertSame($newer->getIdSearchRankingSaturationPointCalibration(), $resultTransfer->getIdSearchRankingSaturationPointCalibration());
    }

    /**
     * A calculated run for a DIFFERENT (store, locale) must never be returned as if it were the asked-about
     * scope's own latest run — the real regression this whole store/locale-scoping extension is for.
     * Isolated store names (not 'DE'/'AT') so this is self-contained regardless of real calibration rows
     * already present in this shared demo database.
     */
    public function testFindLatestCalculatedCalibrationIsScopedByStoreAndLocale(): void
    {
        // Arrange
        $matching = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATED, 'DE-TEST-CALIBRATION-SCOPE', 'de_DE');
        $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATED, 'AT-TEST-CALIBRATION-SCOPE', 'de_AT');

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findLatestCalculatedCalibration('DE-TEST-CALIBRATION-SCOPE', 'de_DE');
        $wrongStoreResultTransfer = (new SearchRankingOptimizerRepository())->findLatestCalculatedCalibration('AT-TEST-CALIBRATION-SCOPE', 'de_DE');

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertSame($matching->getIdSearchRankingSaturationPointCalibration(), $resultTransfer->getIdSearchRankingSaturationPointCalibration());
        $this->assertNull($wrongStoreResultTransfer, 'Right locale, wrong store must not match.');
    }

    public function testFindCalibrationInProgressReturnsTheCalculatingRowWithItsProgressCounts(): void
    {
        // Arrange
        $inProgress = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATING);
        $inProgress->setTotalCount(8);
        $inProgress->setProcessedCount(3);
        $inProgress->save();

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findCalibrationInProgress('DE', 'en_US');

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
        $resultTransfer = (new SearchRankingOptimizerRepository())->findCalibrationInProgress('DE', 'en_US');

        // Assert
        $this->assertNull($resultTransfer);
    }

    /**
     * A calculating run for a DIFFERENT (store, locale) must never be reported as the asked-about scope's
     * own in-progress run — otherwise the Calibration page's progress widget could show another store's
     * run as if it belonged to the one being viewed. Isolated store name for the same reason as
     * {@see testFindLatestCalculatedCalibrationIsScopedByStoreAndLocale()}.
     */
    public function testFindCalibrationInProgressIsScopedByStoreAndLocale(): void
    {
        // Arrange
        $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATING, 'AT-TEST-CALIBRATION-SCOPE', 'de_AT');

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findCalibrationInProgress('DE-TEST-CALIBRATION-SCOPE', 'de_DE');

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

    public function testFindEvaluationHistoryFilteredByStoreLocaleReturnsNewestFirst(): void
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
        $historyTransfers = (new SearchRankingOptimizerRepository())->findEvaluationHistory($storeName, 'en_US');

        // Assert
        $this->assertCount(2, $historyTransfers);
        $this->assertSame($newer->getIdSearchRankingEvaluation(), $historyTransfers[0]->getIdSearchRankingEvaluation());
        $this->assertSame($older->getIdSearchRankingEvaluation(), $historyTransfers[1]->getIdSearchRankingEvaluation());
    }

    public function testFindEvaluationHistoryWithNullStoreOrLocaleIgnoresThatFilter(): void
    {
        // Arrange
        $matchingStore = $this->createTestEvaluation('DE-TEST-EVAL-HISTORY-NULL', 'en_US', 0.5, 3);
        $otherLocale = $this->createTestEvaluation('DE-TEST-EVAL-HISTORY-NULL', 'de_DE', 0.6, 4);

        // Act -- storeName filtered, localeName left null (unfiltered): both rows for this store come back
        // regardless of locale.
        $historyTransfers = (new SearchRankingOptimizerRepository())->findEvaluationHistory('DE-TEST-EVAL-HISTORY-NULL');
        $returnedIds = array_map(fn ($transfer) => $transfer->getIdSearchRankingEvaluation(), $historyTransfers);

        // Assert
        $this->assertContains($matchingStore->getIdSearchRankingEvaluation(), $returnedIds);
        $this->assertContains($otherLocale->getIdSearchRankingEvaluation(), $returnedIds);
    }

    public function testFindAutoTuneMetricConfigByMetricIdReturnsTheConfigForThatMetric(): void
    {
        // Arrange
        $this->createTestAutoTuneMetricConfig(90101, 0.8, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, true, 'DE', 'de_DE');

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findAutoTuneMetricConfigByMetricId(90101, 'DE', 'de_DE');

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertSame(90101, $resultTransfer->getIdSearchRankingMetric());
        $this->assertSame('DE', $resultTransfer->getStoreName());
        $this->assertSame('de_DE', $resultTransfer->getLocaleName());
        $this->assertSame(0.8, $resultTransfer->getAutoTuneThreshold());
        $this->assertFalse($resultTransfer->getIsAutoUpdateEnabled());
        $this->assertSame(SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, $resultTransfer->getAutoUpdateScope());
        $this->assertTrue($resultTransfer->getIsNotifyEnabled());
    }

    public function testFindAutoTuneMetricConfigByMetricIdReturnsNullWhenTheMetricHasNoConfigYet(): void
    {
        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findAutoTuneMetricConfigByMetricId(90102, 'DE', 'de_DE');

        // Assert
        $this->assertNull($resultTransfer);
    }

    /**
     * A config saved for one store must be invisible when looking up the SAME metric under a different
     * store — proves lookups are genuinely scoped by (metric, store), not by metric alone.
     */
    public function testFindAutoTuneMetricConfigByMetricIdIsScopedByStore(): void
    {
        // Arrange
        $this->createTestAutoTuneMetricConfig(90105, 0.8, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, false, 'DE', 'de_DE');

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findAutoTuneMetricConfigByMetricId(90105, 'AT', 'de_DE');

        // Assert
        $this->assertNull($resultTransfer);
    }

    /**
     * A config saved for one locale of a store must be invisible when looking up the SAME metric+store
     * under a different locale — proves lookups are genuinely scoped by (metric, store, locale), the real
     * point of this whole extension, not just (metric, store).
     */
    public function testFindAutoTuneMetricConfigByMetricIdIsScopedByLocale(): void
    {
        // Arrange
        $this->createTestAutoTuneMetricConfig(90107, 0.8, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, false, 'DE', 'de_DE');

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findAutoTuneMetricConfigByMetricId(90107, 'DE', 'en_US');

        // Assert
        $this->assertNull($resultTransfer);
    }

    public function testFindAutoTuneMetricConfigsWithThresholdSetExcludesConfigsWithNoThreshold(): void
    {
        // Arrange
        $withThreshold = $this->createTestAutoTuneMetricConfig(90103, 0.8, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, false, 'DE', 'de_DE');
        $withoutThreshold = $this->createTestAutoTuneMetricConfig(90104, null, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, false, 'DE', 'de_DE');

        // Act
        $resultTransfers = (new SearchRankingOptimizerRepository())->findAutoTuneMetricConfigsWithThresholdSet('DE');
        $returnedMetricIds = array_map(fn ($transfer) => $transfer->getIdSearchRankingMetric(), $resultTransfers);

        // Assert
        $this->assertContains($withThreshold->getFkSearchRankingMetric(), $returnedMetricIds);
        $this->assertNotContains($withoutThreshold->getFkSearchRankingMetric(), $returnedMetricIds);
    }

    /**
     * The same metric can have a threshold set for one store and none for another — each store's list
     * must reflect only its own configs.
     */
    public function testFindAutoTuneMetricConfigsWithThresholdSetIsScopedByStore(): void
    {
        // Arrange
        $deConfig = $this->createTestAutoTuneMetricConfig(90106, 0.8, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, false, 'DE', 'de_DE');
        $this->createTestAutoTuneMetricConfig(90106, null, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, false, 'AT', 'de_AT');

        // Act
        $deResultTransfers = (new SearchRankingOptimizerRepository())->findAutoTuneMetricConfigsWithThresholdSet('DE');
        $atResultTransfers = (new SearchRankingOptimizerRepository())->findAutoTuneMetricConfigsWithThresholdSet('AT');

        // Assert
        $this->assertContains($deConfig->getFkSearchRankingMetric(), array_map(fn ($transfer) => $transfer->getIdSearchRankingMetric(), $deResultTransfers));
        $this->assertSame([], array_filter($atResultTransfers, fn ($transfer) => $transfer->getIdSearchRankingMetric() === 90106));
    }

    /**
     * Not locale-filtered: a metric with a threshold set at two different locales of the SAME store must
     * come back as two separate rows — proves the list genuinely reflects (metric, locale) granularity now,
     * not one row per metric.
     */
    public function testFindAutoTuneMetricConfigsWithThresholdSetReturnsOneRowPerConfiguredLocale(): void
    {
        // Arrange
        $this->createTestAutoTuneMetricConfig(90108, 0.8, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, false, 'DE', 'de_DE');
        $this->createTestAutoTuneMetricConfig(90108, 0.7, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, false, 'DE', 'en_US');

        // Act
        $resultTransfers = (new SearchRankingOptimizerRepository())->findAutoTuneMetricConfigsWithThresholdSet('DE');
        $localeNamesForMetric = array_map(
            fn ($transfer) => $transfer->getLocaleName(),
            array_filter($resultTransfers, fn ($transfer) => $transfer->getIdSearchRankingMetric() === 90108),
        );

        // Assert
        $this->assertEqualsCanonicalizing(['de_DE', 'en_US'], array_values($localeNamesForMetric));
    }

    /**
     * A config with notify enabled but NO threshold still counts: this answers "could this shop ever need
     * to email an admin", not "will it tonight" — see the method's own docblock.
     */
    public function testHasAutoTuneMetricConfigWithNotifyEnabledIsTrueForANotifyEnabledConfigWithoutAThreshold(): void
    {
        // Arrange
        $this->createTestAutoTuneMetricConfig(90109, null, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, true, 'DE', 'de_DE');

        // Act
        $result = (new SearchRankingOptimizerRepository())->hasAutoTuneMetricConfigWithNotifyEnabled();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Asserted against a baseline rather than a flat `assertFalse`: this query is deliberately unscoped, so
     * whatever notify-enabled configs the surrounding installation already has would otherwise decide the
     * outcome. What matters is that adding a notify-DISABLED row never flips it on by itself.
     */
    public function testHasAutoTuneMetricConfigWithNotifyEnabledIsUnaffectedByANotifyDisabledConfig(): void
    {
        // Arrange
        $repository = new SearchRankingOptimizerRepository();
        $baseline = $repository->hasAutoTuneMetricConfigWithNotifyEnabled();

        // Act
        $this->createTestAutoTuneMetricConfig(90110, 0.8, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, false, 'DE', 'de_DE');

        // Assert
        $this->assertSame($baseline, $repository->hasAutoTuneMetricConfigWithNotifyEnabled());
    }

    /**
     * @param int $idSearchRankingMetric
     * @param float|null $autoTuneThreshold
     * @param bool $isAutoUpdateEnabled
     * @param string $autoUpdateScope
     * @param bool $isNotifyEnabled
     * @param string $storeName
     * @param string $localeName
     */
    protected function createTestAutoTuneMetricConfig(
        int $idSearchRankingMetric,
        ?float $autoTuneThreshold,
        bool $isAutoUpdateEnabled,
        string $autoUpdateScope,
        bool $isNotifyEnabled,
        string $storeName = 'DE',
        string $localeName = 'de_DE',
    ): SpySearchRankingAutoTuneMetricConfig {
        $autoTuneMetricConfigEntity = new SpySearchRankingAutoTuneMetricConfig();
        $autoTuneMetricConfigEntity->setFkSearchRankingMetric($idSearchRankingMetric);
        $autoTuneMetricConfigEntity->setStoreName($storeName);
        $autoTuneMetricConfigEntity->setLocaleName($localeName);
        $autoTuneMetricConfigEntity->setAutoTuneThreshold($autoTuneThreshold);
        $autoTuneMetricConfigEntity->setIsAutoUpdateEnabled($isAutoUpdateEnabled);
        $autoTuneMetricConfigEntity->setAutoUpdateScope($autoUpdateScope);
        $autoTuneMetricConfigEntity->setIsNotifyEnabled($isNotifyEnabled);
        $autoTuneMetricConfigEntity->save();

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

        return $queryEntity;
    }

    /**
     * @param string $status
     */
    protected function createTestCalibration(string $status, string $storeName = 'DE', string $localeName = 'en_US'): SpySearchRankingSaturationPointCalibration
    {
        $calibrationEntity = new SpySearchRankingSaturationPointCalibration();
        $calibrationEntity->setRelevantProductCount(6);
        $calibrationEntity->setStoreName($storeName);
        $calibrationEntity->setLocaleName($localeName);
        $calibrationEntity->setStatus($status);
        $calibrationEntity->save();

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

    public function testFindWeightCheckpointHistoryFilteredByStoreLocaleExcludesOtherScopes(): void
    {
        // Arrange
        $matching = $this->createTestWeightCheckpoint(
            SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_MANUAL,
            0.7,
            'DE-TEST-CHECKPOINT-FILTER',
            'en_US',
        );
        $otherLocale = $this->createTestWeightCheckpoint(
            SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_MANUAL,
            0.8,
            'DE-TEST-CHECKPOINT-FILTER',
            'de_DE',
        );

        // Act
        $historyTransfers = (new SearchRankingOptimizerRepository())->findWeightCheckpointHistory('DE-TEST-CHECKPOINT-FILTER', 'en_US');
        $returnedIds = array_map(fn ($transfer) => $transfer->getIdSearchRankingWeightCheckpoint(), $historyTransfers);

        // Assert
        $this->assertContains($matching->getIdSearchRankingWeightCheckpoint(), $returnedIds);
        $this->assertNotContains($otherLocale->getIdSearchRankingWeightCheckpoint(), $returnedIds);
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
    protected function createTestWeightCheckpoint(
        string $source,
        float $relevanceWeight,
        ?string $storeName = null,
        ?string $localeName = null,
    ): SpySearchRankingWeightCheckpoint {
        $weightCheckpointEntity = new SpySearchRankingWeightCheckpoint();
        $weightCheckpointEntity->setSource($source);
        $weightCheckpointEntity->setStoreName($storeName ?? SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME);
        $weightCheckpointEntity->setLocaleName($localeName ?? SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME);
        $weightCheckpointEntity->setRelevanceWeight($relevanceWeight);
        $weightCheckpointEntity->setSpecificityBlendWeight(0.7);
        $weightCheckpointEntity->setSpecificityWeightExponent(1.5);
        $weightCheckpointEntity->setSpecificityWeightShiftMagnitude(0.1);
        $weightCheckpointEntity->setIsSpecificityWeightingEnabled(true);
        $weightCheckpointEntity->setMetricWeights(json_encode([['idSearchRankingMetric' => 1, 'name' => 'top_seller', 'weight' => 1.0]]));
        $weightCheckpointEntity->save();

        return $weightCheckpointEntity;
    }
}
