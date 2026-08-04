<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Communication\Authorization;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\CompanyUserCollectionTransfer;
use Generated\Shared\Transfer\CompanyUserTransfer;
use Generated\Shared\Transfer\CustomerTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Authorization\CompanyUserPermissionAuthorizer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToCompanyUserFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToPermissionFacadeInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Communication
 * @group Authorization
 * @group CompanyUserPermissionAuthorizerTest
 * Add your own group annotations below this line
 */
class CompanyUserPermissionAuthorizerTest extends Unit
{
    public function testIsAuthorizedNeverTrustsAnIdentifierFromTheRequestItselfAndAlwaysResolvesViaCompanyUserFacade(): void
    {
        // Arrange
        $companyUserFacadeMock = $this->createMock(SearchRankingOptimizerToCompanyUserFacadeInterface::class);
        $companyUserFacadeMock->expects($this->once())
            ->method('getActiveCompanyUsersByCustomerReference')
            ->with($this->callback(fn (CustomerTransfer $customerTransfer): bool => $customerTransfer->getCustomerReference() === 'CUST-1'))
            ->willReturn(
                (new CompanyUserCollectionTransfer())->addCompanyUser((new CompanyUserTransfer())->setIdCompanyUser(42)),
            );

        $permissionFacadeMock = $this->createMock(SearchRankingOptimizerToPermissionFacadeInterface::class);
        $permissionFacadeMock->expects($this->once())->method('can')->with('SomePermission', 42)->willReturn(true);

        $authorizer = new CompanyUserPermissionAuthorizer($companyUserFacadeMock, $permissionFacadeMock);

        // Act
        $result = $authorizer->isAuthorized('CUST-1', 'SomePermission');

        // Assert
        $this->assertTrue($result);
    }

    public function testIsAuthorizedGrantsAccessWhenAnyOfMultipleActiveCompanyUsersHoldsThePermission(): void
    {
        // Arrange
        $companyUserFacadeMock = $this->createMock(SearchRankingOptimizerToCompanyUserFacadeInterface::class);
        $companyUserFacadeMock->method('getActiveCompanyUsersByCustomerReference')->willReturn(
            (new CompanyUserCollectionTransfer())
                ->addCompanyUser((new CompanyUserTransfer())->setIdCompanyUser(1))
                ->addCompanyUser((new CompanyUserTransfer())->setIdCompanyUser(2)),
        );

        $permissionFacadeMock = $this->createMock(SearchRankingOptimizerToPermissionFacadeInterface::class);
        $permissionFacadeMock->method('can')->willReturnMap([
            ['SomePermission', 1, false],
            ['SomePermission', 2, true],
        ]);

        $authorizer = new CompanyUserPermissionAuthorizer($companyUserFacadeMock, $permissionFacadeMock);

        // Act
        $result = $authorizer->isAuthorized('CUST-1', 'SomePermission');

        // Assert
        $this->assertTrue($result);
    }

    public function testIsAuthorizedReturnsFalseWhenTheCustomerHasNoActiveCompanyUserAtAll(): void
    {
        // Arrange
        $companyUserFacadeMock = $this->createMock(SearchRankingOptimizerToCompanyUserFacadeInterface::class);
        $companyUserFacadeMock->method('getActiveCompanyUsersByCustomerReference')->willReturn(new CompanyUserCollectionTransfer());

        $permissionFacadeMock = $this->createMock(SearchRankingOptimizerToPermissionFacadeInterface::class);
        $permissionFacadeMock->expects($this->never())->method('can');

        $authorizer = new CompanyUserPermissionAuthorizer($companyUserFacadeMock, $permissionFacadeMock);

        // Act
        $result = $authorizer->isAuthorized('CUST-1', 'SomePermission');

        // Assert
        $this->assertFalse($result);
    }

    public function testIsAuthorizedReturnsFalseWhenNoActiveCompanyUserHoldsThePermission(): void
    {
        // Arrange
        $companyUserFacadeMock = $this->createMock(SearchRankingOptimizerToCompanyUserFacadeInterface::class);
        $companyUserFacadeMock->method('getActiveCompanyUsersByCustomerReference')->willReturn(
            (new CompanyUserCollectionTransfer())->addCompanyUser((new CompanyUserTransfer())->setIdCompanyUser(1)),
        );

        $permissionFacadeMock = $this->createMock(SearchRankingOptimizerToPermissionFacadeInterface::class);
        $permissionFacadeMock->method('can')->willReturn(false);

        $authorizer = new CompanyUserPermissionAuthorizer($companyUserFacadeMock, $permissionFacadeMock);

        // Act
        $result = $authorizer->isAuthorized('CUST-1', 'SomePermission');

        // Assert
        $this->assertFalse($result);
    }
}
