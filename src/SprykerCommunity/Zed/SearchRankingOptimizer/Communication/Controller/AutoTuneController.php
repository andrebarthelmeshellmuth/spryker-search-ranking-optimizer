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
     * @return array<string, mixed>
     */
    public function indexAction(): array
    {
        $searchRankingFacade = $this->getFactory()->getSearchRankingFacade();
        $randomMetricName = $searchRankingFacade->getRandomMetricName();
        $rows = [];

        foreach ($searchRankingFacade->getActiveMetrics() as $metric) {
            // The random tie-breaker metric's formula is `random()` by design -- a fresh evaluation never
            // matches the last one, so its R² is meaningless noise, and there is nothing a refit could
            // sensibly converge on. Excluded here rather than from getActiveMetrics() itself, since this
            // page is the only place that reasoning applies.
            if ($metric['name'] === $randomMetricName) {
                continue;
            }

            $idSearchRankingMetric = $metric['idSearchRankingMetric'];
            $autoTuneMetricConfigTransfer = $this->getFacade()->findAutoTuneMetricConfigByMetricId($idSearchRankingMetric);

            $rows[] = [
                'metricName' => $metric['name'],
                'currentFitRSquared' => $searchRankingFacade->evaluateCurrentMetricFit(
                    $idSearchRankingMetric,
                    SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME,
                    SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME,
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
        ]);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function saveAction(Request $request): RedirectResponse
    {
        $autoTuneMetricConfigForm = $this->getFactory()
            ->createAutoTuneMetricConfigForm(0, null, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, false)
            ->handleRequest($request);

        if (!$autoTuneMetricConfigForm->isSubmitted() || !$autoTuneMetricConfigForm->isValid()) {
            $this->addErrorMessage('The submitted auto-tune settings are invalid.');

            return $this->redirectResponse(static::URL_AUTO_TUNE);
        }

        $formData = $autoTuneMetricConfigForm->getData();

        $autoTuneMetricConfigTransfer = (new SearchRankingAutoTuneMetricConfigTransfer())
            ->setIdSearchRankingMetric((int)$formData[AutoTuneMetricConfigForm::FIELD_ID_SEARCH_RANKING_METRIC])
            ->setAutoTuneThreshold($formData[AutoTuneMetricConfigForm::FIELD_AUTO_TUNE_THRESHOLD] !== null ? (float)$formData[AutoTuneMetricConfigForm::FIELD_AUTO_TUNE_THRESHOLD] : null)
            ->setIsAutoUpdateEnabled((bool)$formData[AutoTuneMetricConfigForm::FIELD_IS_AUTO_UPDATE_ENABLED])
            ->setAutoUpdateScope((string)$formData[AutoTuneMetricConfigForm::FIELD_AUTO_UPDATE_SCOPE])
            ->setIsNotifyEnabled((bool)$formData[AutoTuneMetricConfigForm::FIELD_IS_NOTIFY_ENABLED]);

        $this->getFacade()->saveAutoTuneMetricConfig($autoTuneMetricConfigTransfer);

        $this->addSuccessMessage('Auto-tune settings saved.');

        return $this->redirectResponse(static::URL_AUTO_TUNE);
    }
}
