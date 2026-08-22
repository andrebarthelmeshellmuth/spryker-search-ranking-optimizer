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
     * @var string
     */
    protected const PARAM_HISTORY_STORE_NAME = 'historyStoreName';

    /**
     * @var string
     */
    protected const PARAM_HISTORY_LOCALE_NAME = 'historyLocaleName';

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return array<string, mixed>
     */
    public function indexAction(Request $request): array
    {
        $storeName = $this->resolveStoreName($request);
        $localeName = $this->resolveLocaleName($request);
        $historyStoreName = $this->resolveHistoryStoreName($request);
        $historyLocaleName = $this->resolveHistoryLocaleName($request);
        $searchRankingFacade = $this->getFactory()->getSearchRankingFacade();
        // Independent of $storeName/$localeName above -- History is a cross-scope audit trail (unfiltered
        // by default), not tied to whichever scope "Current State" and "Restore" currently target.
        $weightCheckpointHistory = $this->getFacade()->findWeightCheckpointHistory($historyStoreName, $historyLocaleName);

        $restoreForms = [];
        $metricNameSet = [];
        $metricWeightsByCheckpointId = [];
        $restoreSiblingLocalesByCheckpointId = [];
        $effectiveWeightLocalesCache = [];

        foreach ($weightCheckpointHistory as $weightCheckpointTransfer) {
            $idSearchRankingWeightCheckpoint = $weightCheckpointTransfer->getIdSearchRankingWeightCheckpointOrFail();
            $restoreForms[$idSearchRankingWeightCheckpoint] = $this->getFactory()
                ->createRestoreWeightCheckpointForm($idSearchRankingWeightCheckpoint, $storeName, $localeName)
                ->createView();

            foreach ($weightCheckpointTransfer->getMetricWeights() as $metricWeightTransfer) {
                $metricName = $metricWeightTransfer->getNameOrFail();
                $metricNameSet[$metricName] = true;
                $metricWeightsByCheckpointId[$idSearchRankingWeightCheckpoint][$metricName] = $metricWeightTransfer->getWeightOrFail();

                // Restoring THIS row writes its weight into $storeName/$localeName -- the page's currently
                // selected target scope, not necessarily this checkpoint's own original store/locale (a
                // checkpoint can be restored as a template into any scope). So the real blast radius has
                // to be resolved against the TARGET scope: if the metric is store-wide there, restoring
                // fans this weight out to every other real locale of $storeName too, silently overwriting
                // whatever those locales currently hold for it. Cached per (metric, store) -- every row on
                // this page restores into the SAME target scope, so this is at most one facade call per
                // distinct metric, however many checkpoints are in history.
                $idSearchRankingMetric = $metricWeightTransfer->getIdSearchRankingMetricOrFail();
                $cacheKey = $idSearchRankingMetric . ':' . $storeName;

                if (!array_key_exists($cacheKey, $effectiveWeightLocalesCache)) {
                    $effectiveWeightLocalesCache[$cacheKey] = $searchRankingFacade->resolveEffectiveWeightLocales($idSearchRankingMetric, $storeName, $localeName);
                }

                $siblingLocales = array_values(array_diff($effectiveWeightLocalesCache[$cacheKey], [$localeName]));

                if ($siblingLocales === []) {
                    continue;
                }

                $restoreSiblingLocalesByCheckpointId[$idSearchRankingWeightCheckpoint][$metricName] = $siblingLocales;
            }
        }

        $metricNames = array_keys($metricNameSet);
        sort($metricNames);

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
            'specificityCurveExponent' => $searchRankingFacade->getSpecificityCurveExponent($storeName, $localeName),
            'specificityWeightExponent' => $searchRankingFacade->getSpecificityWeightExponent($storeName, $localeName),
            'specificityWeightShiftMagnitude' => $searchRankingFacade->getSpecificityWeightShiftMagnitude($storeName, $localeName),
            'isSpecificityWeightingEnabled' => $searchRankingFacade->isSpecificityWeightingEnabled(),
            'metricWeights' => $searchRankingFacade->getMetricWeights($storeName, $localeName),
            'weightCheckpointHistory' => $weightCheckpointHistory,
            'restoreForms' => $restoreForms,
            'metricNames' => $metricNames,
            'metricWeightsByCheckpointId' => $metricWeightsByCheckpointId,
            'restoreSiblingLocalesByCheckpointId' => $restoreSiblingLocalesByCheckpointId,
            'stores' => $this->getFactory()->getAllStoreNames(),
            'locales' => $this->getFactory()->getAllLocaleNames(),
            'selectedStoreName' => $storeName,
            'selectedLocaleName' => $localeName,
            'selectedHistoryStoreName' => $historyStoreName,
            'selectedHistoryLocaleName' => $historyLocaleName,
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
            $this->addErrorMessage(sprintf(
                'Could not restore checkpoint #%d — it no longer exists, or references a metric that has since been deleted (nothing was changed).',
                $idSearchRankingWeightCheckpoint,
            ));
        } else {
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
     * Null (not a default scope) means "no filter" — unlike {@see resolveStoreName()} above (which picks
     * the "Current State"/Restore-target scope and always falls back to a default), the History table is
     * a cross-scope audit trail, so an unset/blank query param means "show every store".
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    protected function resolveHistoryStoreName(Request $request): ?string
    {
        $historyStoreName = (string)$request->query->get(static::PARAM_HISTORY_STORE_NAME, '');

        return $historyStoreName === '' ? null : $historyStoreName;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    protected function resolveHistoryLocaleName(Request $request): ?string
    {
        $historyLocaleName = (string)$request->query->get(static::PARAM_HISTORY_LOCALE_NAME, '');

        return $historyLocaleName === '' ? null : $historyLocaleName;
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
