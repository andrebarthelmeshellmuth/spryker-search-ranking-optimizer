<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller;

use Spryker\Zed\Kernel\Communication\Controller\AbstractController;
use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig as SharedSearchRankingConfig;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\SaturationPointCalibrationApplyForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 */
class SaturationPointCalibrationApplyController extends AbstractController
{
    /**
     * @var string
     */
    protected const URL_CALIBRATION = '/search-ranking-optimizer/saturation-point-calibration';

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function indexAction(Request $request): RedirectResponse
    {
        $applyForm = $this->getFactory()
            ->createCalibrationApplyForm(
                0.0,
                SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME,
                SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME,
            )
            ->handleRequest($request);

        if (!$applyForm->isSubmitted() || !$applyForm->isValid()) {
            $this->addErrorMessage('CSRF token is not valid.');

            return $this->redirectResponse(static::URL_CALIBRATION);
        }

        $applyData = $applyForm->getData();
        $saturationPointValue = (float)$applyData[SaturationPointCalibrationApplyForm::FIELD_RELEVANCE_SATURATION_POINT];
        $calibrationType = (string)$applyData[SaturationPointCalibrationApplyForm::FIELD_CALIBRATION_TYPE];
        $storeName = (string)$applyData[SaturationPointCalibrationApplyForm::FIELD_STORE_NAME];
        $localeName = (string)$applyData[SaturationPointCalibrationApplyForm::FIELD_LOCALE_NAME];

        if ($calibrationType === SearchRankingOptimizerConfig::CALIBRATION_TYPE_SPECIFICITY) {
            $this->getFactory()->getSearchRankingFacade()->saveSpecificitySaturationPoint($storeName, $localeName, $saturationPointValue);
            $this->addSuccessMessage('Specificity saturation point (k) was updated.');
        } else {
            $this->getFactory()->getSearchRankingFacade()->saveRelevanceSaturationPoint($storeName, $localeName, $saturationPointValue);
            $this->addSuccessMessage('Relevance saturation point (k) was updated.');
        }

        // Back to the exact scope this calibration was for — not the bare URL, which would silently reset
        // the view to the hardcoded default store/locale regardless of what was actually just applied.
        return $this->redirectResponse(sprintf(
            '%s?%s=%s&%s=%s',
            static::URL_CALIBRATION,
            SaturationPointCalibrationController::PARAM_STORE_NAME,
            $storeName,
            SaturationPointCalibrationController::PARAM_LOCALE_NAME,
            $localeName,
        ));
    }
}
