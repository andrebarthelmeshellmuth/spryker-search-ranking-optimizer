<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller;

use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer;
use Spryker\Zed\Kernel\Communication\Controller\AbstractController;
use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig as SharedSearchRankingConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\AutomatedWeightOptimizationRunForm;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface getFacade()
 */
class AutomatedWeightOptimizationController extends AbstractController
{
    /**
     * @var string
     */
    protected const URL_OPTIMIZATION = '/search-ranking-optimizer/automated-weight-optimization';

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|array<string, mixed>
     */
    public function indexAction(Request $request)
    {
        $optimizeRunForm = $this->getFactory()->createOptimizeRunForm()->handleRequest($request);

        if ($optimizeRunForm->isSubmitted() && $optimizeRunForm->isValid()) {
            return $this->queueOptimizationRunFromFormData($optimizeRunForm->getData(), $request);
        }

        $storeName = (string)$request->query->get(AutomatedWeightOptimizationRunForm::FIELD_STORE_NAME, '');
        $localeName = (string)$request->query->get(AutomatedWeightOptimizationRunForm::FIELD_LOCALE_NAME, '');
        $latestOptimizerRunTransfer = $storeName !== '' && $localeName !== ''
            ? $this->getFacade()->findLatestOptimizerRunByStoreLocale($storeName, $localeName)
            : null;
        $currentConfigurationStoreName = $storeName !== '' ? $storeName : SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME;
        $currentConfigurationLocaleName = $localeName !== '' ? $localeName : SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME;
        $applySiblingLocalesByMetric = $this->resolveApplySiblingLocalesByMetric($latestOptimizerRunTransfer, $currentConfigurationStoreName, $currentConfigurationLocaleName);

        return $this->viewResponse([
            'optimizeRunForm' => $optimizeRunForm->createView(),
            'storeName' => $storeName,
            'localeName' => $localeName,
            'currentRelevanceWeight' => $this->getFactory()->getSearchRankingFacade()->getRelevanceWeight($currentConfigurationStoreName, $currentConfigurationLocaleName),
            'currentSpecificityCurveExponent' => $this->getFactory()->getSearchRankingFacade()->getSpecificityCurveExponent($currentConfigurationStoreName, $currentConfigurationLocaleName),
            'currentSpecificityWeightExponent' => $this->getFactory()->getSearchRankingFacade()->getSpecificityWeightExponent($currentConfigurationStoreName, $currentConfigurationLocaleName),
            'currentSpecificityWeightShiftMagnitude' => $this->getFactory()->getSearchRankingFacade()->getSpecificityWeightShiftMagnitude($currentConfigurationStoreName, $currentConfigurationLocaleName),
            'currentSpecificityBlendWeight' => $this->getFactory()->getSearchRankingFacade()->getSpecificityBlendWeight($currentConfigurationStoreName, $currentConfigurationLocaleName),
            'inProgressOptimizerRun' => $this->getFacade()->findOptimizerRunInProgress(),
            'latestOptimizerRun' => $latestOptimizerRunTransfer,
            'applySiblingLocalesByMetric' => $applySiblingLocalesByMetric,
            'applyForm' => $latestOptimizerRunTransfer !== null
                ? $this->getFactory()->createOptimizationApplyForm($latestOptimizerRunTransfer->getIdSearchRankingOptimizerRunOrFail())->createView()
                : null,
        ]);
    }

