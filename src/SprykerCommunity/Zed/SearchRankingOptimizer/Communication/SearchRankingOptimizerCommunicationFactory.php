<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication;

use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryQuery;
use Spryker\Zed\Kernel\Communication\AbstractCommunicationFactory;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
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
     * @param float $saturationPointValue
     * @param string $storeName
     * @param string $localeName
     * @param string $calibrationType
     */
    public function createCalibrationApplyForm(
        float $saturationPointValue,
        string $storeName,
        string $localeName,
        string $calibrationType = SearchRankingOptimizerConfig::CALIBRATION_TYPE_RELEVANCE_SCORE,
    ): FormInterface {
        return $this->getFormFactory()->create(CalibrationApplyForm::class, [
            CalibrationApplyForm::FIELD_RELEVANCE_SATURATION_POINT => $saturationPointValue,
            CalibrationApplyForm::FIELD_STORE_NAME => $storeName,
            CalibrationApplyForm::FIELD_LOCALE_NAME => $localeName,
            CalibrationApplyForm::FIELD_CALIBRATION_TYPE => $calibrationType,
        ]);
    }

    public function getSearchRankingFacade(): SearchRankingOptimizerToSearchRankingFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_SEARCH_RANKING);
    }

    public function getSearchRankingStorageFacade(): SearchRankingOptimizerToSearchRankingStorageFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_SEARCH_RANKING_STORAGE);
    }

    public function getStoreFacade(): SearchRankingOptimizerToStoreFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_STORE);
    }

    public function getLocaleFacade(): SearchRankingOptimizerToLocaleFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_LOCALE);
    }

    /**
     * Every store name, for the Checkpoint page's own Store+Locale scope selector — matches
     * spryker-community/search-ranking's own `SearchRankingGuiCommunicationFactory::getAllStoreNames()`.
     *
     * @return array<string>
     */
    public function getAllStoreNames(): array
    {
        return array_map(
            static fn ($storeTransfer) => $storeTransfer->getNameOrFail(),
            $this->getStoreFacade()->getAllStores(),
        );
    }

    /**
     * @return array<string>
     */
    public function getAllLocaleNames(): array
    {
        return array_values($this->getLocaleFacade()->getAvailableLocales());
    }

    public function getCompanyUserFacade(): SearchRankingOptimizerToCompanyUserFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_COMPANY_USER);
    }

    public function getPermissionFacade(): SearchRankingOptimizerToPermissionFacadeInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::FACADE_PERMISSION);
    }

    public function createRelevanceJudgmentAuthorizer(): RelevanceJudgmentAuthorizerInterface
    {
        return new RelevanceJudgmentAuthorizer(
            $this->getCompanyUserFacade(),
            $this->getPermissionFacade(),
        );
    }

    public function createQueryTable(): QueryTable
    {
        return new QueryTable(SpySearchRankingQueryQuery::create());
    }

    /**
     * @param float $importanceWeight
     */
    public function createQueryImportanceWeightForm(float $importanceWeight): FormInterface
    {
        return $this->getFormFactory()->create(QueryImportanceWeightForm::class, [
            QueryImportanceWeightForm::FIELD_IMPORTANCE_WEIGHT => $importanceWeight,
        ]);
    }

    public function createRecordWeightCheckpointForm(): FormInterface
    {
        return $this->getFormFactory()->create(RecordWeightCheckpointForm::class);
    }

    /**
     * @param int $idSearchRankingWeightCheckpoint
     * @param string $storeName
     * @param string $localeName
     */
    public function createRestoreWeightCheckpointForm(int $idSearchRankingWeightCheckpoint, string $storeName, string $localeName): FormInterface
    {
        return $this->getFormFactory()->create(RestoreWeightCheckpointForm::class, [
            RestoreWeightCheckpointForm::FIELD_ID_SEARCH_RANKING_WEIGHT_CHECKPOINT => $idSearchRankingWeightCheckpoint,
            RestoreWeightCheckpointForm::FIELD_STORE_NAME => $storeName,
            RestoreWeightCheckpointForm::FIELD_LOCALE_NAME => $localeName,
        ]);
    }

    /**
     * @param int $idSearchRankingMetric
     * @param float|null $autoTuneThreshold
     * @param bool $isAutoUpdateEnabled
     * @param string $autoUpdateScope
     * @param bool $isNotifyEnabled
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
     */
    public function createOptimizationApplyForm(int $idSearchRankingOptimizerRun): FormInterface
    {
        return $this->getFormFactory()->create(OptimizationApplyForm::class, [
            OptimizationApplyForm::FIELD_ID_SEARCH_RANKING_OPTIMIZER_RUN => $idSearchRankingOptimizerRun,
        ]);
    }
}
