<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Communication\Controller;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\CompanyUserCollectionTransfer;
use Generated\Shared\Transfer\CompanyUserTransfer;
use Generated\Shared\Transfer\CustomerTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use LogicException;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolver;
use Spryker\Client\SearchElasticsearch\SearchElasticsearchConfig;
use Spryker\Shared\SearchElasticsearch\ElasticaClient\ElasticaClientFactory;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilder;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\NeverInvokedStoreClient;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\ProductSearchMatchVerifier;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller\GatewayController;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToCompanyUserFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToPermissionFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepository;
use SprykerCommunity\Zed\SearchRankingOptimizer\SearchRankingOptimizerDependencyProvider;
use SprykerCommunityTest\Zed\SearchRankingOptimizer\SearchRankingOptimizerZedTester;

/**
 * INTEGRATION TEST — the real round trip this package's Yves widget actually drives, minus the two edges
 * a browser is the only honest way to verify (see README "Testing and CI"): the widget's own JS click
 * handler, and the Yves `SubmitRelevanceJudgmentController` -> `ProductRelevanceJudgmentStub` ->
 * `Client\ZedRequest` HTTP hop over to THIS controller (already proven separately, on the Client side, by
 * {@see \SprykerCommunityTest\Client\SearchRankingOptimizer\Zed\ProductRelevanceJudgmentStubTest} — the
 * plumbing of that hop itself is Spryker core's own `zed-request` package's concern, not this one's).
 *
 * Everything on THIS side of that hop is real and resolved through the REAL Locator — a real
 * `GatewayController`, real `SearchRankingOptimizerFacade`/`BusinessFactory`/`CompanyUserPermissionAuthorizer`
 * chain — via `DependencyHelper::setDependency()`, the standard way to swap ONE bundle dependency without
 * touching anything else the container wires up (see the project's own testing-conventions notes). Only
 * two boundaries are swapped: the CompanyUser/Permission facades behind `CompanyUserPermissionAuthorizer`
 * (that class's own CompanyUser/Permission logic already has full, dedicated coverage in
 * {@see \SprykerCommunityTest\Zed\SearchRankingOptimizer\Communication\Authorization\CompanyUserPermissionAuthorizerTest} —
 * here only ITS RESULT needs to be controllable), and the one Client dependency that cannot run from Zed
 * at all (`Client\SearchRankingOptimizer::productMatchesSearch()`, resolved the normal way via the real
 * Client Locator, crashes from Zed for the same reason documented on `SaturationPointCalibrationSearcher`) — swapped for
 * the exact same real-Elastica, Zed-safe composition {@see CalibrationSearcherTest}/
 * {@see ProductSearchMatchVerifierTest} already use directly. Every write is verified independently by
 * re-reading it back from a fresh {@see SearchRankingOptimizerRepository} query, not by trusting the
 * response the writer itself returns.
 *
 * Reuses the same real, known "chair"-matching product abstract (id 9, "Besucherstuhl"/M1006811)
 * {@see ProductSearchMatchVerifierTest} already relies on.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Communication
 * @group Controller
 * @group GatewayControllerTest
 *
 * @property \SprykerCommunityTest\Zed\SearchRankingOptimizer\SearchRankingOptimizerZedTester $tester
 * @group NeedsSearch
 */
class GatewayControllerTest extends Unit
{
    protected SearchRankingOptimizerZedTester $tester;

    /**
     * @var int
     */
    protected const ID_PRODUCT_ABSTRACT_BESUCHERSTUHL = 9;

    /**
     * @var string
     */
    protected const CUSTOMER_REFERENCE = 'CUST-GATEWAY-CONTROLLER-TEST';

