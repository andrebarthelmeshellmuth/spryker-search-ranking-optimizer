<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Glue\SearchRankingOptimizerRestApi\Api\Storefront\Processor;

use Generated\Api\Storefront\SearchRelevanceJudgmentsStorefrontResource;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Spryker\ApiPlatform\State\Processor\AbstractStorefrontProcessor;
use Spryker\Client\Permission\PermissionClientInterface;
use Spryker\Service\Serializer\SerializerServiceInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\SearchRankingOptimizerClientInterface;
use SprykerCommunity\Glue\SearchRankingOptimizerRestApi\Api\Storefront\Mapper\SearchRelevanceJudgmentsResourceMapperInterface;
use SprykerCommunity\Shared\SearchRankingOptimizer\Plugin\RateSearchRelevancePermissionPlugin;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class SearchRelevanceJudgmentsStorefrontProcessor extends AbstractStorefrontProcessor
{
    /**
     * @var string
     */
    protected const QUERY_PARAM_SEARCH_TERM = 'searchTerm';

    /**
     * @var string
     */
    protected const QUERY_PARAM_ID_PRODUCT_ABSTRACT = 'idProductAbstract';

    /**
     * @var string
     */
    protected const MESSAGE_NOT_AUTHORIZED = 'Not authorized to rate search relevance.';

    /**
     * @var string
     */
    protected const MESSAGE_NOT_LOGGED_IN = 'Not logged in.';

    public function __construct(
        protected SearchRankingOptimizerClientInterface $searchRankingOptimizerClient,
        protected PermissionClientInterface $permissionClient,
        protected SerializerServiceInterface $serializer,
        protected SearchRelevanceJudgmentsResourceMapperInterface $searchRelevanceJudgmentsResourceMapper,
    ) {
    }

    /**
     * The `PermissionClientInterface::can()` check below is a UX-level fast-fail only, same posture and
     * same reasoning as `SearchFeedbackTicketsStorefrontProcessor::processPost()` in the sibling
     * search-feedback package: the real, unbypassable authorization happens server-side in Zed's
     * `GatewayController`, re-checked independently via `CompanyUserPermissionAuthorizer` rather than
     * trusting anything asserted here.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException
     */
    protected function processPost(mixed $data): mixed
    {
        if (!$this->hasCustomer()) {
            throw new UnprocessableEntityHttpException(static::MESSAGE_NOT_LOGGED_IN);
        }

        if (!$this->permissionClient->can(RateSearchRelevancePermissionPlugin::KEY)) {
            throw new UnprocessableEntityHttpException(static::MESSAGE_NOT_AUTHORIZED);
        }

        /** @var \Generated\Api\Storefront\SearchRelevanceJudgmentsStorefrontResource $judgmentResource */
        $judgmentResource = $data;
        $requestTransfer = (new SearchRankingProductRelevanceJudgmentRequestTransfer())
            ->setSearchTerm((string)$judgmentResource->getSearchTerm())
            ->setIdProductAbstract((int)$judgmentResource->getIdProductAbstract())
            ->setRatingType((string)$judgmentResource->getRatingType())
            ->setStoreName((string)$this->findStoreName())
            ->setLocaleName((string)$this->findLocaleName())
            ->setCustomerReference($this->getCustomerReference());

        $responseTransfer = $this->searchRankingOptimizerClient->submitProductRelevanceJudgment($requestTransfer);

        if (!$responseTransfer->getIsSuccess() || $responseTransfer->getRating() === null) {
            throw new UnprocessableEntityHttpException($responseTransfer->getErrorMessage() ?: 'Judgment submission failed.');
        }

        $resourceData = $this->searchRelevanceJudgmentsResourceMapper->mapRatingTransferToResourceData(
            $responseTransfer->getRating(),
            $requestTransfer->getSearchTermOrFail(),
        );

        return $this->serializer->denormalize($resourceData, SearchRelevanceJudgmentsStorefrontResource::class);
    }

    /**
     * Identified by `searchTerm`/`idProductAbstract` query parameters — not `uriVariables` — same
     * reasoning as `SearchRelevanceJudgmentsStorefrontProvider::provideCollection()`: this resource has no
     * addressable per-item id. Clearing a judgment that doesn't exist is a no-op on the Zed side
     * (`ProductRelevanceJudgmentWriter::clearJudgment()`), not an error, so this always returns
     * successfully once the caller is identified and authorized.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException
     */
    protected function processDelete(): mixed
    {
        if (!$this->hasCustomer()) {
            throw new UnprocessableEntityHttpException(static::MESSAGE_NOT_LOGGED_IN);
        }

        if (!$this->permissionClient->can(RateSearchRelevancePermissionPlugin::KEY)) {
            throw new UnprocessableEntityHttpException(static::MESSAGE_NOT_AUTHORIZED);
        }

        $searchTerm = (string)$this->getRequest()->query->get(static::QUERY_PARAM_SEARCH_TERM, '');
        $idProductAbstract = (int)$this->getRequest()->query->get(static::QUERY_PARAM_ID_PRODUCT_ABSTRACT, 0);

        if ($searchTerm === '' || $idProductAbstract <= 0) {
            throw new UnprocessableEntityHttpException(
                'The `searchTerm` and `idProductAbstract` query parameters are both required.',
            );
        }

        $requestTransfer = (new SearchRankingProductRelevanceJudgmentRequestTransfer())
            ->setSearchTerm($searchTerm)
            ->setIdProductAbstract($idProductAbstract)
            ->setStoreName((string)$this->findStoreName())
            ->setLocaleName((string)$this->findLocaleName())
            ->setCustomerReference($this->getCustomerReference());

        $this->searchRankingOptimizerClient->clearProductRelevanceJudgment($requestTransfer);

        return null;
    }
}
