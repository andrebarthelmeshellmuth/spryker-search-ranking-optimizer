<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication;

use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryQuery;
use Spryker\Zed\Kernel\Communication\AbstractCommunicationFactory;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Authorization\RelevanceJudgmentAuthorizer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Authorization\RelevanceJudgmentAuthorizerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\AutoTuneMetricConfigForm;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\CalibrationApplyForm;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\CalibrationUploadForm;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\EvaluationForm;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\OptimizationApplyForm;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\OptimizeRunForm;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\QueryImportanceWeightForm;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\RecordWeightCheckpointForm;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\RestoreWeightCheckpointForm;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Table\QueryTable;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToCompanyUserFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToLocaleFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToPermissionFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingStorageFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToStoreFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\SearchRankingOptimizerDependencyProvider;
use Symfony\Component\Form\FormInterface;

class SearchRankingOptimizerCommunicationFactory extends AbstractCommunicationFactory
{
    /**
     * @return \Symfony\Component\Form\FormInterface
     */
    public function createCalibrationUploadForm(): FormInterface
    {
        $storeChoices = [];
        foreach ($this->getStoreFacade()->getAllStores() as $storeTransfer) {
            $storeChoices[$storeTransfer->getNameOrFail()] = $storeTransfer->getNameOrFail();
        }

        $localeChoices = [];
        foreach ($this->getLocaleFacade()->getAvailableLocales() as $localeName) {
            $localeChoices[$localeName] = $localeName;
        }

        return $this->getFormFactory()->create(CalibrationUploadForm::class, null, [
            CalibrationUploadForm::OPTION_STORE_CHOICES => $storeChoices,
            CalibrationUploadForm::OPTION_LOCALE_CHOICES => $localeChoices,
        ]);
    }

    /**
     * @param array<string, string> $data
     *
     * @return \Symfony\Component\Form\FormInterface
     */
    public function createEvaluationForm(array $data = []): FormInterface
    {
        $storeChoices = [];
        foreach ($this->getStoreFacade()->getAllStores() as $storeTransfer) {
            $storeChoices[$storeTransfer->getNameOrFail()] = $storeTransfer->getNameOrFail();
        }

        $localeChoices = [];
        foreach ($this->getLocaleFacade()->getAvailableLocales() as $localeName) {
            $localeChoices[$localeName] = $localeName;
        }

        return $this->getFormFactory()->create(EvaluationForm::class, $data, [
            EvaluationForm::OPTION_STORE_CHOICES => $storeChoices,
            EvaluationForm::OPTION_LOCALE_CHOICES => $localeChoices,
        ]);
    }

