<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer;

use Spryker\Client\Kernel\AbstractDependencyProvider;
use Spryker\Client\Kernel\Container;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientBridge;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientBridge;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToZedRequestBridge;

class SearchRankingOptimizerDependencyProvider extends AbstractDependencyProvider
{
    /**
     * @var string
     */
    public const CLIENT_ZED_REQUEST = 'CLIENT_ZED_REQUEST';

    /**
     * @var string
     */
    public const CLIENT_SEARCH_RANKING_STORAGE = 'CLIENT_SEARCH_RANKING_STORAGE';

    /**
     * @var string
     */
    public const CLIENT_SEARCH_RANKING = 'CLIENT_SEARCH_RANKING';

    /**
     * @param \Spryker\Client\Kernel\Container $container
     */
    #[\Override]
    public function provideServiceLayerDependencies(Container $container): Container
    {
        $container = parent::provideServiceLayerDependencies($container);
        $container = $this->addZedRequestClient($container);
        $container = $this->addSearchRankingStorageClient($container);
        $container = $this->addSearchRankingClient($container);

        return $container;
    }

    /**
     * @param \Spryker\Client\Kernel\Container $container
     */
    protected function addZedRequestClient(Container $container): Container
    {
        $container->set(static::CLIENT_ZED_REQUEST, fn (Container $container) => new SearchRankingOptimizerToZedRequestBridge($container->getLocator()->zedRequest()->client()));

        return $container;
    }

    /**
     * @param \Spryker\Client\Kernel\Container $container
     */
    protected function addSearchRankingStorageClient(Container $container): Container
    {
        $container->set(static::CLIENT_SEARCH_RANKING_STORAGE, fn (Container $container) => new SearchRankingOptimizerToSearchRankingStorageClientBridge($container->getLocator()->searchRankingStorage()->client()));

        return $container;
    }

    /**
     * @param \Spryker\Client\Kernel\Container $container
     */
    protected function addSearchRankingClient(Container $container): Container
    {
        $container->set(static::CLIENT_SEARCH_RANKING, fn (Container $container) => new SearchRankingOptimizerToSearchRankingClientBridge($container->getLocator()->searchRanking()->client()));

        return $container;
    }
}
