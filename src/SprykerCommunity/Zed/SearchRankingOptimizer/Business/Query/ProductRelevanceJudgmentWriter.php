<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query;

use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\InvalidRatingTypeException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

class ProductRelevanceJudgmentWriter implements ProductRelevanceJudgmentWriterInterface
{
    /**
     * @var array<string>
     */
    protected const VALID_RATING_TYPES = [
        SearchRankingOptimizerConfig::RATING_TYPE_HEART,
        SearchRankingOptimizerConfig::RATING_TYPE_CHECK,
        SearchRankingOptimizerConfig::RATING_TYPE_X,
    ];

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizerInterface
     */
    protected SearchTermCanonicalizerInterface $searchTermCanonicalizer;

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface
     */
    protected SearchRankingOptimizerRepositoryInterface $repository;

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface
     */
    protected SearchRankingOptimizerEntityManagerInterface $entityManager;

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizerInterface $searchTermCanonicalizer
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     */
    public function __construct(
        SearchTermCanonicalizerInterface $searchTermCanonicalizer,
        SearchRankingOptimizerRepositoryInterface $repository,
        SearchRankingOptimizerEntityManagerInterface $entityManager,
    ) {
        $this->searchTermCanonicalizer = $searchTermCanonicalizer;
        $this->repository = $repository;
        $this->entityManager = $entityManager;
    }

    /**
     * {@inheritDoc}
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\InvalidRatingTypeException
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryRatingTransfer
     */
    public function submitJudgment(SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer): SearchRankingQueryRatingTransfer
    {
        $ratingType = $requestTransfer->getRatingTypeOrFail();

        if (!in_array($ratingType, static::VALID_RATING_TYPES, true)) {
            throw new InvalidRatingTypeException(sprintf(
                'Unknown rating type "%s", expected one of: %s.',
                $ratingType,
                implode(', ', static::VALID_RATING_TYPES),
            ));
        }

        $canonicalSearchTerm = $this->searchTermCanonicalizer->canonicalize($requestTransfer->getSearchTermOrFail());
        $storeName = $requestTransfer->getStoreNameOrFail();
        $localeName = $requestTransfer->getLocaleNameOrFail();

        $queryTransfer = $this->repository->findQueryByTermStoreLocale($canonicalSearchTerm, $storeName, $localeName);

        if ($queryTransfer === null) {
            $queryTransfer = $this->entityManager->createQuery(
                (new SearchRankingQueryTransfer())
                    ->setSearchTerm($canonicalSearchTerm)
                    ->setStoreName($storeName)
                    ->setLocaleName($localeName),
            );
        }

        $ratingTransfer = (new SearchRankingQueryRatingTransfer())
            ->setFkSearchRankingQuery($queryTransfer->getIdSearchRankingQueryOrFail())
            ->setCustomerReference($requestTransfer->getCustomerReferenceOrFail())
            ->setFkProductAbstract($requestTransfer->getIdProductAbstractOrFail())
            ->setRatingType($ratingType);

        return $this->entityManager->upsertRating($ratingTransfer);
    }

    /**
     * {@inheritDoc}
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @return void
     */
    public function clearJudgment(SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer): void
    {
        $canonicalSearchTerm = $this->searchTermCanonicalizer->canonicalize($requestTransfer->getSearchTermOrFail());
        $storeName = $requestTransfer->getStoreNameOrFail();
        $localeName = $requestTransfer->getLocaleNameOrFail();

        $queryTransfer = $this->repository->findQueryByTermStoreLocale($canonicalSearchTerm, $storeName, $localeName);

        if ($queryTransfer === null) {
            return;
        }

        $this->entityManager->deleteRating(
            $queryTransfer->getIdSearchRankingQueryOrFail(),
            $requestTransfer->getCustomerReferenceOrFail(),
            $requestTransfer->getIdProductAbstractOrFail(),
        );
    }
}
