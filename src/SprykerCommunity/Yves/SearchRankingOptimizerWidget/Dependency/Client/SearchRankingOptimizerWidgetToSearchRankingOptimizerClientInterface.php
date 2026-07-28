<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Yves\SearchRankingOptimizerWidget\Dependency\Client;

use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer;

interface SearchRankingOptimizerWidgetToSearchRankingOptimizerClientInterface
{
    /**
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer
     */
    public function submitProductRelevanceJudgment(
        SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer,
    ): SearchRankingProductRelevanceJudgmentResponseTransfer;
}
