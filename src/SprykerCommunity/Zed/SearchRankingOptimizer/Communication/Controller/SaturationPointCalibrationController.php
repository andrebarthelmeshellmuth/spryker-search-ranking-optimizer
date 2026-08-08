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
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\SaturationPointCalibrationUploadForm;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface getFacade()
 */
class SaturationPointCalibrationController extends AbstractController
{
    /**
     * @var string
     */
    protected const URL_CALIBRATION = '/search-ranking-optimizer/saturation-point-calibration';

    /**
     * Public (not just this class's own internal picker) so {@see SaturationPointCalibrationApplyController}
     * can build a redirect back to the exact scope being viewed, using the same query param names.
     *
     * @var string
     */
    public const PARAM_STORE_NAME = 'storeName';

    /**
     * @var string
     */
    public const PARAM_LOCALE_NAME = 'localeName';

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|array<string, mixed>
     */
    public function indexAction(Request $request)
    {
        $storeName = $this->resolveStoreName($request);
        $localeName = $this->resolveLocaleName($request);

        $uploadForm = $this->getFactory()->createCalibrationUploadForm()->handleRequest($request);

        if ($uploadForm->isSubmitted() && $uploadForm->isValid()) {
            $uploadData = $uploadForm->getData();

            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $uploadedFile */
            $uploadedFile = $uploadForm->get(SaturationPointCalibrationUploadForm::FIELD_FILE)->getData();
            $useCsvUpload = (bool)$uploadData[SaturationPointCalibrationUploadForm::FIELD_USE_CSV_UPLOAD];

            $this->getFacade()->createCalibration(
                (string)$uploadData[SaturationPointCalibrationUploadForm::FIELD_CALIBRATION_TYPE],
                (int)$uploadData[SaturationPointCalibrationUploadForm::FIELD_RELEVANT_PRODUCT_COUNT],
                (string)$uploadData[SaturationPointCalibrationUploadForm::FIELD_STORE_NAME],
                (string)$uploadData[SaturationPointCalibrationUploadForm::FIELD_LOCALE_NAME],
                $useCsvUpload && $uploadedFile !== null ? $this->readUploadedFileContent($uploadedFile) : null,
            );

            $this->addSuccessMessage(
                'Calibration run uploaded — the next "search-ranking-optimizer:calibrate" cron tick will calculate it.',
            );

            // Stays on whichever scope was being VIEWED, not necessarily the one the upload form's own
            // (independent) store/locale pickers targeted — the two are allowed to differ, e.g. bootstrapping
            // AT while still reviewing DE's own latest run.
            return $this->redirectResponse($this->buildCalibrationUrl($storeName, $localeName));
        }

        $latestCalibrationTransfer = $this->getFacade()->findLatestCalculatedCalibration($storeName, $localeName);
        $inProgressCalibrationTransfer = $this->getFacade()->findCalibrationInProgress($storeName, $localeName);

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
            'stores' => $this->getFactory()->getAllStoreNames(),
            'locales' => $this->getFactory()->getAllLocaleNames(),
            'selectedStoreName' => $storeName,
            'selectedLocaleName' => $localeName,
            'currentRelevanceSaturationPoint' => $currentRelevanceSaturationPoint,
            'currentSpecificitySaturationPoint' => $currentSpecificitySaturationPoint,
            'latestCalibration' => $latestCalibrationTransfer,
            'inProgressCalibration' => $inProgressCalibrationTransfer,
            'uploadForm' => $uploadForm->createView(),
            'applyForm' => $applyForm,
        ]);
    }

    /**
     * Polled by the Saturation Point Calibration page's own JS while a run is in status=calculating for
     * the scope being viewed — deliberately tiny (id/status/counts only, no search terms) since this
     * fires roughly once a second for however long the run takes. Scoped the same way indexAction() is,
     * via the same query params, so a run for a DIFFERENT store/locale never shows up as if it were the
     * viewed scope's own progress.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function progressAction(Request $request): JsonResponse
    {
        $inProgressCalibrationTransfer = $this->getFacade()->findCalibrationInProgress(
            $this->resolveStoreName($request),
            $this->resolveLocaleName($request),
        );

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

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    protected function resolveStoreName(Request $request): string
    {
        return (string)$request->query->get(static::PARAM_STORE_NAME, '') ?: SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    protected function resolveLocaleName(Request $request): string
    {
        return (string)$request->query->get(static::PARAM_LOCALE_NAME, '') ?: SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME;
    }

    /**
     * @param string $storeName
     * @param string $localeName
     */
    protected function buildCalibrationUrl(string $storeName, string $localeName): string
    {
        return sprintf('%s?%s=%s&%s=%s', static::URL_CALIBRATION, static::PARAM_STORE_NAME, $storeName, static::PARAM_LOCALE_NAME, $localeName);
    }
}
