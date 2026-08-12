<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Acl;

use Generated\Shared\Transfer\SearchRankingOptimizerBackOfficeAccessDiagnosisTransfer;

interface BackOfficeAccessAnalyzerInterface
{
    /**
     * Specification:
     * - Reports how many back-office roles could reach the given Zed module(s), split by whether they hold
     *   unrestricted (wildcard) access or an explicit rule.
     * - Only considers roles reachable through an ACL group: a role attached to no group grants nothing to
     *   anybody, so counting it would overstate who can actually get in.
     * - Deny rules beat allow rules, and a wildcard matches any segment — mirroring
     *   {@see \Spryker\Zed\Acl\Business\Model\RuleValidator}, whose evaluation this reproduces at the
     *   granularity a diagnostic needs. It is an approximation of that evaluation, not a second
     *   implementation of the access check itself: the real decision on the request path is always
     *   Spryker's own, per user, and nothing here can grant or deny anything.
     * - Never throws and never writes.
     *
     * @param array<string> $moduleNames Zed module names as they appear in navigation.xml `<bundle>`.
     */
    public function analyze(array $moduleNames): SearchRankingOptimizerBackOfficeAccessDiagnosisTransfer;
}
