<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Acl;

use Generated\Shared\Transfer\RuleTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerBackOfficeAccessDiagnosisTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToAclFacadeInterface;

class BackOfficeAccessAnalyzer implements BackOfficeAccessAnalyzerInterface
{
    /**
     * Spryker's own `AclConstants::VALIDATOR_WILDCARD`, inlined rather than imported so this package stays
     * loadable without `spryker/acl` present — the whole ACL dependency here exists for one diagnostic.
     *
     * @var string
     */
    protected const WILDCARD = '*';

    /**
     * @var string
     */
    protected const RULE_TYPE_ALLOW = 'allow';

    /**
     * @var string
     */
    protected const RULE_TYPE_DENY = 'deny';

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToAclFacadeInterface $aclFacade
     */
    public function __construct(protected SearchRankingOptimizerToAclFacadeInterface $aclFacade)
    {
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string> $moduleNames
     */
    public function analyze(array $moduleNames): SearchRankingOptimizerBackOfficeAccessDiagnosisTransfer
    {
        $diagnosisTransfer = new SearchRankingOptimizerBackOfficeAccessDiagnosisTransfer();

        foreach ($moduleNames as $moduleName) {
            $diagnosisTransfer->addModuleName($moduleName);
        }

        $unrestrictedRoleCount = 0;
        $restrictedRoleCount = 0;
        $restrictedRoleWithAccessCount = 0;

        foreach ($this->findGroupedRoleIds() as $idAclRole) {
            $ruleTransfers = $this->aclFacade->getRoleRules($idAclRole)->getRules()->getArrayCopy();

            if ($this->hasUnrestrictedAccess($ruleTransfers)) {
                $unrestrictedRoleCount++;

                continue;
            }

            $restrictedRoleCount++;

            if (!$this->hasAccessToAnyModule($ruleTransfers, $moduleNames)) {
                continue;
            }

            $restrictedRoleWithAccessCount++;
        }

        return $diagnosisTransfer
            ->setUnrestrictedRoleCount($unrestrictedRoleCount)
            ->setRestrictedRoleCount($restrictedRoleCount)
            ->setRestrictedRoleWithAccessCount($restrictedRoleWithAccessCount);
    }

    /**
     * Every role reachable through some ACL group, de-duplicated: the same role attached to several groups
     * is still one role, and counting it twice would misreport how much of the back office is affected.
     *
     * @return array<int>
     */
    protected function findGroupedRoleIds(): array
    {
        $idAclRoles = [];

        foreach ($this->aclFacade->getAllGroups()->getGroups() as $groupTransfer) {
            $idAclGroup = $groupTransfer->getIdAclGroup();

            if ($idAclGroup === null) {
                continue;
            }

            foreach ($this->aclFacade->getGroupRoles($idAclGroup)->getRoles() as $roleTransfer) {
                $idAclRole = $roleTransfer->getIdAclRole();

                if ($idAclRole === null) {
                    continue;
                }

                $idAclRoles[$idAclRole] = $idAclRole;
            }
        }

        return array_values($idAclRoles);
    }

    /**
     * The root-style role: allowed everywhere by a total wildcard. Such a role reaches this package's pages
     * the moment it is installed, with no ACL work at all, which is why it is counted separately — it is
     * the reason a default installation needs nothing done and a locked-down one does.
     *
     * A total-wildcard DENY anywhere in the role revokes this, same as it does in Spryker's own evaluation.
     *
     * @param array<\Generated\Shared\Transfer\RuleTransfer> $ruleTransfers
     */
    protected function hasUnrestrictedAccess(array $ruleTransfers): bool
    {
        $isAllowed = false;

        foreach ($ruleTransfers as $ruleTransfer) {
            if (!$this->isTotalWildcard($ruleTransfer)) {
                continue;
            }

            if ($ruleTransfer->getType() === static::RULE_TYPE_DENY) {
                return false;
            }

            if ($ruleTransfer->getType() !== static::RULE_TYPE_ALLOW) {
                continue;
            }

            $isAllowed = true;
        }

        return $isAllowed;
    }

    /**
     * @param \Generated\Shared\Transfer\RuleTransfer $ruleTransfer
     */
    protected function isTotalWildcard(RuleTransfer $ruleTransfer): bool
    {
        return $ruleTransfer->getBundle() === static::WILDCARD
            && $ruleTransfer->getController() === static::WILDCARD
            && $ruleTransfer->getAction() === static::WILDCARD;
    }

    /**
     * Module-level granularity, not per-page: a role with a rule for ANY controller/action of one of this
     * package's modules has clearly been pointed at the feature deliberately, which is the only thing this
     * diagnostic is trying to establish. Whether that role can reach one specific page is Spryker's own
     * per-request decision, and not something worth second-guessing here.
     *
     * @param array<\Generated\Shared\Transfer\RuleTransfer> $ruleTransfers
     * @param array<string> $moduleNames
     */
    protected function hasAccessToAnyModule(array $ruleTransfers, array $moduleNames): bool
    {
        $allowedModuleNames = $this->collectRuledModuleNames($ruleTransfers, $moduleNames, static::RULE_TYPE_ALLOW);
        $deniedModuleNames = $this->collectRuledModuleNames($ruleTransfers, $moduleNames, static::RULE_TYPE_DENY);

        return array_diff_key($allowedModuleNames, $deniedModuleNames) !== [];
    }

    /**
     * The module names these rules allow, or — for $ruleType = deny — the ones they revoke outright.
     *
     * A deny only counts when it covers the WHOLE module: a deny on one specific controller/action
     * (an adopter may well grant a role the review pages but deny it the
     * apply action) leaves the rest of the module
     * reachable, and must not read as "no access".
     *
     * @param array<\Generated\Shared\Transfer\RuleTransfer> $ruleTransfers
     * @param array<string> $moduleNames
     * @param string $ruleType
     *
     * @return array<string, true>
     */
    protected function collectRuledModuleNames(array $ruleTransfers, array $moduleNames, string $ruleType): array
    {
        $ruledModuleNames = [];

        foreach ($ruleTransfers as $ruleTransfer) {
            if ($ruleTransfer->getType() !== $ruleType) {
                continue;
            }

            if ($ruleType === static::RULE_TYPE_DENY && !$this->isWholeModuleRule($ruleTransfer)) {
                continue;
            }

            foreach ($this->findMatchingModuleNames($ruleTransfer, $moduleNames) as $moduleName) {
                $ruledModuleNames[$moduleName] = true;
            }
        }

        return $ruledModuleNames;
    }

    /**
     * @param \Generated\Shared\Transfer\RuleTransfer $ruleTransfer
     * @param array<string> $moduleNames
     *
     * @return array<string>
     */
    protected function findMatchingModuleNames(RuleTransfer $ruleTransfer, array $moduleNames): array
    {
        if ($ruleTransfer->getBundle() === static::WILDCARD) {
            return $moduleNames;
        }

        return array_values(array_intersect($moduleNames, [$ruleTransfer->getBundle()]));
    }

    /**
     * @param \Generated\Shared\Transfer\RuleTransfer $ruleTransfer
     */
    protected function isWholeModuleRule(RuleTransfer $ruleTransfer): bool
    {
        return $ruleTransfer->getController() === static::WILDCARD
            && $ruleTransfer->getAction() === static::WILDCARD;
    }
}
