<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Yves\SearchRankingOptimizerWidget\Controller;

use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Spryker\Yves\Kernel\Controller\AbstractController;
use Spryker\Yves\Kernel\PermissionAwareTrait;
use SprykerCommunity\Shared\SearchRankingOptimizer\Plugin\RateSearchRelevancePermissionPlugin;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Plugin\Twig\SearchRankingOptimizerWidgetTwigPlugin;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * @method \SprykerCommunity\Yves\SearchRankingOptimizerWidget\SearchRankingOptimizerWidgetFactory getFactory()
 */
class SubmitRelevanceJudgmentController extends AbstractController
{
    use PermissionAwareTrait;

    /**
     * @var string
     */
    protected const PARAM_SEARCH_TERM = 'searchTerm';

    /**
     * @var string
     */
    protected const PARAM_ID_PRODUCT_ABSTRACT = 'idProductAbstract';

    /**
     * @var string
     */
    protected const PARAM_RATING_TYPE = 'ratingType';

    /**
     * @var string
     */
    protected const PARAM_CSRF_TOKEN = '_csrf_token';

    /**
     * Neither of this controller's actions is bound to a Symfony Form, so they carry none of the CSRF
     * protection every Form-backed POST in this project gets automatically -- without this, any page a
     * logged-in customer had open could silently submit or clear a fabricated judgment on their behalf via
     * a plain cross-origin POST (their session cookie travels automatically, `RateSearchRelevancePermissionPlugin`
     * alone does nothing to stop this since it only asks "is this customer allowed to rate", not "did THIS
     * customer actually click this button").
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    protected function isCsrfTokenValid(Request $request): bool
    {
        $token = (string)$request->request->get(static::PARAM_CSRF_TOKEN, '');

        return $this->getFactory()->getCsrfTokenManager()->isTokenValid(
            new CsrfToken(SearchRankingOptimizerWidgetTwigPlugin::CSRF_TOKEN_ID, $token),
        );
    }

    /**
     * UX-level gate only — the real, unbypassable check happens server-side in Zed's GatewayController,
     * which independently re-resolves the customer's permission rather than trusting anything asserted
     * here (see {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Authorization\CompanyUserPermissionAuthorizer}).
     * This check only avoids a pointless round-trip for a customer the widget should never have rendered
     * the buttons for in the first place.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function submitAction(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid($request)) {
            return $this->jsonResponse(['isSuccess' => false, 'errorMessage' => 'CSRF token is not valid.'], 403);
        }

        if (!$this->can(RateSearchRelevancePermissionPlugin::KEY)) {
            return $this->jsonResponse(['isSuccess' => false, 'errorMessage' => 'Not authorized.'], 403);
        }

        $customerTransfer = $this->getFactory()->getCustomerClient()->getCustomer();

        if ($customerTransfer === null || $customerTransfer->getCustomerReference() === null) {
            return $this->jsonResponse(['isSuccess' => false, 'errorMessage' => 'Not logged in.'], 403);
        }

        $requestTransfer = (new SearchRankingProductRelevanceJudgmentRequestTransfer())
            ->setSearchTerm((string)$request->request->get(static::PARAM_SEARCH_TERM, ''))
            ->setStoreName($this->getFactory()->getStoreClient()->getCurrentStore()->getNameOrFail())
            ->setLocaleName($this->getLocale())
            ->setIdProductAbstract((int)$request->request->get(static::PARAM_ID_PRODUCT_ABSTRACT, 0))
            ->setRatingType((string)$request->request->get(static::PARAM_RATING_TYPE, ''))
            ->setCustomerReference($customerTransfer->getCustomerReference());

        $responseTransfer = $this->getFactory()->getSearchRankingOptimizerClient()->submitProductRelevanceJudgment($requestTransfer);

        return $this->jsonResponse([
            'isSuccess' => $responseTransfer->getIsSuccess(),
            'errorMessage' => $responseTransfer->getErrorMessage(),
            'ratingType' => $responseTransfer->getRating()?->getRatingType(),
        ]);
    }

    /**
     * Backs the widget's "click an already-pressed button to unselect" affordance. Same permission gate
     * and CSRF check as {@see submitAction()} — clearing your own judgment needs the same protection
     * submitting one does.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function clearAction(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid($request)) {
            return $this->jsonResponse(['isSuccess' => false, 'errorMessage' => 'CSRF token is not valid.'], 403);
        }

        if (!$this->can(RateSearchRelevancePermissionPlugin::KEY)) {
            return $this->jsonResponse(['isSuccess' => false, 'errorMessage' => 'Not authorized.'], 403);
        }

        $customerTransfer = $this->getFactory()->getCustomerClient()->getCustomer();

        if ($customerTransfer === null || $customerTransfer->getCustomerReference() === null) {
            return $this->jsonResponse(['isSuccess' => false, 'errorMessage' => 'Not logged in.'], 403);
        }

        $requestTransfer = (new SearchRankingProductRelevanceJudgmentRequestTransfer())
            ->setSearchTerm((string)$request->request->get(static::PARAM_SEARCH_TERM, ''))
            ->setStoreName($this->getFactory()->getStoreClient()->getCurrentStore()->getNameOrFail())
            ->setLocaleName($this->getLocale())
            ->setIdProductAbstract((int)$request->request->get(static::PARAM_ID_PRODUCT_ABSTRACT, 0))
            ->setCustomerReference($customerTransfer->getCustomerReference());

        $responseTransfer = $this->getFactory()->getSearchRankingOptimizerClient()->clearProductRelevanceJudgment($requestTransfer);

        return $this->jsonResponse([
            'isSuccess' => $responseTransfer->getIsSuccess(),
            'errorMessage' => $responseTransfer->getErrorMessage(),
        ]);
    }
}