    /**
     * A store-wide metric's winning weight applies to this run's own (store, locale) same as any other,
     * but Apply writes it through search-ranking's own saveMetricWeight(), which fans that write out to
     * every real locale of the store too. Resolved per metric (same resolveEffectiveWeightLocales() call
     * CheckpointController already uses for its own Restore warning) so the Apply button below can
     * disclose the real blast radius before it's clicked.
     *
     * @param \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer|null $latestOptimizerRunTransfer
     * @param string $currentConfigurationStoreName
     * @param string $currentConfigurationLocaleName
     *
     * @return array<string, array<int, string>>
     */
    protected function resolveApplySiblingLocalesByMetric(
        ?SearchRankingOptimizerRunTransfer $latestOptimizerRunTransfer,
        string $currentConfigurationStoreName,
        string $currentConfigurationLocaleName,
    ): array {
        $applySiblingLocalesByMetric = [];

        if ($latestOptimizerRunTransfer === null) {
            return $applySiblingLocalesByMetric;
        }

        foreach ($latestOptimizerRunTransfer->getBestMetricWeights() as $metricWeightTransfer) {
            $siblingLocales = array_values(array_diff(
                $this->getFactory()->getSearchRankingFacade()->resolveEffectiveWeightLocales(
                    $metricWeightTransfer->getIdSearchRankingMetricOrFail(),
                    $currentConfigurationStoreName,
                    $currentConfigurationLocaleName,
                ),
                [$currentConfigurationLocaleName],
            ));

            if ($siblingLocales === []) {
                continue;
            }

            $applySiblingLocalesByMetric[$metricWeightTransfer->getNameOrFail()] = $siblingLocales;
        }

        return $applySiblingLocalesByMetric;
    }

    /**
     * @var array<int, string>
     */
    protected const FIXED_SCALAR_KEYS = [
        'relevanceWeight',
        'specificityCurveExponent',
        'specificityWeightExponent',
        'specificityWeightShiftMagnitude',
        'specificityBlendWeight',
    ];

    /**
     * @param array<string, mixed> $formData
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    protected function queueOptimizationRunFromFormData(array $formData, Request $request): RedirectResponse
    {
        $storeName = (string)$formData[AutomatedWeightOptimizationRunForm::FIELD_STORE_NAME];
        $localeName = (string)$formData[AutomatedWeightOptimizationRunForm::FIELD_LOCALE_NAME];
        $algorithm = (string)$formData[AutomatedWeightOptimizationRunForm::FIELD_ALGORITHM];
        $isTerminationCriteriaTrusted = (bool)$formData[AutomatedWeightOptimizationRunForm::FIELD_IS_TERMINATION_CRITERIA_TRUSTED];
        $warmStartFraction = (int)$formData[AutomatedWeightOptimizationRunForm::FIELD_WARM_START_FRACTION_PERCENT] / 100;

        $fixedScalars = $this->parseFixedScalarsFromRequest($request);

        $this->getFacade()->queueOptimizationRun(
            $storeName,
            $localeName,
            $algorithm,
            $isTerminationCriteriaTrusted,
            $warmStartFraction,
            $fixedScalars['relevanceWeight'],
            $fixedScalars['specificityCurveExponent'],
            $fixedScalars['specificityWeightExponent'],
            $fixedScalars['specificityWeightShiftMagnitude'],
            $fixedScalars['specificityBlendWeight'],
            $this->parseFixedMetricWeightsFromRequest($request),
        );

        $this->addSuccessMessage(
            'Optimization run queued — the next "search-ranking-optimizer:optimize" cron tick will process it.',
        );

        return $this->redirectResponse(sprintf(
            '%s?%s=%s&%s=%s',
            static::URL_OPTIMIZATION,
            AutomatedWeightOptimizationRunForm::FIELD_STORE_NAME,
            $storeName,
            AutomatedWeightOptimizationRunForm::FIELD_LOCALE_NAME,
            $localeName,
        ));
    }

    /**
     * Reads the run form's own parameter checklist for the 5 scalars (relevanceWeight + the 4 specificity
     * knobs), populated by {@see parametersAction()}'s JS. Each one is submitted as a checkbox
     * `optimizeScalar[<key>]` (present only when CHECKED -- unchecked HTML checkboxes submit nothing at
     * all) alongside an always-present `fixedScalarValue[<key>]` number input. A key absent from
     * `optimizeScalar` -- whether because a human unchecked it, or because the checklist was never loaded
     * at all (JS disabled, or submitted before a store/locale was picked) -- falls back to its own
     * `fixedScalarValue`; when THAT is also absent (the no-JS case), the result is null, preserving the
     * original always-free behavior exactly.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return array<string, float|null>
     */
    protected function parseFixedScalarsFromRequest(Request $request): array
    {
        $optimizeScalar = (array)$request->request->all('optimizeScalar');
        $fixedScalarValue = (array)$request->request->all('fixedScalarValue');

        $fixedScalars = [];

        foreach (static::FIXED_SCALAR_KEYS as $key) {
            $fixedScalars[$key] = !isset($optimizeScalar[$key]) && isset($fixedScalarValue[$key])
                ? (float)$fixedScalarValue[$key]
                : null;
        }

        return $fixedScalars;
    }

