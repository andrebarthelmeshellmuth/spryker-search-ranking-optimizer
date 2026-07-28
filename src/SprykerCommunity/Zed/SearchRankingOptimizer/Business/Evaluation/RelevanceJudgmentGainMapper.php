<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation;

use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

class RelevanceJudgmentGainMapper implements RelevanceJudgmentGainMapperInterface
{
    /**
     * {@inheritDoc}
     *
     * @param string $ratingType
     *
     * @return float
     */
    public function mapRatingType(string $ratingType): float
    {
        return SearchRankingOptimizerConfig::getRelevanceJudgmentGainMap()[$ratingType] ?? 0.0;
    }
}
