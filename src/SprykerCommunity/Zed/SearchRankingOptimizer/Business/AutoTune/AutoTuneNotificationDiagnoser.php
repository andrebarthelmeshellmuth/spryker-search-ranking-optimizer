<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune;

use Generated\Shared\Transfer\SearchRankingAutoTuneNotificationDiagnosisTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToAclFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

class AutoTuneNotificationDiagnoser implements AutoTuneNotificationDiagnoserInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolverInterface $recipientResolver
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToAclFacadeInterface $aclFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     */
    public function __construct(
        protected AutoTuneNotificationRecipientResolverInterface $recipientResolver,
        protected SearchRankingOptimizerToAclFacadeInterface $aclFacade,
        protected SearchRankingOptimizerRepositoryInterface $repository,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function diagnose(): SearchRankingAutoTuneNotificationDiagnosisTransfer
    {
        $roleName = SearchRankingOptimizerConfig::AUTO_TUNE_NOTIFICATION_ROLE_NAME;

        $diagnosisTransfer = (new SearchRankingAutoTuneNotificationDiagnosisTransfer())
            ->setRoleName($roleName)
            ->setIsNotifyEnabledAnywhere($this->repository->hasAutoTuneMetricConfigWithNotifyEnabled())
            ->setDoesRoleExist($this->aclFacade->existsRoleByName($roleName));

        foreach ($this->recipientResolver->resolve() as $email) {
            $diagnosisTransfer->addRecipientEmail($email);
        }

        return $diagnosisTransfer;
    }
}
