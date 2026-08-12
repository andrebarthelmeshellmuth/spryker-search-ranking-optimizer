<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Communication\Acl;

use ArrayObject;
use Codeception\Test\Unit;
use Generated\Shared\Transfer\GroupsTransfer;
use Generated\Shared\Transfer\GroupTransfer;
use Generated\Shared\Transfer\RolesTransfer;
use Generated\Shared\Transfer\RoleTransfer;
use Generated\Shared\Transfer\RulesTransfer;
use Generated\Shared\Transfer\RuleTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Acl\BackOfficeAccessAnalyzer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToAclFacadeInterface;

/**
 * Note on rule `type`: Spryker stores it as a Propel `ENUM(allow,deny)`, so the DB column holds the value
 * SET INDEX (0/1) while the entity maps it back to the string on read. Everything above the persistence
 * layer — including this analyzer — therefore sees `'allow'`/`'deny'`, which is what these tests use.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Communication
 * @group Acl
 * @group BackOfficeAccessAnalyzerTest
 */
class BackOfficeAccessAnalyzerTest extends Unit
{
    /**
     * @var string
     */
    protected const MODULE_NAME = 'search-ranking-optimizer-gui';

    /**
     * The default Spryker installation: one root-style role with a total wildcard. Nothing to configure,
     * and nothing for the console to say.
     */
    public function testCountsATotalWildcardRoleAsUnrestricted(): void
    {
        // Arrange
        $analyzer = $this->createAnalyzer([
            1 => [['*', '*', '*', 'allow']],
        ]);

        // Act
        $diagnosisTransfer = $analyzer->analyze([static::MODULE_NAME]);

        // Assert
        $this->assertSame(1, $diagnosisTransfer->getUnrestrictedRoleCount());
        $this->assertSame(0, $diagnosisTransfer->getRestrictedRoleCount());
    }

    /**
     * The state the warning exists for: real restricted roles, none of which was ever pointed at this
     * package.
     */
    public function testCountsARoleWithUnrelatedRulesAsRestrictedWithoutAccess(): void
    {
        // Arrange
        $analyzer = $this->createAnalyzer([
            1 => [['*', '*', '*', 'allow']],
            2 => [['product-merchant-portal-gui', '*', '*', 'allow']],
        ]);

        // Act
        $diagnosisTransfer = $analyzer->analyze([static::MODULE_NAME]);

        // Assert
        $this->assertSame(1, $diagnosisTransfer->getUnrestrictedRoleCount());
        $this->assertSame(1, $diagnosisTransfer->getRestrictedRoleCount());
        $this->assertSame(0, $diagnosisTransfer->getRestrictedRoleWithAccessCount());
    }

    public function testCountsARoleWithARuleForThisModuleAsHavingAccess(): void
    {
        // Arrange
        $analyzer = $this->createAnalyzer([
            2 => [[static::MODULE_NAME, '*', '*', 'allow']],
        ]);

        // Act
        $diagnosisTransfer = $analyzer->analyze([static::MODULE_NAME]);

        // Assert
        $this->assertSame(1, $diagnosisTransfer->getRestrictedRoleCount());
        $this->assertSame(1, $diagnosisTransfer->getRestrictedRoleWithAccessCount());
    }

    /**
     * Granting a role the review pages while denying it the apply action is an ordinary split. That role still
     * very much reaches the module, and must not be counted as having no access.
     */
    public function testASingleActionDenyDoesNotCancelOutModuleAccess(): void
    {
        // Arrange
        $analyzer = $this->createAnalyzer([
            2 => [
                [static::MODULE_NAME, '*', '*', 'allow'],
                [static::MODULE_NAME, 'detail', 'apply', 'deny'],
            ],
        ]);

        // Act
        $diagnosisTransfer = $analyzer->analyze([static::MODULE_NAME]);

        // Assert
        $this->assertSame(1, $diagnosisTransfer->getRestrictedRoleWithAccessCount());
    }

    public function testAWholeModuleDenyCancelsOutModuleAccess(): void
    {
        // Arrange
        $analyzer = $this->createAnalyzer([
            2 => [
                [static::MODULE_NAME, '*', '*', 'allow'],
                [static::MODULE_NAME, '*', '*', 'deny'],
            ],
        ]);

        // Act
        $diagnosisTransfer = $analyzer->analyze([static::MODULE_NAME]);

        // Assert
        $this->assertSame(0, $diagnosisTransfer->getRestrictedRoleWithAccessCount());
    }

