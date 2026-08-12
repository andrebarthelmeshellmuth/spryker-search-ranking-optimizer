<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationSearchTermTransfer;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer;
use RuntimeException;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\SearchRankingSaturationPointCalibrationNotFoundException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\ScoreCalibrator;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\StatisticsCalculatorInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group@group SearchRankingOptimizer
 * @group Business
 * @group SaturationPointCalibration
 * @group ScoreCalibratorTest
 * Add your own group annotations below this line
 */
class ScoreCalibratorTest extends Unit
{
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
            $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class),
        );

        // Act
        $result = $calibrator->runNextCalibration();

        // Assert
        $this->assertNull($result);
    }

    public function testSkipsEveryOlderUploadForTheSameTargetWithoutCallingTheSearchClientForThem(): void
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
            $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class),
        );

        // Act
        $calibrator->runNextCalibration();

        // Assert
        $this->assertSame([2, 1], $skippedIds);
    }

    /**
     * A DE/en_US upload says nothing about what AT/de_DE's own saturation point should be, so it must not
     * obsolete it — the other scope's upload stays queued for a later tick instead of being silently
     * marked skipped without a single query ever firing for it.
     */
    public function testLeavesAnUploadForAnotherScopeQueuedInsteadOfSkippingIt(): void
    {
        // Arrange
        $newest = $this->createCalibrationTransfer(3, []);
        $otherScope = $this->createCalibrationTransfer(2, [])->setStoreName('AT')->setLocaleName('de_DE');
        $otherLocaleOnly = $this->createCalibrationTransfer(1, [])->setLocaleName('de_DE');

        // Act
        $skippedIds = $this->grabSkippedIdsForRunNextCalibration([$newest, $otherScope, $otherLocaleOnly], 3);

        // Assert
        $this->assertSame([], $skippedIds);
    }

    /**
     * The two calibration types tune two DIFFERENT settings — `relevance_score` produces
     * `relevanceSaturationPoint`, `specificity` produces `specificitySaturationPoint` — so neither can
     * supersede the other even within one scope.
     */
    public function testLeavesAnUploadOfTheOtherCalibrationTypeQueuedInsteadOfSkippingIt(): void
    {
        // Arrange -- id 3 has no explicit type, which normalizes to relevance_score, the same target id 1
        // states explicitly; id 2 is the other type entirely.
        $newest = $this->createCalibrationTransfer(3, []);
        $otherType = $this->createCalibrationTransfer(2, [])
            ->setCalibrationType(SearchRankingOptimizerConfig::CALIBRATION_TYPE_SPECIFICITY);
        $sameTargetSpelledOut = $this->createCalibrationTransfer(1, [])
            ->setCalibrationType(SearchRankingOptimizerConfig::CALIBRATION_TYPE_RELEVANCE_SCORE);

        // Act
        $skippedIds = $this->grabSkippedIdsForRunNextCalibration([$newest, $otherType, $sameTargetSpelledOut], 3);

        // Assert
        $this->assertSame([1], $skippedIds);
    }

    /**
     * Runs `runNextCalibration()` against $uploadedCalibrations and returns the ids it moved to
     * status=skipped, in the order it skipped them. The newest run itself always reaches `calculate()`
     * with no search terms, so it fails harmlessly — this helper is only about the skip decision.
     *
     * @param array<\Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer> $uploadedCalibrations
     * @param int $idNewestCalibration
     *
     * @return array<int>
     */
    protected function grabSkippedIdsForRunNextCalibration(array $uploadedCalibrations, int $idNewestCalibration): array
    {
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('getUploadedCalibrations')->willReturn($uploadedCalibrations);
        $repositoryMock->method('findCalibrationWithSearchTerms')->with($idNewestCalibration)->willReturn($uploadedCalibrations[0]);

        $skippedIds = [];
        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->method('updateCalibrationStatus')
            ->willReturnCallback(function (int $id, string $status) use (&$skippedIds): void {
                if ($status !== SearchRankingOptimizerConfig::CALIBRATION_STATUS_SKIPPED) {
                    return;
                }

                $skippedIds[] = $id;
            });

        $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
        $searchRankingClientMock->expects($this->never())->method('getCalibrationScores');

        $calibrator = new ScoreCalibrator(
            $repositoryMock,
            $entityManagerMock,
            $searchRankingClientMock,
            $this->createMock(StatisticsCalculatorInterface::class),
            $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class),
        );

        $calibrator->runNextCalibration();

        return $skippedIds;
    }

    /**
     * A single search term's Elasticsearch call throwing must not abort the run — it is treated as 0
     * products found for that term, and every other term is still queried.
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
            ->willReturn(new SearchRankingSaturationPointCalibrationTransfer());

        $calibrator = new ScoreCalibrator(
            $repositoryMock,
            $entityManagerMock,
            $searchRankingClientMock,
            $statisticsCalculatorMock,
            $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class),
        );

        // Act
        $calibrator->runNextCalibration();

        // Assert
        $this->assertSame(0, $capturedProductsFoundByTermId[10]);
        $this->assertSame(2, $capturedProductsFoundByTermId[11]);
    }

    /**
     * A `calibrationType=specificity` run must fire `getCalibrationSpecificity()` (no real catalog query,
     * unlike `getCalibrationScores()`) using the LIVE `specificityBlendWeight`, and pool exactly ONE value
     * per search term rather than one per product.
     */
    public function testSpecificityCalibrationFetchesOneValuePerSearchTermUsingTheLiveBlendWeight(): void
    {
        // Arrange
        $searchTerms = [
            $this->createSearchTermTransfer(10, 'm11480'),
            $this->createSearchTermTransfer(11, 'office'),
        ];
        $calibrationTransfer = $this->createCalibrationTransfer(1, $searchTerms)
            ->setCalibrationType(SearchRankingOptimizerConfig::CALIBRATION_TYPE_SPECIFICITY);

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('getUploadedCalibrations')->willReturn([$calibrationTransfer]);
        $repositoryMock->method('findCalibrationWithSearchTerms')->willReturn($calibrationTransfer);

        $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
        $searchRankingClientMock->expects($this->never())->method('getCalibrationScores');
        $searchRankingClientMock->expects($this->exactly(2))
            ->method('getCalibrationSpecificity')
            ->with($this->isType('string'), 'DE', 0.7)
            ->willReturnCallback(fn (string $searchTerm): float => $searchTerm === 'm11480' ? 6.28 : 0.68);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('getSpecificityBlendWeight')->willReturn(0.7);

        $capturedValuesByTermId = [];
        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- the mock's signature must
        // match saveCalibrationSearchTermResult()'s real 3 arguments; only the values are captured below.
        $entityManagerMock->method('saveCalibrationSearchTermResult')
            ->willReturnCallback(function (int $idSearchTerm, int $productsFound, array $values) use (&$capturedValuesByTermId): void {
                $capturedValuesByTermId[$idSearchTerm] = $values;
            });
        // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter

        $statisticsCalculatorMock = $this->createMock(StatisticsCalculatorInterface::class);
        $statisticsCalculatorMock->expects($this->once())
            ->method('calculate')
            ->with([6.28, 0.68])
            ->willReturn(new SearchRankingSaturationPointCalibrationTransfer());

        $calibrator = new ScoreCalibrator($repositoryMock, $entityManagerMock, $searchRankingClientMock, $statisticsCalculatorMock, $searchRankingFacadeMock);

        // Act
        $calibrator->runNextCalibration();

        // Assert
        $this->assertSame([6.28], $capturedValuesByTermId[10]);
        $this->assertSame([0.68], $capturedValuesByTermId[11]);
    }

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
            $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class),
        );

        // Act
        $calibrator->runNextCalibration();
    }

    /**
     * The live progress counter's numerator: one increment per search term as the loop works through
     * them, regardless of whether that term actually matched anything (a "0 products found" term still
     * counts as processed).
     */
    public function testCalculateIncrementsProcessedCountOnceForEverySearchTermRegardlessOfResult(): void
    {
        // Arrange
        $searchTerms = [
            $this->createSearchTermTransfer(10, 'chair'),
            $this->createSearchTermTransfer(11, 'nomatch'),
        ];
        $calibrationTransfer = $this->createCalibrationTransfer(5, $searchTerms);

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('getUploadedCalibrations')->willReturn([$calibrationTransfer]);
        $repositoryMock->method('findCalibrationWithSearchTerms')->willReturn($calibrationTransfer);

        $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
        $searchRankingClientMock->method('getCalibrationScores')
            ->willReturnCallback(fn (string $searchTerm): array => $searchTerm === 'chair' ? [12.5] : []);

        $incrementedIds = [];
        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->exactly(2))
            ->method('incrementCalibrationProcessedCount')
            ->willReturnCallback(function (int $id) use (&$incrementedIds): void {
                $incrementedIds[] = $id;
            });

        $statisticsCalculatorMock = $this->createMock(StatisticsCalculatorInterface::class);
        $statisticsCalculatorMock->method('calculate')->willReturn(new SearchRankingSaturationPointCalibrationTransfer());

        $calibrator = new ScoreCalibrator(
            $repositoryMock,
            $entityManagerMock,
            $searchRankingClientMock,
            $statisticsCalculatorMock,
            $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class),
        );

        // Act
        $calibrator->runNextCalibration();

        // Assert
        $this->assertSame([5, 5], $incrementedIds);
    }

    /**
     * A real, if rare, race: the calibration row is deleted (or otherwise vanishes) between being listed
     * as "uploaded" and actually being picked up to calculate — `calculate()` must surface this loudly
     * rather than silently proceeding with a missing calibration.
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
            $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class),
        );

        // Assert
        $this->expectException(SearchRankingSaturationPointCalibrationNotFoundException::class);
        $this->expectExceptionMessage('Search ranking calibration with id "1" was not found.');

        // Act
        $calibrator->runNextCalibration();
    }

    /**
     * @param int $idSearchRankingSaturationPointCalibration
     * @param array<\Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationSearchTermTransfer> $searchTermTransfers
     */
    protected function createCalibrationTransfer(
        int $idSearchRankingSaturationPointCalibration,
        array $searchTermTransfers,
    ): SearchRankingSaturationPointCalibrationTransfer {
        $calibrationTransfer = (new SearchRankingSaturationPointCalibrationTransfer())
            ->setIdSearchRankingSaturationPointCalibration($idSearchRankingSaturationPointCalibration)
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
     * @param int $idSearchRankingSaturationPointCalibrationSearchTerm
     * @param string $searchTerm
     */
    protected function createSearchTermTransfer(
        int $idSearchRankingSaturationPointCalibrationSearchTerm,
        string $searchTerm,
    ): SearchRankingSaturationPointCalibrationSearchTermTransfer {
        return (new SearchRankingSaturationPointCalibrationSearchTermTransfer())
            ->setIdSearchRankingSaturationPointCalibrationSearchTerm($idSearchRankingSaturationPointCalibrationSearchTerm)
            ->setSearchTerm($searchTerm);
    }
}
