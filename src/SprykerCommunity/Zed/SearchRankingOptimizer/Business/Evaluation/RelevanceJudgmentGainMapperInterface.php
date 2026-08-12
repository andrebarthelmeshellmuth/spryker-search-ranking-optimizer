<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation;

interface RelevanceJudgmentGainMapperInterface
{
    /**
     * Specification:
     * - Maps a `SearchRankingOptimizerConfig::RATING_TYPE_*` value to its configured numeric rank_eval
     *   gain ({@see \SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig::getRelevanceJudgmentGainMap()}).
     * - An unrecognized rating type maps to gain 0.0 rather than throwing — ratings are always written
     *   through {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\ProductRelevanceJudgmentWriterInterface},
     *   which already rejects an unknown rating type before persisting anything, so this is a defensive
     *   default for old/foreign data, not a path expected to trigger in practice.
     *
     * @param string $ratingType
     */
    public function mapRatingType(string $ratingType): float;
}
