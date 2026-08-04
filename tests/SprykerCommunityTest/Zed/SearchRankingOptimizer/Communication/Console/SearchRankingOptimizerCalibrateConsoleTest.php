<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Communication\Console;

use ArrayObject;
use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingCalibrationSearchTermTransfer;
use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacade;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerCalibrateConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Mocks `SearchRankingOptimizerFacade::runNextCalibration()` (via `Console::setFacade()`) so this proves
 * the console's own three-way branching (nothing queued / failed / done) without running a real
 * calibration pass. The Business-layer work this delegates to is already covered by its own dedicated
 * unit tests (`ScoreCalibratorTest`, `StatisticsCalculatorTest`).
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Communication
 * @group Console
 * @group SearchRankingOptimizerCalibrateConsoleTest
 */
class SearchRankingOptimizerCalibrateConsoleTest extends Unit
{
    public function testReportsNoUploadedCalibrationRunWhenNoneIsQueued(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester(null);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerCalibrateConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('No uploaded calibration run found.', $commandTester->getDisplay());
    }

    public function testExitsWithAnErrorAndTheMessageWhenTheCalibrationFailed(): void
    {
        // Arrange
        $calibrationTransfer = (new SearchRankingCalibrationTransfer())
            ->setIdSearchRankingCalibrationOrFail(3)
            ->setStatusOrFail(SearchRankingOptimizerConfig::CALIBRATION_STATUS_FAILED)
            ->setErrorMessageOrFail('search engine unreachable');

        $commandTester = $this->createCommandTester($calibrationTransfer);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerCalibrateConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('search engine unreachable', $commandTester->getDisplay());
    }

    public function testReportsTheComputedKAndSampleCountsWhenCalibrationSucceeds(): void
    {
        // Arrange
        $calibrationTransfer = (new SearchRankingCalibrationTransfer())
            ->setIdSearchRankingCalibrationOrFail(7)
            ->setStatusOrFail('done')
            ->setSampleCountOrFail(150)
            ->setSearchTerms(new ArrayObject([
                (new SearchRankingCalibrationSearchTermTransfer())->setSearchTermOrFail('chair'),
                (new SearchRankingCalibrationSearchTermTransfer())->setSearchTermOrFail('office chair'),
                (new SearchRankingCalibrationSearchTermTransfer())->setSearchTermOrFail('topstar'),
            ]))
            ->setComputedKOrFail(4.2318);

        $commandTester = $this->createCommandTester($calibrationTransfer);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerCalibrateConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('Calibration #7 done: sampled 150 value(s) across 3 search term(s), computed k = 4.2318.', $commandTester->getDisplay());
    }

    protected function createCommandTester(?SearchRankingCalibrationTransfer $calibrationTransfer): CommandTester
    {
        $facadeMock = $this->getMockBuilder(SearchRankingOptimizerFacade::class)
            ->onlyMethods(['runNextCalibration'])
            ->getMock();
        $facadeMock->method('runNextCalibration')->willReturn($calibrationTransfer);

        $console = new SearchRankingOptimizerCalibrateConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        return new CommandTester($application->find(SearchRankingOptimizerCalibrateConsole::COMMAND_NAME));
    }
}
