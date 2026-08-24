<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Glue\SearchRankingOptimizerRestApi\Api\Storefront\Processor;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Post;
use Codeception\Test\Unit;
use Generated\Api\Storefront\SearchRelevanceJudgmentsStorefrontResource;
use Generated\Shared\Transfer\CustomerTransfer;
use Generated\Shared\Transfer\LocaleTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\StoreTransfer;
use Spryker\Client\Permission\PermissionClientInterface;
use Spryker\Service\Serializer\SerializerServiceInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\SearchRankingOptimizerClientInterface;
use SprykerCommunity\Glue\SearchRankingOptimizerRestApi\Api\Storefront\Mapper\SearchRelevanceJudgmentsResourceMapperInterface;
use SprykerCommunity\Glue\SearchRankingOptimizerRestApi\Api\Storefront\Processor\SearchRelevanceJudgmentsStorefrontProcessor;
use SprykerCommunity\Shared\SearchRankingOptimizer\Plugin\RateSearchRelevancePermissionPlugin;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @group SprykerCommunityTest
 * @group Glue
 * @group SearchRankingOptimizerRestApi
 * @group SearchRelevanceJudgmentsStorefrontProcessorTest
 */
class SearchRelevanceJudgmentsStorefrontProcessorTest extends Unit
{
    public function testProcessPostThrowsWhenNoCustomerIsAuthenticated(): void
    {
        // Arrange
        $processor = $this->buildProcessor($this->createMock(SearchRankingOptimizerClientInterface::class), $this->createMock(PermissionClientInterface::class));
        $resource = $this->buildResource();
        $request = $this->buildRequest(withCustomer: false);

        // Assert
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Not logged in.');

        // Act
        $processor->process($resource, new Post(), [], ['request' => $request]);
    }

    public function testProcessPostThrowsWhenTheCustomerLacksThePermission(): void
    {
        // Arrange
        $permissionClientMock = $this->createMock(PermissionClientInterface::class);
        $permissionClientMock->method('can')->with(RateSearchRelevancePermissionPlugin::KEY)->willReturn(false);

        $processor = $this->buildProcessor($this->createMock(SearchRankingOptimizerClientInterface::class), $permissionClientMock);
        $resource = $this->buildResource();
        $request = $this->buildRequest(withCustomer: true);

        // Assert
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Not authorized to rate search relevance.');

        // Act
        $processor->process($resource, new Post(), [], ['request' => $request]);
    }

    public function testProcessPostThrowsWithTheClientsErrorMessageWhenSubmissionFails(): void
    {
        // Arrange
        $permissionClientMock = $this->createMock(PermissionClientInterface::class);
        $permissionClientMock->method('can')->willReturn(true);

        $searchRankingOptimizerClientMock = $this->createMock(SearchRankingOptimizerClientInterface::class);
        $searchRankingOptimizerClientMock->method('submitProductRelevanceJudgment')->willReturn(
            (new SearchRankingProductRelevanceJudgmentResponseTransfer())
                ->setIsSuccess(false)
                ->setErrorMessage('Product #123 is not among the current search results for "garden chair".'),
        );

        $processor = $this->buildProcessor($searchRankingOptimizerClientMock, $permissionClientMock);
        $resource = $this->buildResource();
        $request = $this->buildRequest(withCustomer: true);

        // Assert
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Product #123 is not among the current search results for "garden chair".');

        // Act
        $processor->process($resource, new Post(), [], ['request' => $request]);
    }

    public function testProcessPostReturnsTheDenormalizedResourceOnSuccess(): void
    {
        // Arrange
        $permissionClientMock = $this->createMock(PermissionClientInterface::class);
        $permissionClientMock->method('can')->willReturn(true);

        $ratingTransfer = (new SearchRankingQueryRatingTransfer())
            ->setIdSearchRankingQueryRating(17)
            ->setFkProductAbstract(123)
            ->setRatingType('heart');

        $searchRankingOptimizerClientMock = $this->createMock(SearchRankingOptimizerClientInterface::class);
        $searchRankingOptimizerClientMock->method('submitProductRelevanceJudgment')->willReturn(
            (new SearchRankingProductRelevanceJudgmentResponseTransfer())->setIsSuccess(true)->setRating($ratingTransfer),
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

        $processor = $this->buildProcessor($searchRankingOptimizerClientMock, $permissionClientMock, $serializerMock, $mapperMock);
        $resource = $this->buildResource();
        $request = $this->buildRequest(withCustomer: true);

        // Act
        $result = $processor->process($resource, new Post(), [], ['request' => $request]);

        // Assert
        $this->assertSame($expectedResource, $result);
    }

    public function testProcessDeleteThrowsWhenQueryParametersAreMissing(): void
    {
        // Arrange
        $permissionClientMock = $this->createMock(PermissionClientInterface::class);
        $permissionClientMock->method('can')->willReturn(true);

        $processor = $this->buildProcessor($this->createMock(SearchRankingOptimizerClientInterface::class), $permissionClientMock);
        $request = $this->buildRequest(withCustomer: true);

        // Assert
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('The `searchTerm` and `idProductAbstract` query parameters are both required.');

        // Act
        $processor->process(null, new Delete(), [], ['request' => $request]);
    }

    public function testProcessDeleteCallsTheClientAndReturnsNullOnSuccess(): void
    {
        // Arrange
        $permissionClientMock = $this->createMock(PermissionClientInterface::class);
        $permissionClientMock->method('can')->willReturn(true);

        $searchRankingOptimizerClientMock = $this->createMock(SearchRankingOptimizerClientInterface::class);
        $searchRankingOptimizerClientMock->expects($this->once())->method('clearProductRelevanceJudgment');

        $processor = $this->buildProcessor($searchRankingOptimizerClientMock, $permissionClientMock);
        $request = $this->buildRequest(withCustomer: true, query: ['searchTerm' => 'garden chair', 'idProductAbstract' => '123']);

        // Act
        $result = $processor->process(null, new Delete(), [], ['request' => $request]);

        // Assert
        $this->assertNull($result);
    }

    protected function buildResource(): SearchRelevanceJudgmentsStorefrontResource
    {
        $resource = new SearchRelevanceJudgmentsStorefrontResource();
        $resource->setSearchTerm('garden chair');
        $resource->setIdProductAbstract(123);
        $resource->setRatingType('heart');

        return $resource;
    }

    protected function buildProcessor(
        SearchRankingOptimizerClientInterface $searchRankingOptimizerClientMock,
        PermissionClientInterface $permissionClientMock,
        ?SerializerServiceInterface $serializerMock = null,
        ?SearchRelevanceJudgmentsResourceMapperInterface $mapperMock = null,
    ): SearchRelevanceJudgmentsStorefrontProcessor {
        return new SearchRelevanceJudgmentsStorefrontProcessor(
            $searchRankingOptimizerClientMock,
            $permissionClientMock,
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
