<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Zed;

use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToZedRequestInterface;

class ProductRelevanceJudgmentStub implements ProductRelevanceJudgmentStubInterface
{
    /**
     * @var \SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToZedRequestInterface
     */
    protected SearchRankingOptimizerToZedRequestInterface $zedRequestClient;

    /**
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToZedRequestInterface $zedRequestClient
     */
    public function __construct(SearchRankingOptimizerToZedRequestInterface $zedRequestClient)
    {
        $this->zedRequestClient = $zedRequestClient;
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer
     */
    public function submitProductRelevanceJudgment(
        SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer,
    ): SearchRankingProductRelevanceJudgmentResponseTransfer {
        /** @var \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer $responseTransfer */
        $responseTransfer = $this->zedRequestClient->call(
            '/search-ranking-optimizer/gateway/submit-product-relevance-judgment',
            $requestTransfer,
        );

        return $responseTransfer;
    }
}
