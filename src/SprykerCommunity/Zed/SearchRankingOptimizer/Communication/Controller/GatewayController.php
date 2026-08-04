<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller;

use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchResponseTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer;
use Spryker\Zed\Kernel\Communication\Controller\AbstractGatewayController;
use SprykerCommunity\Shared\SearchRankingOptimizer\Plugin\RateSearchRelevancePermissionPlugin;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\InvalidRatingTypeException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\ProductNotInSearchResultsException;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface getFacade()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 */
class GatewayController extends AbstractGatewayController
{
    /**
     * The single authorization gate for this write, independently re-checked here rather than trusted
     * from the Yves side alone — same posture as search-debug's SearchDebugAccessChecker, moved to the
     * correct layer for a write-from-Yves case (Zed has the only trustworthy source of "does this customer
     * actually hold this permission", Yves only has its own session-bound, same-layer belief about it).
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     */
    public function submitProductRelevanceJudgmentAction(
        SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer,
    ): SearchRankingProductRelevanceJudgmentResponseTransfer {
        $isAuthorized = $this->getFactory()
            ->createCompanyUserPermissionAuthorizer()
            ->isAuthorized($requestTransfer->getCustomerReferenceOrFail(), RateSearchRelevancePermissionPlugin::KEY);

        if (!$isAuthorized) {
            return (new SearchRankingProductRelevanceJudgmentResponseTransfer())
                ->setIsSuccess(false)
                ->setErrorMessage('Not authorized to rate search relevance.');
        }

        try {
            $ratingTransfer = $this->getFacade()->submitProductRelevanceJudgment($requestTransfer);

            return (new SearchRankingProductRelevanceJudgmentResponseTransfer())
                ->setIsSuccess(true)
                ->setRating($ratingTransfer);
        } catch (InvalidRatingTypeException | ProductNotInSearchResultsException $exception) {
            return (new SearchRankingProductRelevanceJudgmentResponseTransfer())
                ->setIsSuccess(false)
                ->setErrorMessage($exception->getMessage());
        }
    }

    /**
     * Backs the widget's "click an already-pressed button to unselect" affordance. Same authorization
     * posture as {@see submitProductRelevanceJudgmentAction()} — clearing your own judgment needs the
     * same permission submitting one does, re-checked here rather than trusted from Yves.
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     */
    public function clearProductRelevanceJudgmentAction(
        SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer,
    ): SearchRankingProductRelevanceJudgmentResponseTransfer {
        $isAuthorized = $this->getFactory()
            ->createCompanyUserPermissionAuthorizer()
            ->isAuthorized($requestTransfer->getCustomerReferenceOrFail(), RateSearchRelevancePermissionPlugin::KEY);

        if (!$isAuthorized) {
            return (new SearchRankingProductRelevanceJudgmentResponseTransfer())
                ->setIsSuccess(false)
                ->setErrorMessage('Not authorized to rate search relevance.');
        }

        $this->getFacade()->clearProductRelevanceJudgment($requestTransfer);

        return (new SearchRankingProductRelevanceJudgmentResponseTransfer())->setIsSuccess(true);
    }

    /**
     * Backs the widget's "show me what I already rated" pre-fill on page load. Same authorization posture
     * as {@see submitProductRelevanceJudgmentAction()} — seeing your own past ratings needs the same
     * permission submitting one does, re-checked here rather than trusted from Yves.
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer $requestTransfer
     */
    public function getProductRelevanceJudgmentsAction(
        SearchRankingProductRelevanceJudgmentBatchRequestTransfer $requestTransfer,
    ): SearchRankingProductRelevanceJudgmentBatchResponseTransfer {
        $isAuthorized = $this->getFactory()
            ->createCompanyUserPermissionAuthorizer()
            ->isAuthorized($requestTransfer->getCustomerReferenceOrFail(), RateSearchRelevancePermissionPlugin::KEY);

        if (!$isAuthorized) {
            return (new SearchRankingProductRelevanceJudgmentBatchResponseTransfer())
                ->setIsSuccess(false)
                ->setErrorMessage('Not authorized to rate search relevance.');
        }

        return $this->getFacade()->getProductRelevanceJudgments($requestTransfer);
    }
}
