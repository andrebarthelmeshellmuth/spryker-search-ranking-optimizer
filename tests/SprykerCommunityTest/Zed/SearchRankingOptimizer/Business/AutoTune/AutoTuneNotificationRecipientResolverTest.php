<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\AutoTune;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\RoleTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolver;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToAclFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\QueryContainer\SearchRankingOptimizerToAclQueryContainerInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group AutoTune
 * @group AutoTuneNotificationRecipientResolverTest
 * Add your own group annotations below this line
 */
class AutoTuneNotificationRecipientResolverTest extends Unit
{
    /**
     * @return void
     */
    public function testReturnsEmptyArrayWhenTheNotificationRoleDoesNotExistYet(): void
    {
        // Arrange
        $aclFacadeMock = $this->createMock(SearchRankingOptimizerToAclFacadeInterface::class);
        $aclFacadeMock->method('existsRoleByName')->with(SearchRankingOptimizerConfig::AUTO_TUNE_NOTIFICATION_ROLE_NAME)->willReturn(false);
        $aclFacadeMock->expects($this->never())->method('getRoleByName');

        $aclQueryContainerMock = $this->createMock(SearchRankingOptimizerToAclQueryContainerInterface::class);
        $aclQueryContainerMock->expects($this->never())->method('findGroupIdsByRoleId');

        $resolver = new AutoTuneNotificationRecipientResolver($aclFacadeMock, $aclQueryContainerMock);

        // Act
        $result = $resolver->resolve();

        // Assert
        $this->assertSame([], $result);
    }

    /**
     * @return void
     */
    public function testResolvesEveryUsernameAcrossEveryGroupHoldingTheRoleWithoutDuplicates(): void
    {
        // Arrange
        $aclFacadeMock = $this->createMock(SearchRankingOptimizerToAclFacadeInterface::class);
        $aclFacadeMock->method('existsRoleByName')->willReturn(true);
        $aclFacadeMock->method('getRoleByName')->willReturn((new RoleTransfer())->setIdAclRole(7));

        $aclQueryContainerMock = $this->createMock(SearchRankingOptimizerToAclQueryContainerInterface::class);
        $aclQueryContainerMock->method('findGroupIdsByRoleId')->with(7)->willReturn([1, 2]);
        $aclQueryContainerMock->method('findUsernamesByGroupId')->willReturnMap([
            [1, ['alice@example.com', 'bob@example.com']],
            [2, ['bob@example.com', 'carol@example.com']],
        ]);

        $resolver = new AutoTuneNotificationRecipientResolver($aclFacadeMock, $aclQueryContainerMock);

        // Act
        $result = $resolver->resolve();

        // Assert
        sort($result);
        $this->assertSame(['alice@example.com', 'bob@example.com', 'carol@example.com'], $result);
    }
}
