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
use Propel\Runtime\Exception\PropelException;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\InvalidRatingTypeException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\ProductNotInSearchResultsException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
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
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizerInterface $searchTermCanonicalizer
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient
     */
    public function __construct(
        protected SearchTermCanonicalizerInterface $searchTermCanonicalizer,
        protected SearchRankingOptimizerRepositoryInterface $repository,
        protected SearchRankingOptimizerEntityManagerInterface $entityManager,
        protected SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\InvalidRatingTypeException
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\ProductNotInSearchResultsException
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

        $rawSearchTerm = $requestTransfer->getSearchTermOrFail();
        $storeName = $requestTransfer->getStoreNameOrFail();
        $localeName = $requestTransfer->getLocaleNameOrFail();
        $idProductAbstract = $requestTransfer->getIdProductAbstractOrFail();

        // Checked against the RAW search term, not the canonicalized one below -- this must reflect
        // whatever a customer's real search actually returned, and canonicalization is a storage-grouping
        // concern with no bearing on what Elasticsearch would have matched for their real query.
        if (!$this->searchRankingClient->productMatchesSearch($rawSearchTerm, $storeName, $localeName, $idProductAbstract)) {
            throw new ProductNotInSearchResultsException(sprintf(
                'Product #%d is not among the current search results for "%s" -- refusing to record a judgment for a pair that could not have come from a real search.',
                $idProductAbstract,
                $rawSearchTerm,
            ));
        }

        $canonicalSearchTerm = $this->searchTermCanonicalizer->canonicalize($rawSearchTerm);

        $queryTransfer = $this->repository->findQueryByTermStoreLocale($canonicalSearchTerm, $storeName, $localeName);

        if ($queryTransfer === null) {
            $queryTransfer = $this->createQueryHandlingConcurrentInsert($canonicalSearchTerm, $storeName, $localeName);
        }

        $ratingTransfer = (new SearchRankingQueryRatingTransfer())
            ->setFkSearchRankingQuery($queryTransfer->getIdSearchRankingQueryOrFail())
            ->setCustomerReference($requestTransfer->getCustomerReferenceOrFail())
            ->setFkProductAbstract($idProductAbstract)
            ->setRatingType($ratingType);

        return $this->entityManager->upsertRating($ratingTransfer);
    }

    /**
     * Two raters submitting a judgment for the SAME never-before-rated (term, store, locale) at nearly the
     * same time can both reach here after both find nothing to attach their rating to yet -- a genuine
     * time-of-check-to-time-of-use race, not a corner case worth ignoring, since a new search term is
     * exactly the moment two impatient raters are most likely to click within the same instant. The real
     * guarantee against a duplicate query row is the DB's own unique (search_term, store_name, locale_name)
     * constraint (see schema), which lets exactly one insert win; this re-fetches on the loser's failure
     * rather than losing that rater's judgment entirely -- the winner's row is exactly what was wanted
     * anyway. If the re-fetch ALSO comes back empty, the original failure was something else (a genuine
     * DB problem), so it's rethrown rather than silently swallowed.
     *
     * @param string $canonicalSearchTerm
     * @param string $storeName
     * @param string $localeName
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryTransfer
     */
    protected function createQueryHandlingConcurrentInsert(
        string $canonicalSearchTerm,
        string $storeName,
        string $localeName,
    ): SearchRankingQueryTransfer {
        try {
            return $this->entityManager->createQuery(
                (new SearchRankingQueryTransfer())
                    ->setSearchTerm($canonicalSearchTerm)
                    ->setStoreName($storeName)
                    ->setLocaleName($localeName),
            );
        } catch (PropelException $propelException) {
            $queryTransfer = $this->repository->findQueryByTermStoreLocale($canonicalSearchTerm, $storeName, $localeName);

            if ($queryTransfer === null) {
                throw $propelException;
            }

            return $queryTransfer;
        }
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