    public function testSubmitPersistsARealRatingWhenAuthorizedAndTheProductReallyMatchesTheSearch(): void
    {
        // Arrange
        $this->stubAuthorization(true);
        $this->stubSearchRankingClient();
        $requestTransfer = $this->createRequestTransfer('chair', SearchRankingOptimizerConfig::RATING_TYPE_HEART);

        // Act
        $responseTransfer = (new GatewayController())->submitProductRelevanceJudgmentAction($requestTransfer);

        // Assert
        $this->assertTrue($responseTransfer->getIsSuccess());
        $this->assertSame(SearchRankingOptimizerConfig::RATING_TYPE_HEART, $responseTransfer->getRatingOrFail()->getRatingTypeOrFail());
        $this->assertSame(static::ID_PRODUCT_ABSTRACT_BESUCHERSTUHL, $responseTransfer->getRatingOrFail()->getFkProductAbstractOrFail());

        // Independently re-read from the DB -- not just trusting the writer's own return value.
        $persistedRating = $this->findPersistedRating('DE', 'en_US');
        $this->assertNotNull($persistedRating, 'The rating must be readable back from a fresh repository query, not just echoed in the response.');
        $this->assertSame(SearchRankingOptimizerConfig::RATING_TYPE_HEART, $persistedRating->getRatingTypeOrFail());
    }

    /**
     * The authorization gate must actually BLOCK the write, not just decorate the response -- an
     * unauthorized request must leave no trace in the database.
     */
    public function testSubmitRefusesAndPersistsNothingWhenNotAuthorized(): void
    {
        // Arrange
        $this->stubAuthorization(false);
        $this->stubSearchRankingClient();
        $requestTransfer = $this->createRequestTransfer('chair', SearchRankingOptimizerConfig::RATING_TYPE_HEART);

        // Act
        $responseTransfer = (new GatewayController())->submitProductRelevanceJudgmentAction($requestTransfer);

        // Assert
        $this->assertFalse($responseTransfer->getIsSuccess());
        $this->assertSame('Not authorized to rate search relevance.', $responseTransfer->getErrorMessage());
        $this->assertNull($this->findPersistedRating('DE', 'en_US'), 'An unauthorized request must not have written anything.');
    }

    /**
     * A judgment for a (search term, product) pair that could not have come from a real search must be
     * refused -- proven here against the REAL search engine, not a stubbed match.
     */
    public function testSubmitRefusesAndPersistsNothingWhenTheProductIsNotAmongTheRealSearchResults(): void
    {
        // Arrange
        $this->stubAuthorization(true);
        $this->stubSearchRankingClient();
        $requestTransfer = $this->createRequestTransfer('zzz_no_such_product_will_ever_match_zzz', SearchRankingOptimizerConfig::RATING_TYPE_HEART);

        // Act
        $responseTransfer = (new GatewayController())->submitProductRelevanceJudgmentAction($requestTransfer);

        // Assert
        $this->assertFalse($responseTransfer->getIsSuccess());
        $this->assertStringContainsString('is not among the current search results', (string)$responseTransfer->getErrorMessage());
        $this->assertNull($this->findPersistedRating('DE', 'en_US'));
    }

    /**
     * The clear round trip: a submitted rating must actually disappear from the database, not just from
     * whatever the caller happens to hold in memory.
     */
    public function testClearActuallyRemovesAPreviouslySubmittedRating(): void
    {
        // Arrange
        $this->stubAuthorization(true);
        $this->stubSearchRankingClient();
        $requestTransfer = $this->createRequestTransfer('chair', SearchRankingOptimizerConfig::RATING_TYPE_CHECK);
        (new GatewayController())->submitProductRelevanceJudgmentAction($requestTransfer);
        $this->assertNotNull($this->findPersistedRating('DE', 'en_US'), 'Setup: the rating must exist before it can be cleared.');

        // Act
        $responseTransfer = (new GatewayController())->clearProductRelevanceJudgmentAction($requestTransfer);

        // Assert
        $this->assertTrue($responseTransfer->getIsSuccess());
        $this->assertNull($this->findPersistedRating('DE', 'en_US'), 'The rating must be gone from the DB after clearing, not merely from the response.');
    }

