<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller;

use Spryker\Zed\Kernel\Communication\Controller\AbstractController;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\TestCurrentEvaluationForm;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface getFacade()
 */
class TestCurrentEvaluationController extends AbstractController
{
    /**
     * @var string
     */
    protected const URL_EVALUATION = '/search-ranking-optimizer/test-current-evaluation';

    /**
     * @var string
     */
    protected const PARAM_HISTORY_STORE_NAME = 'historyStoreName';

    /**
     * @var string
     */
    protected const PARAM_HISTORY_LOCALE_NAME = 'historyLocaleName';

    /**
     * `_rank_eval` is a single batched HTTP call (unlike Saturation Point Calibration's per-term loop), so — unlike
     * Saturation Point Calibration's upload-then-cron-then-poll flow — this page runs the evaluation synchronously on
     * submit and redirects straight back with the result: no progress counter/polling machinery needed.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|array<string, mixed>
     */
    public function indexAction(Request $request)
    {
        $evaluationForm = $this->getFactory()
            ->createEvaluationForm($this->getDefaultFormData($request))
            ->handleRequest($request);

        if ($evaluationForm->isSubmitted() && !$evaluationForm->isValid()) {
            $this->addErrorMessage('CSRF token is not valid.');

            return $this->redirectResponse(static::URL_EVALUATION);
        }

        if ($evaluationForm->isSubmitted() && $evaluationForm->isValid()) {
            return $this->processSubmission($evaluationForm);
        }

        $storeName = (string)$request->query->get(TestCurrentEvaluationForm::FIELD_STORE_NAME, '');
        $localeName = (string)$request->query->get(TestCurrentEvaluationForm::FIELD_LOCALE_NAME, '');
        $historyStoreName = $this->resolveHistoryStoreName($request);
        $historyLocaleName = $this->resolveHistoryLocaleName($request);

        return $this->viewResponse([
            'evaluationForm' => $evaluationForm->createView(),
            'storeName' => $storeName,
            'localeName' => $localeName,
            'latestEvaluation' => $storeName !== '' && $localeName !== ''
                ? $this->getFacade()->findLatestEvaluation($storeName, $localeName)
                : null,
            // Independent of the run form's own storeName/localeName above -- History is a cross-scope
            // audit trail (unfiltered by default), not tied to whichever scope is currently selected to
            // evaluate/re-evaluate.
            'evaluationHistory' => $this->getFacade()->findEvaluationHistory($historyStoreName, $historyLocaleName),
            'stores' => $this->getFactory()->getAllStoreNames(),
            'locales' => $this->getFactory()->getAllLocaleNames(),
            'selectedHistoryStoreName' => $historyStoreName,
            'selectedHistoryLocaleName' => $historyLocaleName,
        ]);
    }

    /**
     * @param \Symfony\Component\Form\FormInterface $evaluationForm
     */
    protected function processSubmission(FormInterface $evaluationForm): RedirectResponse
    {
        $formData = $evaluationForm->getData();
        $storeName = (string)$formData[TestCurrentEvaluationForm::FIELD_STORE_NAME];
        $localeName = (string)$formData[TestCurrentEvaluationForm::FIELD_LOCALE_NAME];

        $evaluationTransfer = $this->getFacade()->runRankEvaluation($storeName, $localeName);

        if ($evaluationTransfer === null) {
            $this->addErrorMessage(sprintf(
                'Nothing to evaluate for %s / %s yet — no query has a rated product.',
                $storeName,
                $localeName,
            ));
        } else {
            $this->addSuccessMessage(sprintf(
                'Evaluated %d rated quer%s: weighted nDCG@%d = %.4f.',
                $evaluationTransfer->getQueryCountOrFail(),
                $evaluationTransfer->getQueryCountOrFail() === 1 ? 'y' : 'ies',
                SearchRankingOptimizerConfig::getRankEvalCutoff(),
                $evaluationTransfer->getMetricScoreOrFail(),
            ));
        }

        return $this->redirectResponse(sprintf(
            '%s?%s=%s&%s=%s',
            static::URL_EVALUATION,
            TestCurrentEvaluationForm::FIELD_STORE_NAME,
            $storeName,
            TestCurrentEvaluationForm::FIELD_LOCALE_NAME,
            $localeName,
        ));
    }

    /**
     * Pre-fills the form from the current GET scope (?storeName=&localeName=), if any, so switching store/
     * locale and viewing its history doesn't require re-picking both fields every time.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return array<string, string>
     */
    protected function getDefaultFormData(Request $request): array
    {
        $storeName = $request->query->get(TestCurrentEvaluationForm::FIELD_STORE_NAME);
        $localeName = $request->query->get(TestCurrentEvaluationForm::FIELD_LOCALE_NAME);

        $data = [];

        if ($storeName !== null) {
            $data[TestCurrentEvaluationForm::FIELD_STORE_NAME] = (string)$storeName;
        }

        if ($localeName !== null) {
            $data[TestCurrentEvaluationForm::FIELD_LOCALE_NAME] = (string)$localeName;
        }

        return $data;
    }

    /**
     * Null (not a default scope) means "no filter" — the History table below is a cross-scope audit
     * trail, unlike the run form above it, so an unset/blank query param means "show every store", not
     * "fall back to whatever the run form has selected".
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
}
