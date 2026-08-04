<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Yves\SearchRankingOptimizerWidget\Plugin\Twig;

use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer;
use Spryker\Service\Container\ContainerInterface;
use Spryker\Shared\TwigExtension\Dependency\Plugin\TwigPluginInterface;
use Spryker\Yves\Kernel\AbstractPlugin;
use Spryker\Yves\Kernel\PermissionAwareTrait;
use SprykerCommunity\Shared\SearchRankingOptimizer\Plugin\RateSearchRelevancePermissionPlugin;
use Twig\Environment;
use Twig\TwigFunction;

/**
 * @method \SprykerCommunity\Yves\SearchRankingOptimizerWidget\SearchRankingOptimizerWidgetFactory getFactory()
 *
 * Registers `canRateSearchRelevance()` for the storefront templates — lets the product-rating widget's
 * own include gate itself on the Relevance Rater permission without needing a project-level controller
 * edit to pre-compute and inject that boolean (the same reasoning
 * `SprykerCommunity\Yves\SearchDebug\Plugin\Twig\SearchDebugTwigPlugin` already established for a
 * presentation-only helper — this one happens to check a permission instead of computing colors, but
 * doesn't need a controller for the same reason: it derives entirely from the current session).
 *
 * Also registers `searchRankingOptimizerRatingCsrfToken()` for the same reason: the widget's submit/clear
 * AJAX endpoints are plain POST controllers, not bound to a Symfony Form, so they'd otherwise carry none of
 * the CSRF protection every Form-backed POST in this project gets automatically — same
 * `Symfony\Component\Security\Csrf\CsrfTokenManagerInterface` mechanism `spryker/multi-factor-auth`'s own
 * Yves module uses for its own non-Form AJAX actions.
 *
 * Also registers `getSearchRelevanceRatings(searchTerm, idProductAbstracts)` — one batched Zed round trip
 * per SRP render (not one per product tile) returning `[idProductAbstract => ratingType]` for whichever of
 * the given products this customer has already rated, so the widget can pre-fill its buttons on load
 * instead of always starting blank. Fails open to an empty array (nothing pre-filled, buttons still fully
 * usable) on any permission/session/gateway failure — a stale rating display is never worth breaking SRP
 * rendering over.
 */
class SearchRankingOptimizerWidgetTwigPlugin extends AbstractPlugin implements TwigPluginInterface
{
    use PermissionAwareTrait;

    /**
     * @var string
     */
    public const FUNCTION_NAME_CAN_RATE_SEARCH_RELEVANCE = 'canRateSearchRelevance';

    /**
     * @var string
     */
    public const FUNCTION_NAME_RATING_CSRF_TOKEN = 'searchRankingOptimizerRatingCsrfToken';

    /**
     * @var string
     */
    public const FUNCTION_NAME_GET_SEARCH_RELEVANCE_RATINGS = 'getSearchRelevanceRatings';

    /**
     * Shared by both submit and clear — re-checked in {@see \SprykerCommunity\Yves\SearchRankingOptimizerWidget\Controller\SubmitRelevanceJudgmentController},
     * not tied to either action individually, so one token per page render covers both.
     *
     * @var string
     */
    public const CSRF_TOKEN_ID = 'search-ranking-optimizer-widget-rate';

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter $container is mandated by TwigPluginInterface.
     *
     * @param \Twig\Environment $twig
     * @param \Spryker\Service\Container\ContainerInterface $container
     */
    public function extend(Environment $twig, ContainerInterface $container): Environment
    {
        $twig->addFunction(new TwigFunction(
            static::FUNCTION_NAME_CAN_RATE_SEARCH_RELEVANCE,
            fn (): bool => $this->can(RateSearchRelevancePermissionPlugin::KEY),
        ));

        $twig->addFunction(new TwigFunction(
            static::FUNCTION_NAME_RATING_CSRF_TOKEN,
            fn (): string => $this->getFactory()->getCsrfTokenManager()->getToken(static::CSRF_TOKEN_ID)->getValue(),
        ));

        $twig->addFunction(new TwigFunction(
            static::FUNCTION_NAME_GET_SEARCH_RELEVANCE_RATINGS,
            /**
             * @param array<int> $idProductAbstracts
             *
             * @return array<int, string>
             */
            fn (string $searchTerm, array $idProductAbstracts): array => $this->getSearchRelevanceRatings($searchTerm, $idProductAbstracts),
        ));

        return $twig;
    }

    /**
     * @param array<int> $idProductAbstracts
     *
     * @return array<int, string>
     */
    protected function getSearchRelevanceRatings(string $searchTerm, array $idProductAbstracts): array
    {
        if ($idProductAbstracts === [] || !$this->can(RateSearchRelevancePermissionPlugin::KEY)) {
            return [];
        }

        $customerTransfer = $this->getFactory()->getCustomerClient()->getCustomer();

        if ($customerTransfer === null || $customerTransfer->getCustomerReference() === null) {
            return [];
        }

        $requestTransfer = (new SearchRankingProductRelevanceJudgmentBatchRequestTransfer())
            ->setSearchTerm($searchTerm)
            ->setStoreName($this->getFactory()->getStoreClient()->getCurrentStore()->getNameOrFail())
            ->setLocaleName($this->getLocale())
            ->setIdProductAbstracts($idProductAbstracts)
            ->setCustomerReference($customerTransfer->getCustomerReference());

        $responseTransfer = $this->getFactory()->getSearchRankingOptimizerClient()->getProductRelevanceJudgments($requestTransfer);

        if (!$responseTransfer->getIsSuccess()) {
            return [];
        }

        $ratingTypesByIdProductAbstract = [];

        foreach ($responseTransfer->getRatings() as $ratingTransfer) {
            $ratingTypesByIdProductAbstract[$ratingTransfer->getFkProductAbstractOrFail()] = $ratingTransfer->getRatingTypeOrFail();
        }

        return $ratingTypesByIdProductAbstract;
    }
}
