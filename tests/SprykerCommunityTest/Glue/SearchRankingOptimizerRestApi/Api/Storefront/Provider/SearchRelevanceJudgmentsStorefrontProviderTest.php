<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Glue\SearchRankingOptimizerRestApi\Api\Storefront\Provider;

use ApiPlatform\Metadata\GetCollection;
use ArrayObject;
use Codeception\Test\Unit;
use Generated\Api\Storefront\SearchRelevanceJudgmentsStorefrontResource;
use Generated\Shared\Transfer\CustomerTransfer;
use Generated\Shared\Transfer\LocaleTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchResponseTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\StoreTransfer;
use Spryker\Service\Serializer\SerializerServiceInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\SearchRankingOptimizerClientInterface;
use SprykerCommunity\Glue\SearchRankingOptimizerRestApi\Api\Storefront\Mapper\SearchRelevanceJudgmentsResourceMapperInterface;
use SprykerCommunity\Glue\SearchRankingOptimizerRestApi\Api\Storefront\Provider\SearchRelevanceJudgmentsStorefrontProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @group SprykerCommunityTest
 * @group Glue
 * @group SearchRankingOptimizerRestApi
 * @group SearchRelevanceJudgmentsStorefrontProviderTest
 */
class SearchRelevanceJudgmentsStorefrontProviderTest extends Unit
{
    public function testProvideCollectionThrowsWhenSearchTermQueryParameterIsMissing(): void
    {
        // Arrange
        $provider = $this->buildProvider($this->createMock(SearchRankingOptimizerClientInterface::class));
        $request = $this->buildRequest(withCustomer: true);

        // Assert
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('The `searchTerm` query parameter is required.');

        // Act
        $provider->provide(new GetCollection(), [], ['request' => $request]);
    }

    public function testProvideCollectionReturnsEmptyArrayWhenNoCustomerIsAuthenticated(): void
    {
        // Arrange
        $provider = $this->buildProvider($this->createMock(SearchRankingOptimizerClientInterface::class));
        $request = $this->buildRequest(withCustomer: false, query: ['searchTerm' => 'garden chair']);

        // Act
        $result = $provider->provide(new GetCollection(), [], ['request' => $request]);

        // Assert
        $this->assertSame([], $result);
    }

    public function testProvideCollectionReturnsEmptyArrayWhenTheClientResponseIsNotSuccessful(): void
    {
        // Arrange
        $searchRankingOptimizerClientMock = $this->createMock(SearchRankingOptimizerClientInterface::class);
        $searchRankingOptimizerClientMock->method('getProductRelevanceJudgments')->willReturn(
            (new SearchRankingProductRelevanceJudgmentBatchResponseTransfer())->setIsSuccess(false),
        );

        $provider = $this->buildProvider($searchRankingOptimizerClientMock);
        $request = $this->buildRequest(withCustomer: true, query: ['searchTerm' => 'garden chair']);

        // Act
        $result = $provider->provide(new GetCollection(), [], ['request' => $request]);

        // Assert
        $this->assertSame([], $result);
    }

    public function testProvideCollectionReturnsDenormalizedResourcesOnSuccess(): void
    {
        // Arrange
        $ratingTransfer = (new SearchRankingQueryRatingTransfer())
            ->setIdSearchRankingQueryRating(17)
            ->setFkProductAbstract(123)
            ->setRatingType('heart');

        $searchRankingOptimizerClientMock = $this->createMock(SearchRankingOptimizerClientInterface::class);
        $searchRankingOptimizerClientMock->method('getProductRelevanceJudgments')->willReturn(
            (new SearchRankingProductRelevanceJudgmentBatchResponseTransfer())
                ->setIsSuccess(true)
                ->setRatings(new ArrayObject([$ratingTransfer])),
        );

        $mapperMock = $this->createMock(SearchRelevanceJudgmentsResourceMapperInterface::class);
        $mapperMock->method('mapRatingTransferToResourceData')
            ->with($ratingTransfer, 'garden chair')
            ->willReturn(['id' => '17', 'searchTerm' => 'garden chair']);

        $expectedResource = new SearchRelevanceJudgmentsStorefrontResource();

        $serializerMock = $this->createMock(SerializerServiceInterface::class);
        $serializerMock->method('denormalize')
            ->with(['id' => '17', 'searchTerm' => 'garden chair'], SearchRelevanceJudgmentsStorefrontResource::class)
            ->willReturn($expectedResource);

        $provider = $this->buildProvider($searchRankingOptimizerClientMock, $serializerMock, $mapperMock);
        $request = $this->buildRequest(withCustomer: true, query: ['searchTerm' => 'garden chair', 'idProductAbstracts' => ['123']]);

        // Act
        $result = $provider->provide(new GetCollection(), [], ['request' => $request]);

        // Assert
        $this->assertSame([$expectedResource], $result);
    }

    protected function buildProvider(
        SearchRankingOptimizerClientInterface $searchRankingOptimizerClientMock,
        ?SerializerServiceInterface $serializerMock = null,
        ?SearchRelevanceJudgmentsResourceMapperInterface $mapperMock = null,
    ): SearchRelevanceJudgmentsStorefrontProvider {
        return new SearchRelevanceJudgmentsStorefrontProvider(
            $searchRankingOptimizerClientMock,
            $serializerMock ?? $this->createMock(SerializerServiceInterface::class),
            $mapperMock ?? $this->createMock(SearchRelevanceJudgmentsResourceMapperInterface::class),
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function buildRequest(bool $withCustomer, array $query = []): Request
    {
        $request = new Request($query);

        if ($withCustomer) {
            $request->attributes->set('CustomerTransfer', (new CustomerTransfer())->setCustomerReference('DE--123'));
        }

        $request->attributes->set('StoreTransfer', (new StoreTransfer())->setName('DE'));
        $request->attributes->set('LocaleTransfer', (new LocaleTransfer())->setLocaleName('de_DE'));

        return $request;
    }
}
