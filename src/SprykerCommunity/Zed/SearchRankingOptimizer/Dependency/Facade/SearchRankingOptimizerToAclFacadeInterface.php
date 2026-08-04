<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade;

use Generated\Shared\Transfer\RoleTransfer;

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
}