    /**
     * The batched read backing the SRP widget's pre-fill: submit one real rating through the same gateway,
     * then fetch it back through {@see GatewayController::getProductRelevanceJudgmentsAction()} to prove
     * the whole round trip (canonicalization, query lookup, customer-scoped read), not just the writer.
     */
    public function testGetProductRelevanceJudgmentsReturnsThePreviouslySubmittedRating(): void
    {
        // Arrange
        $this->stubAuthorization(true);
        $this->stubSearchRankingClient();
        $submitRequestTransfer = $this->createRequestTransfer('chair', SearchRankingOptimizerConfig::RATING_TYPE_CHECK);
        (new GatewayController())->submitProductRelevanceJudgmentAction($submitRequestTransfer);

        $batchRequestTransfer = $this->createBatchRequestTransfer('chair', [static::ID_PRODUCT_ABSTRACT_BESUCHERSTUHL, 999999]);

        // Act
        $responseTransfer = (new GatewayController())->getProductRelevanceJudgmentsAction($batchRequestTransfer);

        // Assert
        $this->assertTrue($responseTransfer->getIsSuccess());
        $this->assertCount(1, $responseTransfer->getRatings());
        $this->assertSame(SearchRankingOptimizerConfig::RATING_TYPE_CHECK, $responseTransfer->getRatings()[0]->getRatingTypeOrFail());
        $this->assertSame(static::ID_PRODUCT_ABSTRACT_BESUCHERSTUHL, $responseTransfer->getRatings()[0]->getFkProductAbstractOrFail());
    }

    /**
     * The authorization gate must actually block the read, same posture as the write actions.
     */
    public function testGetProductRelevanceJudgmentsRefusesWhenNotAuthorized(): void
    {
        // Arrange
        $this->stubAuthorization(false);
        $batchRequestTransfer = $this->createBatchRequestTransfer('chair', [static::ID_PRODUCT_ABSTRACT_BESUCHERSTUHL]);

        // Act
        $responseTransfer = (new GatewayController())->getProductRelevanceJudgmentsAction($batchRequestTransfer);

        // Assert
        $this->assertFalse($responseTransfer->getIsSuccess());
        $this->assertSame('Not authorized to rate search relevance.', $responseTransfer->getErrorMessage());
    }

    protected function findPersistedRating(string $storeName, string $localeName): ?SearchRankingQueryRatingTransfer
    {
        foreach ((new SearchRankingOptimizerRepository())->findRatingsByStoreLocale($storeName, $localeName) as $ratingTransfer) {
            if (
                $ratingTransfer->getCustomerReferenceOrFail() === static::CUSTOMER_REFERENCE
                && $ratingTransfer->getFkProductAbstractOrFail() === static::ID_PRODUCT_ABSTRACT_BESUCHERSTUHL
            ) {
                return $ratingTransfer;
            }
        }

        return null;
    }

    protected function createRequestTransfer(string $searchTerm, string $ratingType): SearchRankingProductRelevanceJudgmentRequestTransfer
    {
        return (new SearchRankingProductRelevanceJudgmentRequestTransfer())
            ->setSearchTerm($searchTerm)
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setIdProductAbstract(static::ID_PRODUCT_ABSTRACT_BESUCHERSTUHL)
            ->setRatingType($ratingType)
            ->setCustomerReference(static::CUSTOMER_REFERENCE);
    }

    /**
     * @param string $searchTerm
     * @param array<int> $idProductAbstracts
     */
    protected function createBatchRequestTransfer(string $searchTerm, array $idProductAbstracts): SearchRankingProductRelevanceJudgmentBatchRequestTransfer
    {
        return (new SearchRankingProductRelevanceJudgmentBatchRequestTransfer())
            ->setSearchTerm($searchTerm)
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setIdProductAbstracts($idProductAbstracts)
            ->setCustomerReference(static::CUSTOMER_REFERENCE);
    }

