<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query;

use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchResponseTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

class ProductRelevanceJudgmentReader implements ProductRelevanceJudgmentReaderInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizerInterface $searchTermCanonicalizer
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     */
    public function __construct(
        protected SearchTermCanonicalizerInterface $searchTermCanonicalizer,
        protected SearchRankingOptimizerRepositoryInterface $repository,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer $requestTransfer
     */
    public function getJudgments(
        SearchRankingProductRelevanceJudgmentBatchRequestTransfer $requestTransfer,
    ): SearchRankingProductRelevanceJudgmentBatchResponseTransfer {
        $idProductAbstracts = $requestTransfer->getIdProductAbstracts();

        if ($idProductAbstracts === []) {
            return (new SearchRankingProductRelevanceJudgmentBatchResponseTransfer())->setIsSuccess(true);
        }

        $canonicalSearchTerm = $this->searchTermCanonicalizer->canonicalize($requestTransfer->getSearchTermOrFail());

        $queryTransfer = $this->repository->findQueryByTermStoreLocale(
            $canonicalSearchTerm,
            $requestTransfer->getStoreNameOrFail(),
            $requestTransfer->getLocaleNameOrFail(),
        );

        if ($queryTransfer === null) {
            return (new SearchRankingProductRelevanceJudgmentBatchResponseTransfer())->setIsSuccess(true);
        }

        $ratingTransfers = $this->repository->findRatingsByQueryCustomerAndProducts(
            $queryTransfer->getIdSearchRankingQueryOrFail(),
            $requestTransfer->getCustomerReferenceOrFail(),
            $idProductAbstracts,
        );

        $responseTransfer = (new SearchRankingProductRelevanceJudgmentBatchResponseTransfer())->setIsSuccess(true);

        foreach ($ratingTransfers as $ratingTransfer) {
            $responseTransfer->addRating($ratingTransfer);
        }

        return $responseTransfer;
    }
}
