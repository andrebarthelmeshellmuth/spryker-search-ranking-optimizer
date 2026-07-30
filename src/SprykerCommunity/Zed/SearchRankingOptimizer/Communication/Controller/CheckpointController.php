<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller;

use Spryker\Zed\Kernel\Communication\Controller\AbstractController;
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
     * @return array<string, mixed>
     */
    public function indexAction(): array
    {
        $searchRankingFacade = $this->getFactory()->getSearchRankingFacade();
        $weightCheckpointHistory = $this->getFacade()->findWeightCheckpointHistory();

        $restoreForms = [];
        foreach ($weightCheckpointHistory as $weightCheckpointTransfer) {
            $idSearchRankingWeightCheckpoint = $weightCheckpointTransfer->getIdSearchRankingWeightCheckpointOrFail();
            $restoreForms[$idSearchRankingWeightCheckpoint] = $this->getFactory()
                ->createRestoreWeightCheckpointForm($idSearchRankingWeightCheckpoint)
                ->createView();
        }

        return $this->viewResponse([
            'recordForm' => $this->getFactory()->createRecordWeightCheckpointForm()->createView(),
            'relevanceWeight' => $searchRankingFacade->getRelevanceWeight(),
            'entropyProbeResultSize' => $searchRankingFacade->getEntropyProbeResultSize(),
            'entropyWeightExponent' => $searchRankingFacade->getEntropyWeightExponent(),
            'entropyWeightShiftMagnitude' => $searchRankingFacade->getEntropyWeightShiftMagnitude(),
            'isEntropyWeightingEnabled' => $searchRankingFacade->isEntropyWeightingEnabled(),
            'metricWeights' => $searchRankingFacade->getMetricWeights(),
            'weightCheckpointHistory' => $weightCheckpointHistory,
            'restoreForms' => $restoreForms,
        ]);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function recordAction(Request $request): RedirectResponse
    {
        $recordForm = $this->getFactory()->createRecordWeightCheckpointForm()->handleRequest($request);

        if (!$recordForm->isSubmitted() || !$recordForm->isValid()) {
            $this->addErrorMessage('CSRF token is not valid.');

            return $this->redirectResponse(static::URL_CHECKPOINT);
        }

        $weightCheckpointTransfer = $this->getFacade()->recordWeightCheckpoint(SearchRankingOptimizerConfig::CHECKPOINT_SOURCE_MANUAL);

        $this->addSuccessMessage(sprintf(
            'Checkpoint #%d recorded.',
            $weightCheckpointTransfer->getIdSearchRankingWeightCheckpointOrFail(),
        ));

        return $this->redirectResponse(static::URL_CHECKPOINT);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function restoreAction(Request $request): RedirectResponse
    {
        $restoreForm = $this->getFactory()->createRestoreWeightCheckpointForm(0)->handleRequest($request);

        if (!$restoreForm->isSubmitted() || !$restoreForm->isValid()) {
            $this->addErrorMessage('CSRF token is not valid.');

            return $this->redirectResponse(static::URL_CHECKPOINT);
        }

        $idSearchRankingWeightCheckpoint = (int)$restoreForm->getData()[RestoreWeightCheckpointForm::FIELD_ID_SEARCH_RANKING_WEIGHT_CHECKPOINT];
        $newWeightCheckpointTransfer = $this->getFacade()->restoreWeightCheckpoint($idSearchRankingWeightCheckpoint);

        if ($newWeightCheckpointTransfer === null) {
            $this->addErrorMessage(sprintf('Checkpoint #%d no longer exists.', $idSearchRankingWeightCheckpoint));
        } else {
            // Without this, the restore only ever reaches search-ranking's own Zed-side settings — the
            // live storefront reads the SYNCED key-value copy, which a plain facade save never touches
            // (same discipline CalibrationApplyController already follows for its own apply action).
            $this->getFactory()->getSearchRankingStorageFacade()->publishRankingConfiguration();

            $this->addSuccessMessage(sprintf(
                'Restored checkpoint #%d — recorded as new checkpoint #%d.',
                $idSearchRankingWeightCheckpoint,
                $newWeightCheckpointTransfer->getIdSearchRankingWeightCheckpointOrFail(),
            ));
        }

        return $this->redirectResponse(static::URL_CHECKPOINT);
    }
}
