<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller;

use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Spryker\Zed\Kernel\Communication\Controller\AbstractController;
use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig as SharedSearchRankingConfig;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\AutoTuneMetricConfigForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface getFacade()
 */
class AutoTuneController extends AbstractController
{
    /**
     * @var string
     */
    protected const URL_AUTO_TUNE = '/search-ranking-optimizer/auto-tune';

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
        $randomMetricName = $searchRankingFacade->getRandomMetricName();
        $rows = [];

        foreach ($searchRankingFacade->getActiveMetrics($storeName, $localeName) as $metric) {
            // The random tie-breaker metric's formula is `random()` by design -- a fresh evaluation never
            // matches the last one, so its R² is meaningless noise, and there is nothing a refit could
            // sensibly converge on. Excluded here rather than from getActiveMetrics() itself, since this
            // page is the only place that reasoning applies.
            if ($metric['name'] === $randomMetricName) {
                continue;
            }

            $idSearchRankingMetric = $metric['idSearchRankingMetric'];
            $autoTuneMetricConfigTransfer = $this->getFacade()->findAutoTuneMetricConfigByMetricId($idSearchRankingMetric, $storeName, $localeName);

            $rows[] = [
                'metricName' => $metric['name'],
                'currentFitRSquared' => $searchRankingFacade->evaluateCurrentMetricFit(
                    $idSearchRankingMetric,
                    $storeName,
                    $localeName,
                ),
                'form' => $this->getFactory()->createAutoTuneMetricConfigForm(
                    $idSearchRankingMetric,
                    $autoTuneMetricConfigTransfer?->getAutoTuneThreshold(),
                    $autoTuneMetricConfigTransfer?->getIsAutoUpdateEnabled() ?? false,
                    $autoTuneMetricConfigTransfer?->getAutoUpdateScope() ?? SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE,
                    $autoTuneMetricConfigTransfer?->getIsNotifyEnabled() ?? false,
                )->createView(),
            ];
        }

        return $this->viewResponse([
            'rows' => $rows,
            'stores' => $this->getFactory()->getAllStoreNames(),
            'locales' => $this->getFactory()->getAllLocaleNames(),
            'selectedStoreName' => $storeName,
            'selectedLocaleName' => $localeName,
        ]);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function saveAction(Request $request): RedirectResponse
    {
        $storeName = $this->resolveStoreName($request);
        $localeName = $this->resolveLocaleName($request);
        $redirectUrl = $this->buildAutoTuneUrl($storeName, $localeName);

        $autoTuneMetricConfigForm = $this->getFactory()
            ->createAutoTuneMetricConfigForm(0, null, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, false)
            ->handleRequest($request);

        if (!$autoTuneMetricConfigForm->isSubmitted() || !$autoTuneMetricConfigForm->isValid()) {
            $this->addErrorMessage('The submitted auto-tune settings are invalid.');

            return $this->redirectResponse($redirectUrl);
        }

        $formData = $autoTuneMetricConfigForm->getData();

        $autoTuneMetricConfigTransfer = (new SearchRankingAutoTuneMetricConfigTransfer())
            ->setIdSearchRankingMetric((int)$formData[AutoTuneMetricConfigForm::FIELD_ID_SEARCH_RANKING_METRIC])
            ->setStoreName($storeName)
            ->setLocaleName($localeName)
            ->setAutoTuneThreshold($formData[AutoTuneMetricConfigForm::FIELD_AUTO_TUNE_THRESHOLD] !== null ? (float)$formData[AutoTuneMetricConfigForm::FIELD_AUTO_TUNE_THRESHOLD] : null)
            ->setIsAutoUpdateEnabled((bool)$formData[AutoTuneMetricConfigForm::FIELD_IS_AUTO_UPDATE_ENABLED])
            ->setAutoUpdateScope((string)$formData[AutoTuneMetricConfigForm::FIELD_AUTO_UPDATE_SCOPE])
            ->setIsNotifyEnabled((bool)$formData[AutoTuneMetricConfigForm::FIELD_IS_NOTIFY_ENABLED]);

        $this->getFacade()->saveAutoTuneMetricConfig($autoTuneMetricConfigTransfer);

        $this->addSuccessMessage('Auto-tune settings saved.');

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
    protected function buildAutoTuneUrl(string $storeName, string $localeName): string
    {
        return sprintf('%s?%s=%s&%s=%s', static::URL_AUTO_TUNE, static::PARAM_STORE_NAME, $storeName, static::PARAM_LOCALE_NAME, $localeName);
    }
}
