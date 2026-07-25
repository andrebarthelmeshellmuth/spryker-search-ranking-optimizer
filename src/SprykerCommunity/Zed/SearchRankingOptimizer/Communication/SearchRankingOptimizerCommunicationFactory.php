<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication;

use Spryker\Zed\Kernel\Communication\AbstractCommunicationFactory;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\CalibrationApplyForm;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\CalibrationUploadForm;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToLocaleFacadeInterface;
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
}
