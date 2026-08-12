<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune;

use Generated\Shared\Transfer\SearchRankingAutoTuneNotificationDiagnosisTransfer;

interface AutoTuneNotificationDiagnoserInterface
{
    /**
     * Specification:
     * - Reports whether the auto-tune summary email can actually reach anybody, and if not, why.
     * - Answers three things at once: whether any metric has "notify by email" enabled at all, whether
     *   {@see \SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig::AUTO_TUNE_NOTIFICATION_ROLE_NAME}
     *   exists as a real ACL role, and who currently holds it.
     * - Resolves the recipients through {@see AutoTuneNotificationRecipientResolverInterface::resolve()} —
     *   the same call the real send path makes — so a green diagnosis and a delivered email cannot
     *   disagree.
     * - Never throws and never writes: purely a read, safe to call from a console diagnostic on a shop
     *   that has none of this set up.
     */
    public function diagnose(): SearchRankingAutoTuneNotificationDiagnosisTransfer;
}
