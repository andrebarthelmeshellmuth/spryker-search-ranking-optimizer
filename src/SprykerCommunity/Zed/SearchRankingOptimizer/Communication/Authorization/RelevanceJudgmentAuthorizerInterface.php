<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Authorization;

interface RelevanceJudgmentAuthorizerInterface
{
    /**
     * Independently re-resolves `$customerReference` to its active company user(s) via
     * `Spryker\Zed\CompanyUser\Business\CompanyUserFacadeInterface` (never trusts an identifier passed in
     * a request transfer), then checks `$permissionKey` for each via
     * `Spryker\Zed\Permission\Business\PermissionFacadeInterface::can()`. Grants access if ANY of the
     * customer's active company users holds the permission.
     *
     * @param string $customerReference
     * @param string $permissionKey
     *
     * @return bool
     */
    public function isAuthorized(string $customerReference, string $permissionKey): bool;
}
