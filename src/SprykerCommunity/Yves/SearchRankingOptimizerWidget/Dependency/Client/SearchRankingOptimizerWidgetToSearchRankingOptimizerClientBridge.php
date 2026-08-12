<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Yves\SearchRankingOptimizerWidget\Dependency\Client;

use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchResponseTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer;

class SearchRankingOptimizerWidgetToSearchRankingOptimizerClientBridge implements SearchRankingOptimizerWidgetToSearchRankingOptimizerClientInterface
{
    /**
     * @var \SprykerCommunity\Client\SearchRankingOptimizer\SearchRankingOptimizerClientInterface
     */
    protected $searchRankingOptimizerClient;

    /**
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\SearchRankingOptimizerClientInterface $searchRankingOptimizerClient
     */
    public function __construct($searchRankingOptimizerClient)
    {
        $this->searchRankingOptimizerClient = $searchRankingOptimizerClient;
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     */
    public function submitProductRelevanceJudgment(
        SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer,
    ): SearchRankingProductRelevanceJudgmentResponseTransfer {
        return $this->searchRankingOptimizerClient->submitProductRelevanceJudgment($requestTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     */
    public function clearProductRelevanceJudgment(
        SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer,
    ): SearchRankingProductRelevanceJudgmentResponseTransfer {
        return $this->searchRankingOptimizerClient->clearProductRelevanceJudgment($requestTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer $requestTransfer
     */
    public function getProductRelevanceJudgments(
        SearchRankingProductRelevanceJudgmentBatchRequestTransfer $requestTransfer,
    ): SearchRankingProductRelevanceJudgmentBatchResponseTransfer {
        return $this->searchRankingOptimizerClient->getProductRelevanceJudgments($requestTransfer);
    }
}
