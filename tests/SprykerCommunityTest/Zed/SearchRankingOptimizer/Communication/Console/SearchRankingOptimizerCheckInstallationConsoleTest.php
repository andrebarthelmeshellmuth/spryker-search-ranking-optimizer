<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Communication\Console;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingAutoTuneNotificationDiagnosisTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacade;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerAutoTuneConsole;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerCalibrateConsole;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerCheckInstallationConsole;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerOptimizeConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `checkSiblingCommandsRegistered()` and `checkAutoTuneNotificationRoleStaffed()` are exercised under
 * fully controlled conditions here (the test builds the sibling `Application` itself, and injects the
 * notification diagnosis via a facade mock) — every other check (permission plugin on Zed+Client, Yves
 * glossary key, Zed translation, the 8 Propel tables) deliberately hits this demoshop's OWN real project
 * wiring, same portability tradeoff spryker-community/search-ranking's own
 * `SearchRankingCheckInstallationConsoleTest` already accepts: this command exists specifically to
 * diagnose a REAL installation, throwaway/mocked facades would prove nothing about whether the project's
 * own DependencyProvider classes actually register everything. This demoshop's own installation is
 * expected to be fully wired (core namespace registered, permission plugin registered on both sides,
 * glossary key imported, Zed translations loaded, all 8 Propel tables present) — asserted on accordingly.
 *
 * The notification-role check is the one exception, and is mocked rather than left to the demoshop's real
 * ACL tables for a reason: all four of its outcomes matter, and three of them (role missing, role
 * unstaffed, notifications not enabled anywhere) cannot be produced by a real installation that is set up
 * correctly. Leaving it unmocked would assert only whichever single state this demoshop happens to be in.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Communication
 * @group Console
 * @group SearchRankingOptimizerCheckInstallationConsoleTest
 * @group NeedsProject
 */
class SearchRankingOptimizerCheckInstallationConsoleTest extends Unit
{
    public function testSucceedsAndReportsEveryCheckWhenSiblingCommandsAreRegistered(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester(
            registerAllSiblingCommands: true,
            diagnosisTransfer: $this->createDiagnosis(isNotifyEnabledAnywhere: true, doesRoleExist: true, recipientEmails: ['admin@example.com']),
        );

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
        $commandTester = $this->createCommandTester(
            registerAllSiblingCommands: false,
            diagnosisTransfer: $this->createDiagnosis(isNotifyEnabledAnywhere: true, doesRoleExist: true, recipientEmails: ['admin@example.com']),
        );

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerCheckInstallationConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('search-ranking-optimizer:optimize', $commandTester->getDisplay());
        $this->assertStringContainsString('NOT registered', $commandTester->getDisplay());
    }

    public function testReportsResolvedRecipientCountWhenNotificationRoleIsStaffed(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester(
            registerAllSiblingCommands: true,
            diagnosisTransfer: $this->createDiagnosis(
                isNotifyEnabledAnywhere: true,
                doesRoleExist: true,
                recipientEmails: ['first@example.com', 'second@example.com'],
            ),
        );

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerCheckInstallationConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString(
            sprintf('the auto-tune summary email resolves to 2 recipient(s) via the "%s" ACL role', SearchRankingOptimizerConfig::AUTO_TUNE_NOTIFICATION_ROLE_NAME),
            $commandTester->getDisplay(),
        );
    }

    /**
     * The role is only needed once some metric actually asks for an email, so a shop that never enabled
     * notifications must not be told anything is wrong — this is the majority case, and warning here
     * would train people to ignore the warnings that do matter.
     */
    public function testStaysQuietWhenNoMetricHasNotificationEnabled(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester(
            registerAllSiblingCommands: true,
            diagnosisTransfer: $this->createDiagnosis(isNotifyEnabledAnywhere: false, doesRoleExist: false, recipientEmails: []),
        );

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerCheckInstallationConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('is not needed yet', $commandTester->getDisplay());
        $this->assertStringNotContainsString('sent to nobody', $commandTester->getDisplay());
    }

    /**
     * A WARNING, never a failure: the run itself still works, only the email goes nowhere. The exit code
     * staying CODE_SUCCESS is the point of the assertion, not incidental to it.
     */
    public function testWarnsWithoutFailingWhenNotificationRoleDoesNotExist(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester(
            registerAllSiblingCommands: true,
            diagnosisTransfer: $this->createDiagnosis(isNotifyEnabledAnywhere: true, doesRoleExist: false, recipientEmails: []),
        );

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerCheckInstallationConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString(
            sprintf('the ACL role "%s" does not exist', SearchRankingOptimizerConfig::AUTO_TUNE_NOTIFICATION_ROLE_NAME),
            $commandTester->getDisplay(),
        );
        $this->assertStringContainsString('sent to nobody', $commandTester->getDisplay());
    }

    /**
     * Distinct from the missing-role case above, and worth its own message: the remedy is to staff the
     * role, not to create it, and someone who just created it is exactly who lands here.
     */
    public function testWarnsWithoutFailingWhenNotificationRoleExistsButNobodyHoldsIt(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester(
            registerAllSiblingCommands: true,
            diagnosisTransfer: $this->createDiagnosis(isNotifyEnabledAnywhere: true, doesRoleExist: true, recipientEmails: []),
        );

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerCheckInstallationConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('exists, but nobody holds it', $commandTester->getDisplay());
        $this->assertStringContainsString('sent to nobody', $commandTester->getDisplay());
    }

    /**
     * @param bool $isNotifyEnabledAnywhere
     * @param bool $doesRoleExist
     * @param array<string> $recipientEmails
     */
    protected function createDiagnosis(
        bool $isNotifyEnabledAnywhere,
        bool $doesRoleExist,
        array $recipientEmails,
    ): SearchRankingAutoTuneNotificationDiagnosisTransfer {
        $diagnosisTransfer = (new SearchRankingAutoTuneNotificationDiagnosisTransfer())
            ->setRoleName(SearchRankingOptimizerConfig::AUTO_TUNE_NOTIFICATION_ROLE_NAME)
            ->setIsNotifyEnabledAnywhere($isNotifyEnabledAnywhere)
            ->setDoesRoleExist($doesRoleExist);

        foreach ($recipientEmails as $recipientEmail) {
            $diagnosisTransfer->addRecipientEmail($recipientEmail);
        }

        return $diagnosisTransfer;
    }

    /**
     * @param bool $registerAllSiblingCommands
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneNotificationDiagnosisTransfer $diagnosisTransfer
     */
    protected function createCommandTester(
        bool $registerAllSiblingCommands,
        SearchRankingAutoTuneNotificationDiagnosisTransfer $diagnosisTransfer,
    ): CommandTester {
        $facadeMock = $this->getMockBuilder(SearchRankingOptimizerFacade::class)
            ->onlyMethods(['getAutoTuneNotificationDiagnosis'])
            ->getMock();
        $facadeMock->method('getAutoTuneNotificationDiagnosis')->willReturn($diagnosisTransfer);

        $console = new SearchRankingOptimizerCheckInstallationConsole();
        $console->setFacade($facadeMock);

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
