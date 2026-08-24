<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Glue\SearchRankingOptimizerRestApi\Api\Storefront\Provider;

use Generated\Api\Storefront\SearchRelevanceJudgmentsStorefrontResource;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer;
use Spryker\ApiPlatform\State\Provider\AbstractStorefrontProvider;
use Spryker\Service\Serializer\SerializerServiceInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\SearchRankingOptimizerClientInterface;
use SprykerCommunity\Glue\SearchRankingOptimizerRestApi\Api\Storefront\Mapper\SearchRelevanceJudgmentsResourceMapperInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A judgment is identified by (searchTerm, idProductAbstract, customerReference) — there is no
 * addressable per-item id route for this resource (see the resource schema for why), so `Get` is not
 * implemented; only the `GetCollection` operation is provided, driven entirely by query-string
 * parameters rather than `uriVariables`/path routing. `Post` and `Delete` need no provider-side read at
 * all (`Post` is short-circuited to null by the base class; `Delete` declares `read: false`), so this
 * class only overrides `provideCollection()`.
 */
class SearchRelevanceJudgmentsStorefrontProvider extends AbstractStorefrontProvider
{
    /**
     * @var string
     */
    protected const QUERY_PARAM_SEARCH_TERM = 'searchTerm';

    /**
     * @var string
     */
    protected const QUERY_PARAM_ID_PRODUCT_ABSTRACTS = 'idProductAbstracts';

    public function __construct(
        protected SearchRankingOptimizerClientInterface $searchRankingOptimizerClient,
        protected SerializerServiceInterface $serializer,
        protected SearchRelevanceJudgmentsResourceMapperInterface $searchRelevanceJudgmentsResourceMapper,
    ) {
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException
     *
     * @return array<object>
     */
    protected function provideCollection(): array
    {
        $searchTerm = (string)$this->getRequest()->query->get(static::QUERY_PARAM_SEARCH_TERM, '');

        if ($searchTerm === '') {
            throw new UnprocessableEntityHttpException('The `searchTerm` query parameter is required.');
        }

        if (!$this->hasCustomer()) {
            return [];
        }

        $idProductAbstracts = array_values(array_map(
            static fn (mixed $idProductAbstract): int => (int)$idProductAbstract,
            (array)$this->getRequest()->query->all(static::QUERY_PARAM_ID_PRODUCT_ABSTRACTS),
        ));

        $requestTransfer = (new SearchRankingProductRelevanceJudgmentBatchRequestTransfer())
            ->setSearchTerm($searchTerm)
            ->setStoreName((string)$this->findStoreName())
            ->setLocaleName((string)$this->findLocaleName())
            ->setIdProductAbstracts($idProductAbstracts)
            ->setCustomerReference($this->getCustomerReference());

        $responseTransfer = $this->searchRankingOptimizerClient->getProductRelevanceJudgments($requestTransfer);

        if (!$responseTransfer->getIsSuccess()) {
            return [];
        }

        $resources = [];

        foreach ($responseTransfer->getRatings() as $ratingTransfer) {
            $resourceData = $this->searchRelevanceJudgmentsResourceMapper->mapRatingTransferToResourceData($ratingTransfer, $searchTerm);
            $resources[] = $this->serializer->denormalize($resourceData, SearchRelevanceJudgmentsStorefrontResource::class);
        }

        return $resources;
    }
}
