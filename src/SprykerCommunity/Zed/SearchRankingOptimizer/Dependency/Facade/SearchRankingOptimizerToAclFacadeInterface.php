<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade;

use Generated\Shared\Transfer\GroupsTransfer;
use Generated\Shared\Transfer\RolesTransfer;
use Generated\Shared\Transfer\RoleTransfer;
use Generated\Shared\Transfer\RulesTransfer;

interface SearchRankingOptimizerToAclFacadeInterface
{
    /**
     * @param string $name
     */
    public function existsRoleByName(string $name): bool;

    /**
     * Only safe to call after {@see existsRoleByName()} confirms the role exists.
     *
     * @param string $name
     */
    public function getRoleByName(string $name): RoleTransfer;

    /**
     * Read-only, and used ONLY by `search-ranking-optimizer:check-installation` to work out whether this
     * package's own Zed pages are reachable by anybody other than a root-style admin. Nothing on the
     * request path consults it — Zed access control is Spryker's own Acl module's job, exactly as it is
     * for every other Zed module.
     */
    public function getAllGroups(): GroupsTransfer;

    /**
     * @param int $idGroup
     */
    public function getGroupRoles(int $idGroup): RolesTransfer;

    /**
     * @param int $idRole
     */
    public function getRoleRules(int $idRole): RulesTransfer;
}
