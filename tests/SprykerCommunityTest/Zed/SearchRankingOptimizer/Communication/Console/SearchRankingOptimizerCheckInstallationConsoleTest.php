<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Communication\Console;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerAutoTuneConsole;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerCalibrateConsole;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerCheckInstallationConsole;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerOptimizeConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Only `checkSiblingCommandsRegistered()` is exercised under fully controlled conditions here (the test
 * builds the sibling `Application` itself) — every other check (permission plugin on Zed+Client, Yves
 * glossary key, Zed translation, the 8 Propel tables) deliberately hits this demoshop's OWN real project
 * wiring, same portability tradeoff spryker-community/search-ranking's own
 * `SearchRankingCheckInstallationConsoleTest` already accepts: this command exists specifically to
 * diagnose a REAL installation, throwaway/mocked facades would prove nothing about whether the project's
 * own DependencyProvider classes actually register everything. This demoshop's own installation is
 * expected to be fully wired (core namespace registered, permission plugin registered on both sides,
 * glossary key imported, Zed translations loaded, all 8 Propel tables present) — asserted on accordingly.
 *
 * This console has no mockable Facade dependency at all (every check reaches a DIFFERENT module's facade
 * via `getFactory()`, never `getFacade()`), unlike its search-ranking sibling.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Communication
 * @group Console
 * @group SearchRankingOptimizerCheckInstallationConsoleTest
 */
class SearchRankingOptimizerCheckInstallationConsoleTest extends Unit
{
    public function testSucceedsAndReportsEveryCheckWhenSiblingCommandsAreRegistered(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester(registerAllSiblingCommands: true);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerCheckInstallationConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('core namespace "SprykerCommunity" is registered', $commandTester->getDisplay());
        $this->assertStringContainsString('all 3 sibling console commands are registered', $commandTester->getDisplay());
        $this->assertStringContainsString('the SRP-rating permission plugin is registered on both Zed and Client', $commandTester->getDisplay());
        $this->assertStringContainsString('the Yves glossary key is imported', $commandTester->getDisplay());
        $this->assertStringContainsString('the Zed GUI translation catalog is loaded', $commandTester->getDisplay());
        $this->assertStringContainsString('all 8 Propel tables exist and are queryable', $commandTester->getDisplay());
        $this->assertStringContainsString('Everything checkable from the CLI is in place.', $commandTester->getDisplay());
    }

    /**
     * A missing sibling command is a FAILURE (not a warning) — it means README step 3 was never
     * completed, which this command must not report as a clean bill of health.
     */
    public function testFailsAndNamesTheMissingCommandWhenASiblingCommandIsNotRegistered(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester(registerAllSiblingCommands: false);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerCheckInstallationConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('search-ranking-optimizer:optimize', $commandTester->getDisplay());
        $this->assertStringContainsString('NOT registered', $commandTester->getDisplay());
    }

    /**
     * @param bool $registerAllSiblingCommands
     */
    protected function createCommandTester(bool $registerAllSiblingCommands): CommandTester
    {
        $console = new SearchRankingOptimizerCheckInstallationConsole();

        $application = new Application();
        $application->add($console);
        $application->add(new SearchRankingOptimizerCalibrateConsole());
        $application->add(new SearchRankingOptimizerAutoTuneConsole());

        if ($registerAllSiblingCommands) {
            $application->add(new SearchRankingOptimizerOptimizeConsole());
        }

        $command = $application->find(SearchRankingOptimizerCheckInstallationConsole::COMMAND_NAME);

        return new CommandTester($command);
    }
}
