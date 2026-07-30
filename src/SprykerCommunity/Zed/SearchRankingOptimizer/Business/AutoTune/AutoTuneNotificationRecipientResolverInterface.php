<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune;

interface AutoTuneNotificationRecipientResolverInterface
{
    /**
     * Specification:
     * - Resolves every admin user email that should receive the auto-tune summary email: every member of
     *   every ACL group holding {@see \SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig::AUTO_TUNE_NOTIFICATION_ROLE_NAME}.
     *   No per-metric or per-run subscriber list, deliberately the simplest option of those considered.
     * - Returns an empty array (never throws) when the role doesn't exist yet, or when it exists but no
     *   group/user holds it — a safe, expected outcome (the host shop just hasn't set the role up yet),
     *   not an error.
     *
     * @return array<string>
     */
    public function resolve(): array;
}