    /**
     * This demoshop's own `Pyz\Zed\Acl\AclConfig` does exactly this to keep root out of merchant-portal
     * modules: a total wildcard allow plus targeted denies. Those denies are for other modules, so the role
     * is still unrestricted as far as this package is concerned.
     */
    public function testTargetedDeniesElsewhereLeaveAWildcardRoleUnrestricted(): void
    {
        // Arrange
        $analyzer = $this->createAnalyzer([
            1 => [
                ['*', '*', '*', 'allow'],
                ['product-merchant-portal-gui', '*', '*', 'deny'],
            ],
        ]);

        // Act
        $diagnosisTransfer = $analyzer->analyze([static::MODULE_NAME]);

        // Assert
        $this->assertSame(1, $diagnosisTransfer->getUnrestrictedRoleCount());
        $this->assertSame(0, $diagnosisTransfer->getRestrictedRoleCount());
    }

    /**
     * A role attached to two groups is still one role — counting it twice would overstate how much of the
     * back office is affected.
     */
    public function testCountsARoleHeldBySeveralGroupsOnce(): void
    {
        // Arrange
        $analyzer = $this->createAnalyzer(
            rulesByRoleId: [2 => [['product-merchant-portal-gui', '*', '*', 'allow']]],
            roleIdsByGroupId: [1 => [2], 2 => [2]],
        );

        // Act
        $diagnosisTransfer = $analyzer->analyze([static::MODULE_NAME]);

        // Assert
        $this->assertSame(1, $diagnosisTransfer->getRestrictedRoleCount());
    }

    /**
     * A role no group holds grants nothing to anybody, so it must not appear in either count.
     */
    public function testIgnoresRolesNoGroupHolds(): void
    {
        // Arrange
        $analyzer = $this->createAnalyzer(
            rulesByRoleId: [2 => [['product-merchant-portal-gui', '*', '*', 'allow']]],
            roleIdsByGroupId: [],
        );

        // Act
        $diagnosisTransfer = $analyzer->analyze([static::MODULE_NAME]);

        // Assert
        $this->assertSame(0, $diagnosisTransfer->getUnrestrictedRoleCount());
        $this->assertSame(0, $diagnosisTransfer->getRestrictedRoleCount());
    }

    /**
     * @param array<int, array<array{0: string, 1: string, 2: string, 3: string}>> $rulesByRoleId
     * @param array<int, array<int>>|null $roleIdsByGroupId Defaults to one group per role.
     */
    protected function createAnalyzer(array $rulesByRoleId, ?array $roleIdsByGroupId = null): BackOfficeAccessAnalyzer
    {
        $roleIdsByGroupId ??= array_combine(
            array_keys($rulesByRoleId),
            array_map(static fn (int $idAclRole): array => [$idAclRole], array_keys($rulesByRoleId)),
        );

        $groupsTransfer = new GroupsTransfer();

        foreach (array_keys($roleIdsByGroupId) as $idAclGroup) {
            $groupsTransfer->addGroup((new GroupTransfer())->setIdAclGroup($idAclGroup));
        }

        $aclFacadeMock = $this->createMock(SearchRankingOptimizerToAclFacadeInterface::class);
        $aclFacadeMock->method('getAllGroups')->willReturn($groupsTransfer);
        $aclFacadeMock->method('getGroupRoles')->willReturnCallback(
            function (int $idAclGroup) use ($roleIdsByGroupId): RolesTransfer {
                $rolesTransfer = new RolesTransfer();

                foreach ($roleIdsByGroupId[$idAclGroup] ?? [] as $idAclRole) {
                    $rolesTransfer->addRole((new RoleTransfer())->setIdAclRole($idAclRole));
                }

                return $rolesTransfer;
            },
        );
        $aclFacadeMock->method('getRoleRules')->willReturnCallback(
            function (int $idAclRole) use ($rulesByRoleId): RulesTransfer {
                $ruleTransfers = new ArrayObject();

                foreach ($rulesByRoleId[$idAclRole] ?? [] as [$bundle, $controller, $action, $type]) {
                    $ruleTransfers->append(
                        (new RuleTransfer())
                            ->setBundle($bundle)
                            ->setController($controller)
                            ->setAction($action)
                            ->setType($type),
                    );
                }

                return (new RulesTransfer())->setRules($ruleTransfers);
            },
        );

        return new BackOfficeAccessAnalyzer($aclFacadeMock);
    }
}
