<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Authorization;

use Generated\Shared\Transfer\CustomerTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToCompanyUserFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToPermissionFacadeInterface;

class RelevanceJudgmentAuthorizer implements RelevanceJudgmentAuthorizerInterface
{
    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToCompanyUserFacadeInterface
     */
    protected SearchRankingOptimizerToCompanyUserFacadeInterface $companyUserFacade;

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToPermissionFacadeInterface
     */
    protected SearchRankingOptimizerToPermissionFacadeInterface $permissionFacade;

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToCompanyUserFacadeInterface $companyUserFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToPermissionFacadeInterface $permissionFacade
     */
    public function __construct(
        SearchRankingOptimizerToCompanyUserFacadeInterface $companyUserFacade,
        SearchRankingOptimizerToPermissionFacadeInterface $permissionFacade,
    ) {
        $this->companyUserFacade = $companyUserFacade;
        $this->permissionFacade = $permissionFacade;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $customerReference
     * @param string $permissionKey
     *
     * @return bool
     */
    public function isAuthorized(string $customerReference, string $permissionKey): bool
    {
        $customerTransfer = (new CustomerTransfer())->setCustomerReference($customerReference);
        $companyUserCollectionTransfer = $this->companyUserFacade->getActiveCompanyUsersByCustomerReference($customerTransfer);

        foreach ($companyUserCollectionTransfer->getCompanyUsers() as $companyUserTransfer) {
            if ($this->permissionFacade->can($permissionKey, $companyUserTransfer->getIdCompanyUserOrFail())) {
                return true;
            }
        }

        return false;
    }
}
