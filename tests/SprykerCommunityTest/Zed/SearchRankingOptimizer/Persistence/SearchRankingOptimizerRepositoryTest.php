<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Persistence;

use Codeception\Test\Unit;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibration;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationSearchTerm;
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
     * @var array<\Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibration>
     */
    protected array $calibrationEntities = [];

    /**
     * @return void
     */
    protected function _after(): void
    {
        foreach ($this->calibrationEntities as $calibrationEntity) {
            $calibrationEntity->delete();
        }

        parent::_after();
    }

    /**
     * @return void
     */
    public function testGetUploadedCalibrationsReturnsOnlyUploadedStatusRowsNewestFirst(): void
    {
        // Arrange
        $older = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);
        $newer = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);
        $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATED);

        // Act
        $calibrationTransfers = (new SearchRankingOptimizerRepository())->getUploadedCalibrations();
        $returnedIds = array_map(fn ($transfer) => $transfer->getIdSearchRankingCalibration(), $calibrationTransfers);

        // Assert — both uploaded rows present, newest first, calculated row excluded
        $newerPosition = array_search($newer->getIdSearchRankingCalibration(), $returnedIds, true);
        $olderPosition = array_search($older->getIdSearchRankingCalibration(), $returnedIds, true);

        $this->assertNotFalse($newerPosition);
        $this->assertNotFalse($olderPosition);
        $this->assertLessThan($olderPosition, $newerPosition);
    }

    /**
     * @return void
     */
    public function testFindCalibrationWithSearchTermsReturnsTheCalibrationWithItsSearchTermsAttached(): void
    {
        // Arrange
        $calibrationEntity = $this->createTestCalibration(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);

        $firstSearchTermEntity = new SpySearchRankingCalibrationSearchTerm();
        $firstSearchTermEntity->setFkSearchRankingCalibration($calibrationEntity->getIdSearchRankingCalibration());
        $firstSearchTermEntity->setSearchTerm('chair');
        $firstSearchTermEntity->save();

        $secondSearchTermEntity = new SpySearchRankingCalibrationSearchTerm();
        $secondSearchTermEntity->setFkSearchRankingCalibration($calibrationEntity->getIdSearchRankingCalibration());
        $secondSearchTermEntity->setSearchTerm('desk');
        $secondSearchTermEntity->save();

        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findCalibrationWithSearchTerms(
            $calibrationEntity->getIdSearchRankingCalibration(),
        );

        // Assert
        $this->assertNotNull($resultTransfer);
        $this->assertCount(2, $resultTransfer->getSearchTerms());
        $this->assertEqualsCanonicalizing(
            ['chair', 'desk'],
            array_map(fn ($searchTermTransfer) => $searchTermTransfer->getSearchTerm(), iterator_to_array($resultTransfer->getSearchTerms())),
        );
    }

    /**
     * @return void
     */
    public function testFindCalibrationWithSearchTermsReturnsNullForANonExistentId(): void
    {
        // Act
        $resultTransfer = (new SearchRankingOptimizerRepository())->findCalibrationWithSearchTerms(-1);

        // Assert
        $this->assertNull($resultTransfer);
    }

    /**
     * @return void
     */
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
        $this->assertSame($newer->getIdSearchRankingCalibration(), $resultTransfer->getIdSearchRankingCalibration());
    }

    /**
     * @return void
     */
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
        $this->assertSame($inProgress->getIdSearchRankingCalibration(), $resultTransfer->getIdSearchRankingCalibration());
        $this->assertSame(8, $resultTransfer->getTotalCount());
        $this->assertSame(3, $resultTransfer->getProcessedCount());
    }

    /**
     * @return void
     */
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
}
