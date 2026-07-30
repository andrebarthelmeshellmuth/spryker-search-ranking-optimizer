<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Persistence;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingCalibrationSearchTermTransfer;
use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibration;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationSearchTerm;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationSearchTermQuery;
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
}
