<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune;

use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;

/**
 * Fans an auto-tune metric config out to every locale it should actually apply to — the same "does this
 * metric's config live at one locale, or every real locale of the store" decision `search-ranking`'s own
 * `MetricWriter` already makes for formula/isActive/shape/weight, reused here via
 * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface::resolveEffectiveWeightLocales()}
 * rather than re-derived.
 */
interface AutoTuneMetricConfigWriterInterface
{
    /**
     * Saves $autoTuneMetricConfigTransfer at every locale {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface::resolveEffectiveWeightLocales()}
     * resolves for its (idSearchRankingMetric, storeName, localeName) — just the one named locale for a
     * genuinely locale-scoped metric, or every real locale of the store for a store-wide one (the common
     * case), so an admin editing the settings at any one locale of a store-wide metric doesn't leave its
     * sibling locales silently unconfigured.
     *
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     *
     * @return array<string, \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer> Keyed by the locale each row was actually saved at.
     */
    public function save(SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer): array;
}
