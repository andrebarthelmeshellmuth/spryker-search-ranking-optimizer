<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\QueryContainer;

class SearchRankingOptimizerToAclQueryContainerBridge implements SearchRankingOptimizerToAclQueryContainerInterface
{
    /**
     * @var \Spryker\Zed\Acl\Persistence\AclQueryContainerInterface
     */
    protected $aclQueryContainer;

    /**
     * @param \Spryker\Zed\Acl\Persistence\AclQueryContainerInterface $aclQueryContainer
     */
    public function __construct($aclQueryContainer)
    {
        $this->aclQueryContainer = $aclQueryContainer;
    }

    /**
     * @param int $idRole
     *
     * @return array<int>
     */
    public function findGroupIdsByRoleId(int $idRole): array
    {
        $groupIds = [];

        foreach ($this->aclQueryContainer->queryRoleHasGroup($idRole)->find() as $groupHasRoleEntity) {
            $groupIds[] = $groupHasRoleEntity->getFkAclGroup();
        }

        return $groupIds;
    }

    /**
     * @param int $idGroup
     *
     * @return array<string>
     */
    public function findUsernamesByGroupId(int $idGroup): array
    {
        $usernames = [];

        foreach ($this->aclQueryContainer->queryGroupUsers($idGroup)->find() as $userEntity) {
            $usernames[] = $userEntity->getUsername();
        }

        return $usernames;
    }
}
