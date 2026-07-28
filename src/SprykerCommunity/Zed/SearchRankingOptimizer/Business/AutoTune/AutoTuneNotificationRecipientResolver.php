<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune;

use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToAclFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\QueryContainer\SearchRankingOptimizerToAclQueryContainerInterface;

class AutoTuneNotificationRecipientResolver implements AutoTuneNotificationRecipientResolverInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToAclFacadeInterface $aclFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\QueryContainer\SearchRankingOptimizerToAclQueryContainerInterface $aclQueryContainer
     */
    public function __construct(
        protected SearchRankingOptimizerToAclFacadeInterface $aclFacade,
        protected SearchRankingOptimizerToAclQueryContainerInterface $aclQueryContainer,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string>
     */
    public function resolve(): array
    {
        if (!$this->aclFacade->existsRoleByName(SearchRankingOptimizerConfig::AUTO_TUNE_NOTIFICATION_ROLE_NAME)) {
            return [];
        }

        $roleTransfer = $this->aclFacade->getRoleByName(SearchRankingOptimizerConfig::AUTO_TUNE_NOTIFICATION_ROLE_NAME);
        $idAclRole = $roleTransfer->getIdAclRoleOrFail();

        $emails = [];

        foreach ($this->aclQueryContainer->findGroupIdsByRoleId($idAclRole) as $idAclGroup) {
            foreach ($this->aclQueryContainer->findUsernamesByGroupId($idAclGroup) as $username) {
                $emails[$username] = $username;
            }
        }

        return array_values($emails);
    }
}
