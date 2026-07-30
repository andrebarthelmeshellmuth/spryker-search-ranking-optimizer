<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Yves\SearchRankingOptimizerWidget;

use Spryker\Yves\Kernel\AbstractFactory;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Dependency\Client\SearchRankingOptimizerWidgetToCustomerClientInterface;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Dependency\Client\SearchRankingOptimizerWidgetToSearchRankingOptimizerClientInterface;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Dependency\Client\SearchRankingOptimizerWidgetToStoreClientInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class SearchRankingOptimizerWidgetFactory extends AbstractFactory
{
    /**
     * @return \SprykerCommunity\Yves\SearchRankingOptimizerWidget\Dependency\Client\SearchRankingOptimizerWidgetToSearchRankingOptimizerClientInterface
     */
    public function getSearchRankingOptimizerClient(): SearchRankingOptimizerWidgetToSearchRankingOptimizerClientInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerWidgetDependencyProvider::CLIENT_SEARCH_RANKING_OPTIMIZER);
    }

    /**
     * @return \SprykerCommunity\Yves\SearchRankingOptimizerWidget\Dependency\Client\SearchRankingOptimizerWidgetToCustomerClientInterface
     */
    public function getCustomerClient(): SearchRankingOptimizerWidgetToCustomerClientInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerWidgetDependencyProvider::CLIENT_CUSTOMER);
    }

    /**
     * @return \SprykerCommunity\Yves\SearchRankingOptimizerWidget\Dependency\Client\SearchRankingOptimizerWidgetToStoreClientInterface
     */
    public function getStoreClient(): SearchRankingOptimizerWidgetToStoreClientInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerWidgetDependencyProvider::CLIENT_STORE);
    }

    /**
     * @return \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface
     */
    public function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerWidgetDependencyProvider::SERVICE_FORM_CSRF_PROVIDER);
    }
}
