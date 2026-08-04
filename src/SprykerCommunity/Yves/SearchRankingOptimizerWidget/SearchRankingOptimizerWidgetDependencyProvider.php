<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Yves\SearchRankingOptimizerWidget;

use Spryker\Yves\Kernel\AbstractBundleDependencyProvider;
use Spryker\Yves\Kernel\Container;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Dependency\Client\SearchRankingOptimizerWidgetToCustomerClientBridge;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Dependency\Client\SearchRankingOptimizerWidgetToSearchRankingOptimizerClientBridge;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Dependency\Client\SearchRankingOptimizerWidgetToStoreClientBridge;

class SearchRankingOptimizerWidgetDependencyProvider extends AbstractBundleDependencyProvider
{
    /**
     * @var string
     */
    public const CLIENT_SEARCH_RANKING_OPTIMIZER = 'CLIENT_SEARCH_RANKING_OPTIMIZER';

    /**
     * @var string
     */
    public const CLIENT_CUSTOMER = 'CLIENT_CUSTOMER';

    /**
     * @var string
     */
    public const CLIENT_STORE = 'CLIENT_STORE';

    /**
     * @var string
     */
    public const SERVICE_FORM_CSRF_PROVIDER = 'form.csrf_provider';

    /**
     * @param \Spryker\Yves\Kernel\Container $container
     *
     * @return \Spryker\Yves\Kernel\Container
     */
    #[\Override]
    public function provideDependencies(Container $container): Container
    {
        $container = parent::provideDependencies($container);
        $container = $this->addSearchRankingOptimizerClient($container);
        $container = $this->addCustomerClient($container);
        $container = $this->addStoreClient($container);
        $container = $this->addCsrfTokenManager($container);

        return $container;
    }

    /**
     * @param \Spryker\Yves\Kernel\Container $container
     *
     * @return \Spryker\Yves\Kernel\Container
     */
    protected function addSearchRankingOptimizerClient(Container $container): Container
    {
        $container->set(static::CLIENT_SEARCH_RANKING_OPTIMIZER, fn (Container $container) => new SearchRankingOptimizerWidgetToSearchRankingOptimizerClientBridge(
            $container->getLocator()->searchRankingOptimizer()->client(),
        ));

        return $container;
    }

    /**
     * @param \Spryker\Yves\Kernel\Container $container
     *
     * @return \Spryker\Yves\Kernel\Container
     */
    protected function addCustomerClient(Container $container): Container
    {
        $container->set(static::CLIENT_CUSTOMER, fn (Container $container) => new SearchRankingOptimizerWidgetToCustomerClientBridge(
            $container->getLocator()->customer()->client(),
        ));

        return $container;
    }

    /**
     * @param \Spryker\Yves\Kernel\Container $container
     *
     * @return \Spryker\Yves\Kernel\Container
     */
    protected function addStoreClient(Container $container): Container
    {
        $container->set(static::CLIENT_STORE, fn (Container $container) => new SearchRankingOptimizerWidgetToStoreClientBridge(
            $container->getLocator()->store()->client(),
        ));

        return $container;
    }

    /**
     * Same `form.csrf_provider` application service `spryker/multi-factor-auth`'s own Yves module uses for
     * its own non-Form AJAX endpoints — requires `Spryker\Yves\Form\Plugin\Application\FormApplicationPlugin`
     * registered in the project's `ShopApplicationDependencyProvider` (already true in this shop).
     *
     * @param \Spryker\Yves\Kernel\Container $container
     *
     * @return \Spryker\Yves\Kernel\Container
     */
    protected function addCsrfTokenManager(Container $container): Container
    {
        $container->set(static::SERVICE_FORM_CSRF_PROVIDER, fn (Container $container) => $container->getApplicationService(static::SERVICE_FORM_CSRF_PROVIDER));

        return $container;
    }
}
