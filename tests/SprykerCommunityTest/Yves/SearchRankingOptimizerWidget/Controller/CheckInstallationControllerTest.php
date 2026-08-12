<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Yves\SearchRankingOptimizerWidget\Controller;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\StoreTransfer;
use ReflectionMethod;
use RuntimeException;
use Spryker\Yves\Kernel\View\View;
use SprykerCommunity\Shared\SearchRankingOptimizer\Plugin\RateSearchRelevancePermissionPlugin;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Controller\CheckInstallationController;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Dependency\Client\SearchRankingOptimizerWidgetToSearchRankingOptimizerClientInterface;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Dependency\Client\SearchRankingOptimizerWidgetToStoreClientInterface;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Plugin\Router\SearchRankingOptimizerWidgetRouteProviderPlugin;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Plugin\Twig\SearchRankingOptimizerWidgetTwigPlugin;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\SearchRankingOptimizerWidgetFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * The container-touching helpers are partial-mocked at their narrowest seam (`isTwigFunctionCallable()`,
 * `isRouteRegistered()`, `translate()`, `isAssetProbePresentInBuiltCss()`) so each check's own
 * pass/fail/remedy logic is what is under test, not a booted application. Mirrors the sibling
 * spryker-community/search-debug and spryker-community/search-feedback packages' identical tests for
 * their own CheckInstallationControllers.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Yves
 * @group SearchRankingOptimizerWidget
 * @group Controller
 * @group CheckInstallationControllerTest
 * Add your own group annotations below this line
 */
class CheckInstallationControllerTest extends Unit
{
    public function testIndexActionReturnsAForbiddenResponseWhenThePermissionIsMissing(): void
    {
        // Arrange
        $controller = $this->getMockBuilder(CheckInstallationController::class)
            ->onlyMethods(['can', 'renderView'])
            ->getMock();
        $controller->method('can')->with(RateSearchRelevancePermissionPlugin::KEY)->willReturn(false);
        $controller->expects($this->once())
            ->method('renderView')
            ->with(
                '@SearchRankingOptimizerWidget/views/check-installation/permission-denied.twig',
                [],
                $this->callback(fn (Response $response): bool => $response->getStatusCode() === Response::HTTP_FORBIDDEN),
            )
            ->willReturn(new Response('', Response::HTTP_FORBIDDEN));

        // Act
        $result = $controller->indexAction();

        // Assert
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(Response::HTTP_FORBIDDEN, $result->getStatusCode());
    }

    public function testIndexActionReturnsTheViewWithChecksWhenPermitted(): void
    {
        // Arrange
        $checks = [['label' => 'a check', 'passed' => true, 'remedy' => null]];
        $controller = $this->getMockBuilder(CheckInstallationController::class)
            ->onlyMethods(['can', 'runChecks'])
            ->getMock();
        $controller->method('can')->with(RateSearchRelevancePermissionPlugin::KEY)->willReturn(true);
        $controller->method('runChecks')->willReturn($checks);

        // Act
        $result = $controller->indexAction();

        // Assert
        $this->assertInstanceOf(View::class, $result);
        $this->assertSame(['checks' => $checks], $result->getData());
        $this->assertSame('@SearchRankingOptimizerWidget/views/check-installation/check-installation.twig', $result->getTemplate());
    }

    public function testRunChecksReturnsAllFiveChecksInOrder(): void
    {
        // Arrange
        $controller = $this->getMockBuilder(CheckInstallationController::class)
            ->onlyMethods(['checkTwigFunctions', 'checkRoutes', 'checkJudgmentLookup', 'checkGlossary', 'checkFrontendAssets'])
            ->getMock();

        foreach (['checkTwigFunctions', 'checkRoutes', 'checkJudgmentLookup', 'checkGlossary', 'checkFrontendAssets'] as $checkName) {
            $controller->method($checkName)->willReturn(['label' => $checkName, 'passed' => true, 'remedy' => null]);
        }

        // Act
        $checks = $this->invoke($controller, 'runChecks');

        // Assert
        $this->assertSame(
            ['checkTwigFunctions', 'checkRoutes', 'checkJudgmentLookup', 'checkGlossary', 'checkFrontendAssets'],
            array_column($checks, 'label'),
        );
    }

