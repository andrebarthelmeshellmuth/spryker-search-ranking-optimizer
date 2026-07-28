<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller;

use Spryker\Zed\Kernel\Communication\Controller\AbstractController;
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

            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile $uploadedFile */
            $uploadedFile = $uploadForm->get(CalibrationUploadForm::FIELD_FILE)->getData();

            $this->getFacade()->createCalibration(
                (int)$uploadData[CalibrationUploadForm::FIELD_RELEVANT_PRODUCT_COUNT],
                (string)$uploadData[CalibrationUploadForm::FIELD_STORE_NAME],
                (string)$uploadData[CalibrationUploadForm::FIELD_LOCALE_NAME],
                $this->readUploadedFileContent($uploadedFile),
            );

            $this->addSuccessMessage(
                'Calibration run uploaded — the next "search-ranking-optimizer:calibrate" cron tick will calculate it.',
            );

            return $this->redirectResponse(static::URL_CALIBRATION);
        }

        $latestCalibrationTransfer = $this->getFacade()->findLatestCalculatedCalibration();
        $currentRelevanceSaturationPoint = $this->getFactory()->getSearchRankingFacade()->getRelevanceSaturationPoint();

        $applyForm = $this->getFactory()
            ->createCalibrationApplyForm($latestCalibrationTransfer?->getComputedK() ?? $currentRelevanceSaturationPoint)
            ->createView();

        return $this->viewResponse([
            'currentRelevanceSaturationPoint' => $currentRelevanceSaturationPoint,
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
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
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
     *
     * @return string
     */
    protected function readUploadedFileContent(UploadedFile $uploadedFile): string
    {
        return (string)file_get_contents($uploadedFile->getPathname());
    }
}
