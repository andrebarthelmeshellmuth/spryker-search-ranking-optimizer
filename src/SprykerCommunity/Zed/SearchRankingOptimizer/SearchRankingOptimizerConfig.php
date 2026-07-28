<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer;

use Spryker\Zed\Kernel\AbstractBundleConfig;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig as SharedSearchRankingOptimizerConfig;

class SearchRankingOptimizerConfig extends AbstractBundleConfig
{
    /**
     * Specification:
     * - Maps each rating type to the numeric gain `_rank_eval` (Phase O3) uses as its relevance judgment.
     *   Deliberately project-overridable rather than hardcoded: nDCG's standard gain function is
     *   `2^rating - 1`, so this illustrative 3/1/0 default gives heart a much bigger gain-weight (7) than
     *   check (1) — may be exactly right for a given catalog, may want a flatter/linear scale once tested
     *   against real judgment data.
     *
     * @api
     *
     * @return array<string, int>
     */
    public function getRatingTypeGainMap(): array
    {
        return [
            SharedSearchRankingOptimizerConfig::RATING_TYPE_HEART => 3,
            SharedSearchRankingOptimizerConfig::RATING_TYPE_CHECK => 1,
            SharedSearchRankingOptimizerConfig::RATING_TYPE_X => 0,
        ];
    }
}
