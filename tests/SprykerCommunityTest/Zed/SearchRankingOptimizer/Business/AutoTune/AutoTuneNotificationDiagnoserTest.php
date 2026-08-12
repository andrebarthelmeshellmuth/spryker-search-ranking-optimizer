<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\AutoTune;

use Codeception\Test\Unit;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationDiagnoser;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolverInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToAclFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group AutoTune
 * @group AutoTuneNotificationDiagnoserTest
 * @group Portable
 */
class AutoTuneNotificationDiagnoserTest extends Unit
{
    public function testReportsTheConfiguredRoleNameSoCallersNeverHardcodeIt(): void
    {
        // Arrange
        $diagnoser = $this->createDiagnoser(hasNotifyEnabledConfig: false, doesRoleExist: false, recipientEmails: []);

        // Act
        $diagnosisTransfer = $diagnoser->diagnose();

        // Assert
        $this->assertSame(SearchRankingOptimizerConfig::AUTO_TUNE_NOTIFICATION_ROLE_NAME, $diagnosisTransfer->getRoleName());
    }

    public function testReportsNotifyDisabledWhenNoMetricConfigHasItEnabled(): void
    {
        // Arrange
        $diagnoser = $this->createDiagnoser(hasNotifyEnabledConfig: false, doesRoleExist: true, recipientEmails: ['admin@example.com']);

        // Act
        $diagnosisTransfer = $diagnoser->diagnose();

        // Assert
        $this->assertFalse($diagnosisTransfer->getIsNotifyEnabledAnywhere());
    }

    public function testReportsNotifyEnabledWhenAtLeastOneMetricConfigHasItEnabled(): void
    {
        // Arrange
        $diagnoser = $this->createDiagnoser(hasNotifyEnabledConfig: true, doesRoleExist: false, recipientEmails: []);

        // Act
        $diagnosisTransfer = $diagnoser->diagnose();

        // Assert
        $this->assertTrue($diagnosisTransfer->getIsNotifyEnabledAnywhere());
    }

    /**
     * The two states a caller has to tell apart: a role that was never created, versus one that exists but
     * nobody holds. Both resolve to zero recipients, and they have different remedies.
     */
    public function testDistinguishesAMissingRoleFromAnUnstaffedOne(): void
    {
        // Arrange
        $missingRoleDiagnoser = $this->createDiagnoser(hasNotifyEnabledConfig: true, doesRoleExist: false, recipientEmails: []);
        $unstaffedRoleDiagnoser = $this->createDiagnoser(hasNotifyEnabledConfig: true, doesRoleExist: true, recipientEmails: []);

        // Act
        $missingRoleDiagnosisTransfer = $missingRoleDiagnoser->diagnose();
        $unstaffedRoleDiagnosisTransfer = $unstaffedRoleDiagnoser->diagnose();

        // Assert
        $this->assertFalse($missingRoleDiagnosisTransfer->getDoesRoleExist());
        $this->assertCount(0, $missingRoleDiagnosisTransfer->getRecipientEmails());
        $this->assertTrue($unstaffedRoleDiagnosisTransfer->getDoesRoleExist());
        $this->assertCount(0, $unstaffedRoleDiagnosisTransfer->getRecipientEmails());
    }

    /**
     * Recipients must come from the resolver the real send path uses, unchanged — a diagnosis that
     * re-derived them could report a healthy setup while the actual email still reached nobody.
     */
    public function testCarriesThroughExactlyWhatTheRecipientResolverReturns(): void
    {
        // Arrange
        $diagnoser = $this->createDiagnoser(
            hasNotifyEnabledConfig: true,
            doesRoleExist: true,
            recipientEmails: ['first@example.com', 'second@example.com'],
        );

        // Act
        $diagnosisTransfer = $diagnoser->diagnose();

        // Assert
        $this->assertSame(
            ['first@example.com', 'second@example.com'],
            $diagnosisTransfer->getRecipientEmails(),
        );
    }

    /**
     * @param bool $hasNotifyEnabledConfig
     * @param bool $doesRoleExist
     * @param array<string> $recipientEmails
     */
    protected function createDiagnoser(
        bool $hasNotifyEnabledConfig,
        bool $doesRoleExist,
        array $recipientEmails,
    ): AutoTuneNotificationDiagnoser {
        $recipientResolverMock = $this->createMock(AutoTuneNotificationRecipientResolverInterface::class);
        $recipientResolverMock->method('resolve')->willReturn($recipientEmails);

        $aclFacadeMock = $this->createMock(SearchRankingOptimizerToAclFacadeInterface::class);
        $aclFacadeMock->method('existsRoleByName')->willReturn($doesRoleExist);

        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('hasAutoTuneMetricConfigWithNotifyEnabled')->willReturn($hasNotifyEnabledConfig);

        return new AutoTuneNotificationDiagnoser($recipientResolverMock, $aclFacadeMock, $repositoryMock);
    }
}
