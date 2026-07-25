<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller;

use Spryker\Zed\Kernel\Communication\Controller\AbstractController;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\CalibrationApplyForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 */
class CalibrationApplyController extends AbstractController
{
    /**
     * @var string
     */
    protected const URL_CALIBRATION = '/search-ranking-optimizer/calibration';

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function indexAction(Request $request): RedirectResponse
    {
        $applyForm = $this->getFactory()->createCalibrationApplyForm(0.0)->handleRequest($request);

        if (!$applyForm->isSubmitted() || !$applyForm->isValid()) {
            $this->addErrorMessage('CSRF token is not valid.');

            return $this->redirectResponse(static::URL_CALIBRATION);
        }

        $applyData = $applyForm->getData();

        $this->getFactory()->getSearchRankingFacade()->saveRelevanceSaturationPoint(
            (float)$applyData[CalibrationApplyForm::FIELD_RELEVANCE_SATURATION_POINT],
        );
        $this->getFactory()->getSearchRankingStorageFacade()->publishRankingConfiguration();

        $this->addSuccessMessage('Relevance saturation point (k) was updated.');

        return $this->redirectResponse(static::URL_CALIBRATION);
    }
}
