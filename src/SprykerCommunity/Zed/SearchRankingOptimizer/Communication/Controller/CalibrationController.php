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
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\CalibrationUploadForm;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface getFacade()
 */
class CalibrationController extends AbstractController
{
    /**
     * @var string
     */
    protected const URL_CALIBRATION = '/search-ranking-optimizer/calibration';

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|array<string, mixed>
     */
    public function indexAction(Request $request)
    {
        $uploadForm = $this->getFactory()->createCalibrationUploadForm()->handleRequest($request);

        if ($uploadForm->isSubmitted() && $uploadForm->isValid()) {
            $uploadData = $uploadForm->getData();

            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $uploadedFile */
            $uploadedFile = $uploadForm->get(CalibrationUploadForm::FIELD_FILE)->getData();
            $useCsvUpload = (bool)$uploadData[CalibrationUploadForm::FIELD_USE_CSV_UPLOAD];

            $this->getFacade()->createCalibration(
                (string)$uploadData[CalibrationUploadForm::FIELD_CALIBRATION_TYPE],
                (int)$uploadData[CalibrationUploadForm::FIELD_RELEVANT_PRODUCT_COUNT],
                (string)$uploadData[CalibrationUploadForm::FIELD_STORE_NAME],
                (string)$uploadData[CalibrationUploadForm::FIELD_LOCALE_NAME],
                $useCsvUpload && $uploadedFile !== null ? $this->readUploadedFileContent($uploadedFile) : null,
            );

            $this->addSuccessMessage(
                'Calibration run uploaded — the next "search-ranking-optimizer:calibrate" cron tick will calculate it.',
            );

            return $this->redirectResponse(static::URL_CALIBRATION);
        }

        $latestCalibrationTransfer = $this->getFacade()->findLatestCalculatedCalibration();
        // The calibration this page is about to offer applying (if any) is what determines which
        // store+locale the saturation point actually gets written to — never the hardcoded default,
        // since Zed has no implicit current store to fall back on.
        $storeName = $latestCalibrationTransfer?->getStoreName() ?? SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME;
        $localeName = $latestCalibrationTransfer?->getLocaleName() ?? SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME;
        $currentRelevanceSaturationPoint = $this->getFactory()->getSearchRankingFacade()->getRelevanceSaturationPoint($storeName, $localeName);
        $currentSpecificitySaturationPoint = $this->getFactory()->getSearchRankingFacade()->getSpecificitySaturationPoint($storeName, $localeName);

        $latestCalibrationType = $latestCalibrationTransfer?->getCalibrationType() ?? SearchRankingOptimizerConfig::CALIBRATION_TYPE_RELEVANCE_SCORE;
        $currentSaturationPointForLatestType = $latestCalibrationType === SearchRankingOptimizerConfig::CALIBRATION_TYPE_SPECIFICITY
            ? $currentSpecificitySaturationPoint
            : $currentRelevanceSaturationPoint;

        $applyForm = $this->getFactory()
            ->createCalibrationApplyForm(
                $latestCalibrationTransfer?->getComputedK() ?? $currentSaturationPointForLatestType,
                $storeName,
                $localeName,
                $latestCalibrationType,
            )
            ->createView();

        return $this->viewResponse([
            'currentRelevanceSaturationPoint' => $currentRelevanceSaturationPoint,
            'currentSpecificitySaturationPoint' => $currentSpecificitySaturationPoint,
            'latestCalibration' => $latestCalibrationTransfer,
            'inProgressCalibration' => $this->getFacade()->findCalibrationInProgress(),
            'uploadForm' => $uploadForm->createView(),
            'applyForm' => $applyForm,
        ]);
    }

    /**
     * Polled by the Calibration page's own JS while a run is in status=calculating — deliberately tiny
     * (id/status/counts only, no search terms) since this fires roughly once a second for however long
     * the run takes.
     */
    public function progressAction(): JsonResponse
    {
        $inProgressCalibrationTransfer = $this->getFacade()->findCalibrationInProgress();

        if ($inProgressCalibrationTransfer === null) {
            return $this->jsonResponse([
                'status' => null,
            ]);
        }

        return $this->jsonResponse([
            'status' => $inProgressCalibrationTransfer->getStatus(),
            'processedCount' => $inProgressCalibrationTransfer->getProcessedCount(),
            'totalCount' => $inProgressCalibrationTransfer->getTotalCount(),
        ]);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\File\UploadedFile $uploadedFile
     */
    protected function readUploadedFileContent(UploadedFile $uploadedFile): string
    {
        return (string)file_get_contents($uploadedFile->getPathname());
    }
}
