<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\QueryContainer;

interface SearchRankingOptimizerToAclQueryContainerInterface
{
    /**
     * @param int $idRole
     *
     * @return array<int> IDs of every ACL group holding $idRole.
     */
    public function findGroupIdsByRoleId(int $idRole): array;

    /**
     * @param int $idGroup
     *
     * @return array<string> Usernames (this shop's login/email field) of every user in $idGroup.
     */
    public function findUsernamesByGroupId(int $idGroup): array;
}
