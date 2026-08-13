<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Communication\Console;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacade;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerOptimizeConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Mocks `SearchRankingOptimizerFacade::runNextOptimization()` (via `Console::setFacade()`) so this proves
 * the console's own three-way branching (nothing queued / failed / done) without running a real
 * optimization search. The Business-layer work this delegates to is already covered by its own dedicated
 * unit tests (`OptimizationRunnerTest`, `ParameterVectorMapperTest`).
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Communication
 * @group Console
 * @group SearchRankingOptimizerOptimizeConsoleTest
 * @group Portable
 */
class SearchRankingOptimizerOptimizeConsoleTest extends Unit
{
    public function testReportsNoOptimizationRunIsQueuedWhenNoneIsQueued(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester(null);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerOptimizeConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('No optimization run is queued.', $commandTester->getDisplay());
    }

    public function testExitsWithAnErrorAndTheMessageWhenTheRunFailed(): void
    {
        // Arrange
        $runTransfer = (new SearchRankingOptimizerRunTransfer())
            ->setIdSearchRankingOptimizerRunOrFail(9)
            ->setStatusOrFail(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_FAILED)
            ->setErrorMessageOrFail('rank_eval endpoint not supported');

        $commandTester = $this->createCommandTester($runTransfer);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerOptimizeConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('Run #9 failed: rank_eval endpoint not supported', $commandTester->getDisplay());
    }

    public function testReportsAnImprovedResultWhenTheBestScoreBeatsTheBaseline(): void
    {
        // Arrange
        $runTransfer = (new SearchRankingOptimizerRunTransfer())
            ->setIdSearchRankingOptimizerRunOrFail(4)
            ->setStatusOrFail('done')
            ->setAlgorithmOrFail('cma-es')
            ->setBaselineScoreOrFail(0.72)
            ->setBestScoreOrFail(0.81);

        $commandTester = $this->createCommandTester($runTransfer);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerOptimizeConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('Run #4 (cma-es) done: baseline nDCG = 0.7200, winning candidate nDCG = 0.8100 (improved)', $commandTester->getDisplay());
    }

    /**
     * A best score that does NOT beat the baseline must say so explicitly, not read as an improvement.
     */
    public function testReportsNoImprovementWhenTheBestScoreDoesNotBeatTheBaseline(): void
    {
        // Arrange
        $runTransfer = (new SearchRankingOptimizerRunTransfer())
            ->setIdSearchRankingOptimizerRunOrFail(5)
            ->setStatusOrFail('done')
            ->setAlgorithmOrFail('differential-evolution')
            ->setBaselineScoreOrFail(0.80)
            ->setBestScoreOrFail(0.80);

        $commandTester = $this->createCommandTester($runTransfer);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerOptimizeConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('(no improvement)', $commandTester->getDisplay());
    }

    protected function createCommandTester(?SearchRankingOptimizerRunTransfer $runTransfer): CommandTester
    {
        $facadeMock = $this->getMockBuilder(SearchRankingOptimizerFacade::class)
            ->onlyMethods(['runNextOptimization'])
            ->getMock();
        $facadeMock->method('runNextOptimization')->willReturn($runTransfer);

        $console = new SearchRankingOptimizerOptimizeConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        return new CommandTester($application->find(SearchRankingOptimizerOptimizeConsole::COMMAND_NAME));
    }
}