    /**
     * Same checked/unchecked contract as {@see parseFixedScalarsFromRequest()}, per metric instead of per
     * scalar: `optimizeMetric[<idSearchRankingMetric>]` (checkbox, checked = optimize),
     * `fixedMetricValue[<idSearchRankingMetric>]` (always-present number input), `metricName[<idSearchRankingMetric>]`
     * (always-present hidden input, so this never needs a second lookup to resolve id -> name). A metric
     * the checklist showed read-only (non-deterministic formula, nothing for a human to choose) submits
     * none of these three -- {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationRunner}'s
     * own determinism check holds it fixed regardless, exactly as it always has.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer>
     */
    protected function parseFixedMetricWeightsFromRequest(Request $request): array
    {
        $optimizeMetric = (array)$request->request->all('optimizeMetric');
        $fixedMetricValue = (array)$request->request->all('fixedMetricValue');
        $metricName = (array)$request->request->all('metricName');

        $fixedMetricWeightTransfers = [];

        foreach ($metricName as $idSearchRankingMetric => $name) {
            if (isset($optimizeMetric[$idSearchRankingMetric]) || !isset($fixedMetricValue[$idSearchRankingMetric])) {
                continue;
            }

            $fixedMetricWeightTransfers[] = (new SearchRankingWeightCheckpointMetricWeightTransfer())
                ->setIdSearchRankingMetric((int)$idSearchRankingMetric)
                ->setName((string)$name)
                ->setWeight((float)$fixedMetricValue[$idSearchRankingMetric]);
        }

        return $fixedMetricWeightTransfers;
    }

    /**
     * Fetched by the run form's own JS whenever the store/locale ChoiceType fields change, to populate the
     * parameter checklist (which scalar/metric to search vs. pin) without a full page reload — see
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface::listOptimizableParameters()}
     * for the payload shape.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function parametersAction(Request $request): JsonResponse
    {
        $storeName = (string)$request->query->get(AutomatedWeightOptimizationRunForm::FIELD_STORE_NAME, '');
        $localeName = (string)$request->query->get(AutomatedWeightOptimizationRunForm::FIELD_LOCALE_NAME, '');

        if ($storeName === '' || $localeName === '') {
            return $this->jsonResponse(['error' => 'storeName and localeName are both required.'], 400);
        }

        return $this->jsonResponse($this->getFacade()->listOptimizableParameters($storeName, $localeName));
    }

    /**
     * Polled by the Optimization page's own JS while a run is in status=running — deliberately tiny (id/
     * status/counts only) since this fires roughly once a second for however long the run takes.
     */
    public function progressAction(): JsonResponse
    {
        $inProgressOptimizerRunTransfer = $this->getFacade()->findOptimizerRunInProgress();

        if ($inProgressOptimizerRunTransfer === null) {
            return $this->jsonResponse([
                'status' => null,
            ]);
        }

        return $this->jsonResponse([
            'status' => $inProgressOptimizerRunTransfer->getStatus(),
            'processedCount' => $inProgressOptimizerRunTransfer->getProcessedCount(),
            'totalCount' => $inProgressOptimizerRunTransfer->getTotalCount(),
        ]);
    }
}
