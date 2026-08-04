<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller;

use Spryker\Zed\Kernel\Communication\Controller\AbstractController;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\AutomatedWeightOptimizationApplyForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface getFacade()
 */
class AutomatedWeightOptimizationApplyController extends AbstractController
{
    /**
     * @var string
     */
    protected const URL_OPTIMIZATION = '/search-ranking-optimizer/automated-weight-optimization';

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function indexAction(Request $request): RedirectResponse
    {
        $applyForm = $this->getFactory()->createOptimizationApplyForm(0)->handleRequest($request);

        if (!$applyForm->isSubmitted() || !$applyForm->isValid()) {
            $this->addErrorMessage('CSRF token is not valid.');

            return $this->redirectResponse(static::URL_OPTIMIZATION);
        }

        $idSearchRankingOptimizerRun = (int)$applyForm->getData()[AutomatedWeightOptimizationApplyForm::FIELD_ID_SEARCH_RANKING_OPTIMIZER_RUN];
        $optimizerRunTransfer = $this->getFacade()->applyOptimizationRun($idSearchRankingOptimizerRun);

        if ($optimizerRunTransfer === null) {
            $this->addErrorMessage(sprintf(
                'Could not apply run #%d — it no longer exists, isn\'t finished yet, or references a metric that has since been deleted (nothing was changed).',
                $idSearchRankingOptimizerRun,
            ));

            return $this->redirectResponse(static::URL_OPTIMIZATION);
        }

        // Without this, the applied values only ever reach search-ranking's own Zed-side settings — the
        // live storefront reads the SYNCED key-value copy, which a plain facade save never touches (same
        // discipline SaturationPointCalibrationApplyController and CheckpointController::restoreAction() both follow).
        $this->getFactory()->getSearchRankingStorageFacade()->publishRankingConfiguration();

        $this->addSuccessMessage(sprintf(
            'Run #%d applied — relevanceWeight and every active metric weight were updated and republished.',
            $idSearchRankingOptimizerRun,
        ));

        return $this->redirectResponse(sprintf(
            '%s?storeName=%s&localeName=%s',
            static::URL_OPTIMIZATION,
            $optimizerRunTransfer->getStoreNameOrFail(),
            $optimizerRunTransfer->getLocaleNameOrFail(),
        ));
    }
}