    /**
     * @param float $relevanceSaturationPoint
     *
     * @return \Symfony\Component\Form\FormInterface
     */
    public function createCalibrationApplyForm(float $relevanceSaturationPoint): FormInterface
    {
        return $this->getFormFactory()->create(CalibrationApplyForm::class, [
            CalibrationApplyForm::FIELD_RELEVANCE_SATURATION_POINT => $relevanceSaturationPoint,
        ]);
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface
     */
    public function getSearchRankingFacade(): SearchRankingOptimizerToSearchRankingFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_SEARCH_RANKING);
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingStorageFacadeInterface
     */
    public function getSearchRankingStorageFacade(): SearchRankingOptimizerToSearchRankingStorageFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_SEARCH_RANKING_STORAGE);
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToStoreFacadeInterface
     */
    public function getStoreFacade(): SearchRankingOptimizerToStoreFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_STORE);
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToLocaleFacadeInterface
     */
    public function getLocaleFacade(): SearchRankingOptimizerToLocaleFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_LOCALE);
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToCompanyUserFacadeInterface
     */
    public function getCompanyUserFacade(): SearchRankingOptimizerToCompanyUserFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_COMPANY_USER);
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToPermissionFacadeInterface
     */
    public function getPermissionFacade(): SearchRankingOptimizerToPermissionFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_PERMISSION);
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Authorization\RelevanceJudgmentAuthorizerInterface
     */
    public function createRelevanceJudgmentAuthorizer(): RelevanceJudgmentAuthorizerInterface
    {
        return new RelevanceJudgmentAuthorizer(
            $this->getCompanyUserFacade(),
            $this->getPermissionFacade(),
        );
    }

    /**
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Table\QueryTable
     */
    public function createQueryTable(): QueryTable
    {
        return new QueryTable(SpySearchRankingQueryQuery::create());
    }

    /**
     * @param float $importanceWeight
     *
     * @return \Symfony\Component\Form\FormInterface
     */
    public function createQueryImportanceWeightForm(float $importanceWeight): FormInterface
    {
        return $this->getFormFactory()->create(QueryImportanceWeightForm::class, [
            QueryImportanceWeightForm::FIELD_IMPORTANCE_WEIGHT => $importanceWeight,
        ]);
    }

    /**
     * @return \Symfony\Component\Form\FormInterface
     */
    public function createRecordWeightCheckpointForm(): FormInterface
    {
        return $this->getFormFactory()->create(RecordWeightCheckpointForm::class);
    }

    /**
     * @param int $idSearchRankingWeightCheckpoint
     *
     * @return \Symfony\Component\Form\FormInterface
     */
    public function createRestoreWeightCheckpointForm(int $idSearchRankingWeightCheckpoint): FormInterface
    {
        return $this->getFormFactory()->create(RestoreWeightCheckpointForm::class, [
            RestoreWeightCheckpointForm::FIELD_ID_SEARCH_RANKING_WEIGHT_CHECKPOINT => $idSearchRankingWeightCheckpoint,
        ]);
    }

    /**
     * @param int $idSearchRankingMetric
     * @param float|null $autoTuneThreshold
     * @param bool $isAutoUpdateEnabled
     * @param string $autoUpdateScope
     * @param bool $isNotifyEnabled
     *
     * @return \Symfony\Component\Form\FormInterface
     */
    public function createAutoTuneMetricConfigForm(
        int $idSearchRankingMetric,
        ?float $autoTuneThreshold,
        bool $isAutoUpdateEnabled,
        string $autoUpdateScope,
        bool $isNotifyEnabled,
    ): FormInterface {
        return $this->getFormFactory()->create(AutoTuneMetricConfigForm::class, [
            AutoTuneMetricConfigForm::FIELD_ID_SEARCH_RANKING_METRIC => $idSearchRankingMetric,
            AutoTuneMetricConfigForm::FIELD_AUTO_TUNE_THRESHOLD => $autoTuneThreshold,
            AutoTuneMetricConfigForm::FIELD_IS_AUTO_UPDATE_ENABLED => $isAutoUpdateEnabled,
            AutoTuneMetricConfigForm::FIELD_AUTO_UPDATE_SCOPE => $autoUpdateScope,
            AutoTuneMetricConfigForm::FIELD_IS_NOTIFY_ENABLED => $isNotifyEnabled,
        ]);
    }

    /**
     * @return \Symfony\Component\Form\FormInterface
     */
    public function createOptimizeRunForm(): FormInterface
    {
        $storeChoices = [];
        foreach ($this->getStoreFacade()->getAllStores() as $storeTransfer) {
            $storeChoices[$storeTransfer->getNameOrFail()] = $storeTransfer->getNameOrFail();
        }

        $localeChoices = [];
        foreach ($this->getLocaleFacade()->getAvailableLocales() as $localeName) {
            $localeChoices[$localeName] = $localeName;
        }

        return $this->getFormFactory()->create(OptimizeRunForm::class, null, [
            OptimizeRunForm::OPTION_STORE_CHOICES => $storeChoices,
            OptimizeRunForm::OPTION_LOCALE_CHOICES => $localeChoices,
        ]);
    }

    /**
     * @param int $idSearchRankingOptimizerRun
     *
     * @return \Symfony\Component\Form\FormInterface
     */
    public function createOptimizationApplyForm(int $idSearchRankingOptimizerRun): FormInterface
    {
        return $this->getFormFactory()->create(OptimizationApplyForm::class, [
            OptimizationApplyForm::FIELD_ID_SEARCH_RANKING_OPTIMIZER_RUN => $idSearchRankingOptimizerRun,
        ]);
    }
}