    /**
     * Swaps the CompanyUser/Permission facades `CompanyUserPermissionAuthorizer` depends on, globally for this
     * test, via `DependencyHelper` -- the real Locator resolves the real `GatewayController`, real
     * `SearchRankingOptimizerFacade`, and the real (unmodified) `CompanyUserPermissionAuthorizer`, all of which
     * end up seeing only this controlled authorization outcome.
     *
     * @param bool $isAuthorized
     */
    protected function stubAuthorization(bool $isAuthorized): void
    {
        $companyUserCollectionTransfer = new CompanyUserCollectionTransfer();

        if ($isAuthorized) {
            $companyUserCollectionTransfer->addCompanyUser((new CompanyUserTransfer())->setIdCompanyUser(1));
        }

        $companyUserFacadeMock = $this->createMock(SearchRankingOptimizerToCompanyUserFacadeInterface::class);
        $companyUserFacadeMock->method('getActiveCompanyUsersByCustomerReference')
            ->with($this->callback(fn (CustomerTransfer $customerTransfer) => $customerTransfer->getCustomerReference() === static::CUSTOMER_REFERENCE))
            ->willReturn($companyUserCollectionTransfer);

        $permissionFacadeMock = $this->createMock(SearchRankingOptimizerToPermissionFacadeInterface::class);
        $permissionFacadeMock->method('can')->willReturn($isAuthorized);

        $this->tester->setDependency(SearchRankingOptimizerDependencyProvider::FACADE_COMPANY_USER, $companyUserFacadeMock);
        $this->tester->setDependency(SearchRankingOptimizerDependencyProvider::FACADE_PERMISSION, $permissionFacadeMock);
    }

    /**
     * Swaps `Client\SearchRankingOptimizer` (which crashes when resolved via the real Locator from Zed)
     * for the same real-Elastica, Zed-safe composition {@see CalibrationSearcherTest}/
     * {@see ProductSearchMatchVerifierTest} use directly -- `productMatchesSearch()` is the only method
     * `ProductRelevanceJudgmentWriter` ever calls on this dependency, so the other three are never invoked.
     *
     * @throws \LogicException
     */
    protected function stubSearchRankingClient(): void
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());
        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), $searchElasticsearchConfig);
        $verifier = new ProductSearchMatchVerifier($elasticaClient, $indexNameResolver, new LiveCatalogSearchQueryBuilder());

        $client = new class ($verifier) implements SearchRankingOptimizerToSearchRankingClientInterface {
            public function __construct(protected ProductSearchMatchVerifier $verifier)
            {
            }

            /**
             * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter Interface-mandated signature; never exercised by this test.
             *
             * @param string $searchTerm
             * @param string $storeName
             * @param string $localeName
             * @param int $limit
             *
             * @throws \LogicException
             *
             * @return array<float>
             */
            public function getCalibrationScores(string $searchTerm, string $storeName, string $localeName, int $limit): array
            {
                throw new LogicException('Not exercised by GatewayControllerTest.');
            }

            /**
             * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter Interface-mandated signature; never exercised by this test.
             *
             * @param string $searchTerm
             * @param string $storeName
             * @param float $blendWeight
             *
             * @throws \LogicException
             */
            public function getCalibrationSpecificity(string $searchTerm, string $storeName, float $blendWeight): float
            {
                throw new LogicException('Not exercised by GatewayControllerTest.');
            }

            /**
             * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter Interface-mandated signature; never exercised by this test.
             *
             * @param \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer $requestTransfer
             *
             * @throws \LogicException
             */
            public function evaluateRankings(SearchRankingEvaluationRequestTransfer $requestTransfer): SearchRankingEvaluationResponseTransfer
            {
                throw new LogicException('Not exercised by GatewayControllerTest.');
            }

            public function productMatchesSearch(string $searchTerm, string $storeName, string $localeName, int $idProductAbstract): bool
            {
                return $this->verifier->matches($searchTerm, $storeName, $localeName, $idProductAbstract);
            }

            /**
             * @return array<int, string>
             */
            public function getRegisteredRankingStrategyNames(): array
            {
                return ['adaptive_formula'];
            }
        };

        $this->tester->setDependency(SearchRankingOptimizerDependencyProvider::CLIENT_SEARCH_RANKING_OPTIMIZER, $client);
    }
}