    public function testCheckTwigFunctionsListsEveryMissingFunctionInItsRemedy(): void
    {
        // Arrange — only the CSRF-token helper resolves; the other two must both be named.
        $controller = $this->getMockBuilder(CheckInstallationController::class)->onlyMethods(['isTwigFunctionCallable'])->getMock();
        $controller->method('isTwigFunctionCallable')->willReturnCallback(
            fn (string $functionName): bool => $functionName === SearchRankingOptimizerWidgetTwigPlugin::FUNCTION_NAME_RATING_CSRF_TOKEN,
        );

        // Act
        $check = $this->invoke($controller, 'checkTwigFunctions');

        // Assert
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('canRateSearchRelevance', (string)$check['remedy']);
        $this->assertStringContainsString('getSearchRelevanceRatings', (string)$check['remedy']);
        $this->assertStringNotContainsString('searchRankingOptimizerRatingCsrfToken,', (string)$check['remedy']);
    }

    public function testCheckTwigFunctionsPassesWhenAllThreeResolve(): void
    {
        // Arrange
        $controller = $this->getMockBuilder(CheckInstallationController::class)->onlyMethods(['isTwigFunctionCallable'])->getMock();
        $controller->method('isTwigFunctionCallable')->willReturn(true);

        // Act
        $check = $this->invoke($controller, 'checkTwigFunctions');

        // Assert
        $this->assertTrue($check['passed']);
        $this->assertNull($check['remedy']);
    }

    public function testCheckRoutesNamesEveryMissingRouteAndExcludesTheCheckInstallationRouteItself(): void
    {
        // Arrange
        $controller = $this->getMockBuilder(CheckInstallationController::class)->onlyMethods(['isRouteRegistered'])->getMock();
        $controller->method('isRouteRegistered')->willReturn(false);

        // Act
        $check = $this->invoke($controller, 'checkRoutes');
        $routeNames = $this->invoke($controller, 'getWidgetRouteNames');

        // Assert — reaching this action already proves the check-installation route exists, so re-checking
        // it could only ever report success.
        $this->assertFalse($check['passed']);
        $this->assertSame(
            [
                SearchRankingOptimizerWidgetRouteProviderPlugin::ROUTE_NAME_SUBMIT_RELEVANCE_JUDGMENT,
                SearchRankingOptimizerWidgetRouteProviderPlugin::ROUTE_NAME_CLEAR_RELEVANCE_JUDGMENT,
            ],
            $routeNames,
        );
        $this->assertStringNotContainsString(SearchRankingOptimizerWidgetRouteProviderPlugin::ROUTE_NAME_CHECK_INSTALLATION, (string)$check['remedy']);
    }

    public function testCheckJudgmentLookupFailsWithTheUnderlyingErrorWhenTheGatewayThrows(): void
    {
        // Arrange
        $optimizerClientMock = $this->createMock(SearchRankingOptimizerWidgetToSearchRankingOptimizerClientInterface::class);
        $optimizerClientMock->method('getProductRelevanceJudgments')->willThrowException(new RuntimeException('zed unreachable'));

        // Act
        $check = $this->invoke($this->createController($optimizerClientMock), 'checkJudgmentLookup');

        // Assert
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('zed unreachable', (string)$check['remedy']);
    }

    public function testCheckJudgmentLookupPassesWhenTheRoundTripCompletes(): void
    {
        // Act — an empty result is a pass: the point is that the round trip completes at all.
        $check = $this->invoke($this->createController(), 'checkJudgmentLookup');

        // Assert
        $this->assertTrue($check['passed']);
        $this->assertNull($check['remedy']);
    }

