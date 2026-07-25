<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Calibration;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingCalibrationSearchTermTransfer;
use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use RuntimeException;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\ScoreCalibrator;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\StatisticsCalculatorInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\SearchRankingCalibrationNotFoundException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group@group SearchRankingOptimizer
 * @group Business
 * @group Calibration
 * @group ScoreCalibratorTest
 * Add your own group annotations below this line
 */
class ScoreCalibratorTest extends Unit
{
    /**
     * @return void
     */
    public function testReturnsNullWhenThereIsNoUploadedCalibration(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('getUploadedCalibrations')->willReturn([]);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('updateCalibrationStatus');

        $calibrator = new ScoreCalibrator(
            $repositoryMock,
            $entityManagerMock,
            $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class),
            $this->createMock(StatisticsCalculatorInterface::class),
        );

        // Act
        $result = $calibrator->runNextCalibration();

        // Assert
        $this->assertNull($result);
    }

    /**
     * @return void
     */
    public function testSkipsEveryUploadedCalibrationExceptTheNewestWithoutCallingTheSearchClientForThem(): void
    {
        // Arrange
        $newest = $this->createCalibrationTransfer(3, []);
        $older = $this->createCalibrationTransfer(2, []);
        $oldest = $this->createCalibrationTransfer(1, []);

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('getUploadedCalibrations')->willReturn([$newest, $older, $oldest]);
        $repositoryMock->method('findCalibrationWithSearchTerms')->with(3)->willReturn($newest);

        $skippedIds = [];
        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->method('updateCalibrationStatus')
            ->willReturnCallback(function (int $id, string $status) use (&$skippedIds): void {
                if ($status !== SearchRankingOptimizerConfig::CALIBRATION_STATUS_SKIPPED) {
                    return;
                }

                $skippedIds[] = $id;
            });
        $entityManagerMock->expects($this->once())->method('markCalibrationFailed');

        $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
        $searchRankingClientMock->expects($this->never())->method('getCalibrationScores');

        $calibrator = new ScoreCalibrator(
            $repositoryMock,
            $entityManagerMock,
            $searchRankingClientMock,
            $this->createMock(StatisticsCalculatorInterface::class),
        );

        // Act
        $calibrator->runNextCalibration();

        // Assert
        $this->assertSame([2, 1], $skippedIds);
    }

    /**
     * A single search term's Elasticsearch call throwing must not abort the run — it is treated as 0
     * products found for that term, and every other term is still queried.
     *
     * @return void
     */
    public function testTreatsAFailingSearchTermAsZeroScoresWithoutAbortingTheRun(): void
    {
        // Arrange
        $searchTerms = [
            $this->createSearchTermTransfer(10, 'broken'),
            $this->createSearchTermTransfer(11, 'chair'),
        ];
        $calibrationTransfer = $this->createCalibrationTransfer(1, $searchTerms);

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('getUploadedCalibrations')->willReturn([$calibrationTransfer]);
        $repositoryMock->method('findCalibrationWithSearchTerms')->willReturn($calibrationTransfer);

        $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
        $searchRankingClientMock->method('getCalibrationScores')
            ->willReturnCallback(function (string $searchTerm) {
                if ($searchTerm === 'broken') {
                    throw new RuntimeException('Elasticsearch is unreachable.');
                }

                return [12.5, 13.5];
            });

        $capturedProductsFoundByTermId = [];
        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->method('saveCalibrationSearchTermResult')
            ->willReturnCallback(function (int $idSearchTerm, int $productsFound) use (&$capturedProductsFoundByTermId): void {
                $capturedProductsFoundByTermId[$idSearchTerm] = $productsFound;
            });

        $statisticsCalculatorMock = $this->createMock(StatisticsCalculatorInterface::class);
        $statisticsCalculatorMock->expects($this->once())
            ->method('calculate')
            ->with([12.5, 13.5])
            ->willReturn(new SearchRankingCalibrationTransfer());

        $calibrator = new ScoreCalibrator($repositoryMock, $entityManagerMock, $searchRankingClientMock, $statisticsCalculatorMock);

        // Act
        $calibrator->runNextCalibration();

        // Assert
        $this->assertSame(0, $capturedProductsFoundByTermId[10]);
        $this->assertSame(2, $capturedProductsFoundByTermId[11]);
    }

    /**
     * @return void
     */
    public function testMarksTheCalibrationFailedWhenNoSearchTermProducedAnyScore(): void
    {
        // Arrange
        $searchTerms = [$this->createSearchTermTransfer(10, 'nomatch')];
        $calibrationTransfer = $this->createCalibrationTransfer(1, $searchTerms);

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('getUploadedCalibrations')->willReturn([$calibrationTransfer]);
        $repositoryMock->method('findCalibrationWithSearchTerms')->willReturn($calibrationTransfer);

        $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
        $searchRankingClientMock->method('getCalibrationScores')->willReturn([]);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())->method('markCalibrationFailed')->with(1, $this->isType('string'));
        $entityManagerMock->expects($this->never())->method('saveCalibrationStatistics');

        $calibrator = new ScoreCalibrator(
            $repositoryMock,
            $entityManagerMock,
            $searchRankingClientMock,
            $this->createMock(StatisticsCalculatorInterface::class),
        );

        // Act
        $calibrator->runNextCalibration();
    }

    /**
     * A real, if rare, race: the calibration row is deleted (or otherwise vanishes) between being listed
     * as "uploaded" and actually being picked up to calculate — `calculate()` must surface this loudly
     * rather than silently proceeding with a missing calibration.
     *
     * @return void
     */
    public function testThrowsWhenTheCalibrationVanishesBeforeItCanBeCalculated(): void
    {
        // Arrange
        $newest = $this->createCalibrationTransfer(1, []);

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('getUploadedCalibrations')->willReturn([$newest]);
        $repositoryMock->method('findCalibrationWithSearchTerms')->with(1)->willReturn(null);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);

        $calibrator = new ScoreCalibrator(
            $repositoryMock,
            $entityManagerMock,
            $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class),
            $this->createMock(StatisticsCalculatorInterface::class),
        );

        // Assert
        $this->expectException(SearchRankingCalibrationNotFoundException::class);
        $this->expectExceptionMessage('Search ranking calibration with id "1" was not found.');

        // Act
        $calibrator->runNextCalibration();
    }

    /**
     * @param int $idSearchRankingCalibration
     * @param array<\Generated\Shared\Transfer\SearchRankingCalibrationSearchTermTransfer> $searchTermTransfers
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer
     */
    protected function createCalibrationTransfer(int $idSearchRankingCalibration, array $searchTermTransfers): SearchRankingCalibrationTransfer
    {
        $calibrationTransfer = (new SearchRankingCalibrationTransfer())
            ->setIdSearchRankingCalibration($idSearchRankingCalibration)
            ->setRelevantProductCount(6)
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);

        foreach ($searchTermTransfers as $searchTermTransfer) {
            $calibrationTransfer->addSearchTerm($searchTermTransfer);
        }

        return $calibrationTransfer;
    }

    /**
     * @param int $idSearchRankingCalibrationSearchTerm
     * @param string $searchTerm
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationSearchTermTransfer
     */
    protected function createSearchTermTransfer(int $idSearchRankingCalibrationSearchTerm, string $searchTerm): SearchRankingCalibrationSearchTermTransfer
    {
        return (new SearchRankingCalibrationSearchTermTransfer())
            ->setIdSearchRankingCalibrationSearchTerm($idSearchRankingCalibrationSearchTerm)
            ->setSearchTerm($searchTerm);
    }
}
