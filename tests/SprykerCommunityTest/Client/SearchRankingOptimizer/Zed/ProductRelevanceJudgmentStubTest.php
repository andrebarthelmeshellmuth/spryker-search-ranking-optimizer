<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Client\SearchRankingOptimizer\Zed;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchResponseTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToZedRequestInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Zed\ProductRelevanceJudgmentStub;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Client
 * @group SearchRankingOptimizer
 * @group Zed
 * @group ProductRelevanceJudgmentStubTest
 * Add your own group annotations below this line
 */
class ProductRelevanceJudgmentStubTest extends Unit
{
    public function testSubmitProductRelevanceJudgmentCallsTheExpectedGatewayUrlWithTheRequestTransfer(): void
    {
        // Arrange
        $requestTransfer = (new SearchRankingProductRelevanceJudgmentRequestTransfer())
            ->setSearchTerm('chair')
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setIdProductAbstract(1)
            ->setRatingType('heart')
            ->setCustomerReference('CUST-1');

        $responseTransfer = (new SearchRankingProductRelevanceJudgmentResponseTransfer())->setIsSuccess(true);

        $zedRequestClientMock = $this->createMock(SearchRankingOptimizerToZedRequestInterface::class);
        $zedRequestClientMock->expects($this->once())
            ->method('call')
            ->with('/search-ranking-optimizer/gateway/submit-product-relevance-judgment', $requestTransfer)
            ->willReturn($responseTransfer);

        $stub = new ProductRelevanceJudgmentStub($zedRequestClientMock);

        // Act
        $result = $stub->submitProductRelevanceJudgment($requestTransfer);

        // Assert
        $this->assertSame($responseTransfer, $result);
    }

    public function testClearProductRelevanceJudgmentCallsTheExpectedGatewayUrlWithTheRequestTransfer(): void
    {
        // Arrange
        $requestTransfer = (new SearchRankingProductRelevanceJudgmentRequestTransfer())
            ->setSearchTerm('chair')
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setIdProductAbstract(1)
            ->setCustomerReference('CUST-1');

        $responseTransfer = (new SearchRankingProductRelevanceJudgmentResponseTransfer())->setIsSuccess(true);

        $zedRequestClientMock = $this->createMock(SearchRankingOptimizerToZedRequestInterface::class);
        $zedRequestClientMock->expects($this->once())
            ->method('call')
            ->with('/search-ranking-optimizer/gateway/clear-product-relevance-judgment', $requestTransfer)
            ->willReturn($responseTransfer);

        $stub = new ProductRelevanceJudgmentStub($zedRequestClientMock);

        // Act
        $result = $stub->clearProductRelevanceJudgment($requestTransfer);

        // Assert
        $this->assertSame($responseTransfer, $result);
    }

    public function testGetProductRelevanceJudgmentsCallsTheExpectedGatewayUrlWithTheBatchRequestTransfer(): void
    {
        // Arrange
        $requestTransfer = (new SearchRankingProductRelevanceJudgmentBatchRequestTransfer())
            ->setSearchTerm('chair')
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setIdProductAbstracts([1, 2, 3])
            ->setCustomerReference('CUST-1');

        $responseTransfer = (new SearchRankingProductRelevanceJudgmentBatchResponseTransfer())->setIsSuccess(true);

        $zedRequestClientMock = $this->createMock(SearchRankingOptimizerToZedRequestInterface::class);
        $zedRequestClientMock->expects($this->once())
            ->method('call')
            ->with('/search-ranking-optimizer/gateway/get-product-relevance-judgments', $requestTransfer)
            ->willReturn($responseTransfer);

        $stub = new ProductRelevanceJudgmentStub($zedRequestClientMock);

        // Act
        $result = $stub->getProductRelevanceJudgments($requestTransfer);

        // Assert
        $this->assertSame($responseTransfer, $result);
    }
}
