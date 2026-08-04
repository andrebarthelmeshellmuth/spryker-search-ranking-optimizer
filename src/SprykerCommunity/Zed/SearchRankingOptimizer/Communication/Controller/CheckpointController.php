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
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\RestoreWeightCheckpointForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface getFacade()
 */
class CheckpointController extends AbstractController
{
    /**
     * @var string
     */
    protected const URL_CHECKPOINT = '/search-ranking-optimizer/checkpoint';

    /**
     * @var string
     */
    protected const PARAM_STORE_NAME = 'storeName';

    /**
     * @var string
     */
    protected const PARAM_LOCALE_NAME = 'localeName';

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return array<string, mixed>
     */
    public function indexAction(Request $request): array
    {
        $storeName = $this->resolveStoreName($request);
        $localeName = $this->resolveLocaleName($request);
        $searchRankingFacade = $this->getFactory()->getSearchRankingFacade();
        $weightCheckpointHistory = $this->getFacade()->findWeightCheckpointHistory();

        $restoreForms = [];
        foreach ($weightCheckpointHistory as $weightCheckpointTransfer) {
            $idSearchRankingWeightCheckpoint = $weightCheckpointTransfer->getIdSearchRankingWeightCheckpointOrFail();
            $restoreForms[$idSearchRankingWeightCheckpoint] = $this->getFactory()
                ->createRestoreWeightCheckpointForm($idSearchRankingWeightCheckpoint, $storeName, $localeName)
                ->createView();
        }

        return $this->viewResponse([
            'recordForm' => $this->getFactory()->createRecordWeightCheckpointForm()->createView(),
            'recordFormAction' => sprintf(
                '/search-ranking-optimizer/checkpoint/record?%s=%s&%s=%s',
                static::PARAM_STORE_NAME,
                $storeName,
                static::PARAM_LOCALE_NAME,
                $localeName,
            ),
            'relevanceWeight' => $searchRankingFacade->getRelevanceWeight($storeName, $localeName),
            'specificityBlendWeight' => $searchRankingFacade->getSpecificityBlendWeight($storeName, $localeName),
            'specificityWeightExponent' => $searchRankingFacade->getSpecificityWeightExponent($storeName, $localeName),
            'specificityWeightShiftMagnitude' => $searchRankingFacade->getSpecificityWeightShiftMagnitude($storeName, $localeName),
            'isSpecificityWeightingEnabled' => $searchRankingFacade->isSpecificityWeightingEnabled(),
            'metricWeights' => $searchRankingFacade->getMetricWeights($storeName, $localeName),
            'weightCheckpointHistory' => $weightCheckpointHistory,
            'restoreForms' => $restoreForms,
            'stores' => $this->getFactory()->getAllStoreNames(),
            'locales' => $this->getFactory()->getAllLocaleNames(),
            'selectedStoreName' => $storeName,
            'selectedLocaleName' => $localeName,
        ]);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function recordAction(Request $request): RedirectResponse
    {
        $storeName = $this->resolveStoreName($request);
        $localeName = $this->resolveLocaleName($request);
        $redirectUrl = $this->buildCheckpointUrl($storeName, $localeName);

        $recordForm = $this->getFactory()->createRecordWeightCheckpointForm()->handleRequest($request);

        if (!$recordForm->isSubmitted() || !$recordForm->isValid()) {
            $this->addErrorMessage('CSRF token is not valid.');

            return $this->redirectResponse($redirectUrl);
        }

        $weightCheckpointTransfer = $this->getFacade()->recordWeightCheckpoint(
            SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_MANUAL,
            $storeName,
            $localeName,
        );

        $this->addSuccessMessage(sprintf(
            'Checkpoint #%d recorded.',
            $weightCheckpointTransfer->getIdSearchRankingWeightCheckpointOrFail(),
        ));

        return $this->redirectResponse($redirectUrl);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function restoreAction(Request $request): RedirectResponse
    {
        $restoreForm = $this->getFactory()
            ->createRestoreWeightCheckpointForm(0, $this->resolveStoreName($request), $this->resolveLocaleName($request))
            ->handleRequest($request);

        if (!$restoreForm->isSubmitted() || !$restoreForm->isValid()) {
            $this->addErrorMessage('CSRF token is not valid.');

            return $this->redirectResponse(static::URL_CHECKPOINT);
        }

        $restoreFormData = $restoreForm->getData();
        $idSearchRankingWeightCheckpoint = (int)$restoreFormData[RestoreWeightCheckpointForm::FIELD_ID_SEARCH_RANKING_WEIGHT_CHECKPOINT];
        $storeName = (string)$restoreFormData[RestoreWeightCheckpointForm::FIELD_STORE_NAME];
        $localeName = (string)$restoreFormData[RestoreWeightCheckpointForm::FIELD_LOCALE_NAME];
        $redirectUrl = $this->buildCheckpointUrl($storeName, $localeName);

        $newWeightCheckpointTransfer = $this->getFacade()->restoreWeightCheckpoint($idSearchRankingWeightCheckpoint, $storeName, $localeName);

        if ($newWeightCheckpointTransfer === null) {
            $this->addErrorMessage(sprintf('Checkpoint #%d no longer exists.', $idSearchRankingWeightCheckpoint));
        } else {
            // Without this, the restore only ever reaches search-ranking's own Zed-side settings — the
            // live storefront reads the SYNCED key-value copy, which a plain facade save never touches
            // (same discipline SaturationPointCalibrationApplyController already follows for its own apply action).
            $this->getFactory()->getSearchRankingStorageFacade()->publishRankingConfiguration();

            $this->addSuccessMessage(sprintf(
                'Restored checkpoint #%d into %s/%s — recorded as new checkpoint #%d.',
                $idSearchRankingWeightCheckpoint,
                $storeName,
                $localeName,
                $newWeightCheckpointTransfer->getIdSearchRankingWeightCheckpointOrFail(),
            ));
        }

        return $this->redirectResponse($redirectUrl);
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
    protected function buildCheckpointUrl(string $storeName, string $localeName): string
    {
        return sprintf('%s?%s=%s&%s=%s', static::URL_CHECKPOINT, static::PARAM_STORE_NAME, $storeName, static::PARAM_LOCALE_NAME, $localeName);
    }
}
