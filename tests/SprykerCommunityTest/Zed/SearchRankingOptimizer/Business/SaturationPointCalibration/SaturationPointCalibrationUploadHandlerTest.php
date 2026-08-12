<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\NoSearchTermsAvailableException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\CsvSearchTermParserInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\SaturationPointCalibrationUploadHandler;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group SaturationPointCalibration
 * @group SaturationPointCalibrationUploadHandlerTest
 * Add your own group annotations below this line
 * @group Portable
 */
class SaturationPointCalibrationUploadHandlerTest extends Unit
{
    public function testCreateCalibrationBuildsOneSearchTermTransferPerParsedTermInOrderWhenCsvContentIsGiven(): void
    {
        // Arrange
        $csvSearchTermParserMock = $this->createMock(CsvSearchTermParserInterface::class);
        $csvSearchTermParserMock->method('parse')->with('chair,desk')->willReturn(['chair', 'desk']);

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->expects($this->never())->method('findDistinctSearchTermsByStoreLocale');

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())
            ->method('createCalibration')
            ->with($this->callback(function (SearchRankingSaturationPointCalibrationTransfer $calibrationTransfer): bool {
                $searchTerms = array_map(
                    fn ($searchTermTransfer) => $searchTermTransfer->getSearchTerm(),
                    iterator_to_array($calibrationTransfer->getSearchTerms()),
                );

                return $calibrationTransfer->getRelevantProductCount() === 6
                    && $calibrationTransfer->getStoreName() === 'DE'
                    && $calibrationTransfer->getLocaleName() === 'en_US'
                    && $calibrationTransfer->getStatus() === SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED
                    && $searchTerms === ['chair', 'desk'];
            }))
            ->willReturnArgument(0);

        $handler = new SaturationPointCalibrationUploadHandler($csvSearchTermParserMock, $repositoryMock, $entityManagerMock);

        // Act
        $handler->createCalibration(SearchRankingOptimizerConfig::CALIBRATION_TYPE_RELEVANCE_SCORE, 6, 'DE', 'en_US', 'chair,desk');
    }

    public function testCreateCalibrationRejectsTheUploadWhenParsingYieldsNoTerms(): void
    {
        // Arrange -- a calibration with zero attached terms can only fail later on the calibrate cron
        // tick, so it is rejected up front rather than persisted.
        $csvSearchTermParserMock = $this->createMock(CsvSearchTermParserInterface::class);
        $csvSearchTermParserMock->method('parse')->willReturn([]);

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('createCalibration');

        $handler = new SaturationPointCalibrationUploadHandler($csvSearchTermParserMock, $repositoryMock, $entityManagerMock);

        // Assert
        $this->expectException(NoSearchTermsAvailableException::class);

        // Act
        $handler->createCalibration(SearchRankingOptimizerConfig::CALIBRATION_TYPE_RELEVANCE_SCORE, 6, 'DE', 'en_US', '');
    }

    public function testCreateCalibrationRejectsTheUploadWhenTheRepositoryHasNoRatedTermsYet(): void
    {
        // Arrange
        $csvSearchTermParserMock = $this->createMock(CsvSearchTermParserInterface::class);
        $csvSearchTermParserMock->expects($this->never())->method('parse');

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findDistinctSearchTermsByStoreLocale')->with('DE', 'en_US')->willReturn([]);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->never())->method('createCalibration');

        $handler = new SaturationPointCalibrationUploadHandler($csvSearchTermParserMock, $repositoryMock, $entityManagerMock);

        // Assert
        $this->expectException(NoSearchTermsAvailableException::class);

        // Act
        $handler->createCalibration(SearchRankingOptimizerConfig::CALIBRATION_TYPE_RELEVANCE_SCORE, 6, 'DE', 'en_US');
    }

    public function testCreateCalibrationSourcesTermsFromTheRepositoryWhenNoCsvContentIsGiven(): void
    {
        // Arrange
        $csvSearchTermParserMock = $this->createMock(CsvSearchTermParserInterface::class);
        $csvSearchTermParserMock->expects($this->never())->method('parse');

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->expects($this->once())
            ->method('findDistinctSearchTermsByStoreLocale')
            ->with('DE', 'en_US')
            ->willReturn(['chair', 'desk']);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())
            ->method('createCalibration')
            ->with($this->callback(function (SearchRankingSaturationPointCalibrationTransfer $calibrationTransfer): bool {
                $searchTerms = array_map(
                    fn ($searchTermTransfer) => $searchTermTransfer->getSearchTerm(),
                    iterator_to_array($calibrationTransfer->getSearchTerms()),
                );

                return $searchTerms === ['chair', 'desk'];
            }))
            ->willReturnArgument(0);

        $handler = new SaturationPointCalibrationUploadHandler($csvSearchTermParserMock, $repositoryMock, $entityManagerMock);

        // Act
        $handler->createCalibration(SearchRankingOptimizerConfig::CALIBRATION_TYPE_RELEVANCE_SCORE, 6, 'DE', 'en_US');
    }

    public function testCreateCalibrationReturnsWhateverTheEntityManagerHandsBack(): void
    {
        // Arrange
        $csvSearchTermParserMock = $this->createMock(CsvSearchTermParserInterface::class);
        $csvSearchTermParserMock->method('parse')->willReturn(['chair']);

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);

        $persistedTransfer = (new SearchRankingSaturationPointCalibrationTransfer())->setIdSearchRankingSaturationPointCalibration(42);
        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->method('createCalibration')->willReturn($persistedTransfer);

        $handler = new SaturationPointCalibrationUploadHandler($csvSearchTermParserMock, $repositoryMock, $entityManagerMock);

        // Act
        $resultTransfer = $handler->createCalibration(SearchRankingOptimizerConfig::CALIBRATION_TYPE_RELEVANCE_SCORE, 6, 'DE', 'en_US', '');

        // Assert
        $this->assertSame(42, $resultTransfer->getIdSearchRankingSaturationPointCalibration());
    }
}
