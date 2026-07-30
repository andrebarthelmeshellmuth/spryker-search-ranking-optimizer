<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Calibration;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CalibrationUploadHandler;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CsvSearchTermParserInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Calibration
 * @group CalibrationUploadHandlerTest
 * Add your own group annotations below this line
 */
class CalibrationUploadHandlerTest extends Unit
{
    /**
     * @return void
     */
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
            ->with($this->callback(function (SearchRankingCalibrationTransfer $calibrationTransfer): bool {
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

        $handler = new CalibrationUploadHandler($csvSearchTermParserMock, $repositoryMock, $entityManagerMock);

        // Act
        $handler->createCalibration(6, 'DE', 'en_US', 'chair,desk');
    }

    /**
     * @return void
     */
    public function testCreateCalibrationPersistsAnEmptySearchTermListWhenParsingYieldsNoTerms(): void
    {
        // Arrange
        $csvSearchTermParserMock = $this->createMock(CsvSearchTermParserInterface::class);
        $csvSearchTermParserMock->method('parse')->willReturn([]);

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())
            ->method('createCalibration')
            ->with($this->callback(fn (SearchRankingCalibrationTransfer $calibrationTransfer): bool => iterator_to_array($calibrationTransfer->getSearchTerms()) === []))
            ->willReturnArgument(0);

        $handler = new CalibrationUploadHandler($csvSearchTermParserMock, $repositoryMock, $entityManagerMock);

        // Act
        $handler->createCalibration(6, 'DE', 'en_US', '');
    }

    /**
     * @return void
     */
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
            ->with($this->callback(function (SearchRankingCalibrationTransfer $calibrationTransfer): bool {
                $searchTerms = array_map(
                    fn ($searchTermTransfer) => $searchTermTransfer->getSearchTerm(),
                    iterator_to_array($calibrationTransfer->getSearchTerms()),
                );

                return $searchTerms === ['chair', 'desk'];
            }))
            ->willReturnArgument(0);

        $handler = new CalibrationUploadHandler($csvSearchTermParserMock, $repositoryMock, $entityManagerMock);

        // Act
        $handler->createCalibration(6, 'DE', 'en_US');
    }

    /**
     * @return void
     */
    public function testCreateCalibrationReturnsWhateverTheEntityManagerHandsBack(): void
    {
        // Arrange
        $csvSearchTermParserMock = $this->createMock(CsvSearchTermParserInterface::class);
        $csvSearchTermParserMock->method('parse')->willReturn([]);

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);

        $persistedTransfer = (new SearchRankingCalibrationTransfer())->setIdSearchRankingCalibration(42);
        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->method('createCalibration')->willReturn($persistedTransfer);

        $handler = new CalibrationUploadHandler($csvSearchTermParserMock, $repositoryMock, $entityManagerMock);

        // Act
        $resultTransfer = $handler->createCalibration(6, 'DE', 'en_US', '');

        // Assert
        $this->assertSame(42, $resultTransfer->getIdSearchRankingCalibration());
    }
}
