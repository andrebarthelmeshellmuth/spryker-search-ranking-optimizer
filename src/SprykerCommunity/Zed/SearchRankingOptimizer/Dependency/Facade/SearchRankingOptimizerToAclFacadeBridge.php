<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade;

use Generated\Shared\Transfer\RoleTransfer;

class SearchRankingOptimizerToAclFacadeBridge implements SearchRankingOptimizerToAclFacadeInterface
{
    /**
     * @var \Spryker\Zed\Acl\Business\AclFacadeInterface
     */
    protected $aclFacade;

    /**
     * @param \Spryker\Zed\Acl\Business\AclFacadeInterface $aclFacade
     */
    public function __construct($aclFacade)
    {
        $this->aclFacade = $aclFacade;
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function existsRoleByName(string $name): bool
    {
        return $this->aclFacade->existsRoleByName($name);
    }

    /**
     * @param string $name
     *
     * @return \Generated\Shared\Transfer\RoleTransfer
     */
    public function getRoleByName(string $name): RoleTransfer
    {
        return $this->aclFacade->getRoleByName($name);
    }
}