    public function testCheckGlossaryFailsWhenTheKeyResolvesToItself(): void
    {
        // Arrange — Spryker's translator returns the key itself for a missing translation, which is
        // exactly the silent failure this check exists for.
        $controller = $this->getMockBuilder(CheckInstallationController::class)->onlyMethods(['translate'])->getMock();
        $controller->method('translate')->willReturnArgument(0);

        // Act
        $check = $this->invoke($controller, 'checkGlossary');

        // Assert
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('data:import glossary', (string)$check['remedy']);
    }

    public function testCheckGlossaryPassesWhenTheKeyResolvesToRealText(): void
    {
        // Arrange
        $controller = $this->getMockBuilder(CheckInstallationController::class)->onlyMethods(['translate'])->getMock();
        $controller->method('translate')->willReturn('Rate this product as highly relevant');

        // Act
        $check = $this->invoke($controller, 'checkGlossary');

        // Assert
        $this->assertTrue($check['passed']);
        $this->assertNull($check['remedy']);
    }

    public function testCheckFrontendAssetsDistinguishesNoBundleFoundFromABundleWithoutThisPackage(): void
    {
        // Arrange
        $controllers = [];

        foreach (['noBundle' => null, 'notBundled' => false, 'bundled' => true] as $case => $probeResult) {
            $controllers[$case] = $this->getMockBuilder(CheckInstallationController::class)->onlyMethods(['isAssetProbePresentInBuiltCss'])->getMock();
            $controllers[$case]->method('isAssetProbePresentInBuiltCss')->willReturn($probeResult);
        }

        // Act
        $noBundleCheck = $this->invoke($controllers['noBundle'], 'checkFrontendAssets');
        $notBundledCheck = $this->invoke($controllers['notBundled'], 'checkFrontendAssets');
        $bundledCheck = $this->invoke($controllers['bundled'], 'checkFrontendAssets');

        // Assert — both failures are real, but they need different remedies.
        $this->assertFalse($noBundleCheck['passed']);
        $this->assertStringContainsString('never been built', (string)$noBundleCheck['remedy']);
        $this->assertFalse($notBundledCheck['passed']);
        $this->assertStringContainsString('search-ranking-optimizer-product-rating', (string)$notBundledCheck['remedy']);
        $this->assertTrue($bundledCheck['passed']);
        $this->assertNull($bundledCheck['remedy']);
    }

    /**
     * @param \SprykerCommunity\Yves\SearchRankingOptimizerWidget\Dependency\Client\SearchRankingOptimizerWidgetToSearchRankingOptimizerClientInterface|null $optimizerClient
     */
    protected function createController(
        ?SearchRankingOptimizerWidgetToSearchRankingOptimizerClientInterface $optimizerClient = null,
    ): CheckInstallationController {
        $storeTransfer = (new StoreTransfer())->setName('DE');
        $storeClientMock = $this->createMock(SearchRankingOptimizerWidgetToStoreClientInterface::class);
        $storeClientMock->method('getCurrentStore')->willReturn($storeTransfer);

        $factoryMock = $this->createMock(SearchRankingOptimizerWidgetFactory::class);
        $factoryMock->method('getStoreClient')->willReturn($storeClientMock);
        $factoryMock->method('getSearchRankingOptimizerClient')->willReturn(
            $optimizerClient ?? $this->createMock(SearchRankingOptimizerWidgetToSearchRankingOptimizerClientInterface::class),
        );

        $controller = $this->getMockBuilder(CheckInstallationController::class)->onlyMethods(['getFactory', 'getLocale'])->getMock();
        $controller->method('getFactory')->willReturn($factoryMock);
        $controller->method('getLocale')->willReturn('en_US');

        return $controller;
    }

    /**
     * @param \SprykerCommunity\Yves\SearchRankingOptimizerWidget\Controller\CheckInstallationController $controller
     * @param string $methodName
     * @param mixed ...$arguments
     *
     * @return mixed
     */
    protected function invoke(CheckInstallationController $controller, string $methodName, ...$arguments)
    {
        $method = new ReflectionMethod(CheckInstallationController::class, $methodName);

        return $method->invoke($controller, ...$arguments);
    }
}
