<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer;

use Spryker\Zed\Kernel\AbstractBundleDependencyProvider;
use Spryker\Zed\Kernel\Container;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientBridge;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToAclFacadeBridge;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToCompanyUserFacadeBridge;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToLocaleFacadeBridge;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToPermissionFacadeBridge;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeBridge;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToStoreFacadeBridge;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSymfonyMailerFacadeBridge;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\QueryContainer\SearchRankingOptimizerToAclQueryContainerBridge;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\SearchRankingOptimizerConfig getConfig()
 */
class SearchRankingOptimizerDependencyProvider extends AbstractBundleDependencyProvider
{
    /**
     * @var string
     */
    public const CLIENT_SEARCH_RANKING_OPTIMIZER = 'CLIENT_SEARCH_RANKING_OPTIMIZER';

    /**
     * The base spryker-community/search-ranking facade — this package writes the calibration's suggested
     * `relevanceSaturationPoint` (k) back into that package's own setting via `saveRelevanceSaturationPoint()`
     * when an admin applies a calibration. One-directional: this package depends on search-ranking, never
     * the reverse.
     *
     * @var string
     */
    public const FACADE_SEARCH_RANKING = 'FACADE_SEARCH_RANKING';

    /**
     * @var string
     */
    public const FACADE_STORE = 'FACADE_STORE';

    /**
     * @var string
     */
    public const FACADE_LOCALE = 'FACADE_LOCALE';

    /**
     * Resolves the incoming Gateway Controller request's customer reference to its active company user(s)
     * — never trust an idCompanyUser passed in the request transfer itself, always re-derive it server-side.
     *
     * @var string
     */
    public const FACADE_COMPANY_USER = 'FACADE_COMPANY_USER';

    /**
     * Re-checks RateSearchRelevancePermissionPlugin server-side in the Gateway Controller, independently
     * of whatever the Yves side already gated — the single gate for these writes, same posture as
     * search-debug's own SearchDebugAccessChecker. Zed's own "Query Curator" page (editing a query's
     * importance weight) is gated by standard Zed ACL only, like every other Zed controller in this
     * package — there is no separate fine-grained Permission-system check for it.
     *
     * @var string
     */
    public const FACADE_PERMISSION = 'FACADE_PERMISSION';

    /**
     * Resolves which ACL groups hold the notification role, and which admin users belong to those groups
     * — the auto-tune job's "who gets the before/after summary email" lookup.
     *
     * @var string
     */
    public const FACADE_ACL = 'FACADE_ACL';

    /**
     * @var string
     */
    public const QUERY_CONTAINER_ACL = 'QUERY_CONTAINER_ACL';

    /**
     * Sends the auto-tune job's before/after summary email directly, bypassing `spryker/mail`'s
     * per-event template system for this one-off self-contained notification.
     *
     * @var string
     */
    public const FACADE_SYMFONY_MAILER = 'FACADE_SYMFONY_MAILER';

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    #[\Override]
    public function provideBusinessLayerDependencies(Container $container): Container
    {
        $container = parent::provideBusinessLayerDependencies($container);
        $container = $this->addSearchRankingClient($container);
        $container = $this->addSearchRankingFacade($container);
        $container = $this->addAclFacade($container);
        $container = $this->addAclQueryContainer($container);
        $container = $this->addSymfonyMailerFacade($container);

        return $container;
    }

    /**
     * The base-package facade bridge is also needed in the Business layer as of the weight-checkpoint
     * feature (`WeightCheckpointRecorder`/`WeightCheckpointRestorer` read and write `search-ranking`'s own
     * live tuning values directly) — every other base-package bridge here stays Communication-layer only
     * (the Gui apply controller), since calibration/scoring business logic still has no dependency on the
     * base package beyond the Client it already used.
     *
     * @param \Spryker\Zed\Kernel\Container $container
     */
    #[\Override]
    public function provideCommunicationLayerDependencies(Container $container): Container
    {
        $container = parent::provideCommunicationLayerDependencies($container);
        $container = $this->addSearchRankingClient($container);
        $container = $this->addSearchRankingFacade($container);
        $container = $this->addStoreFacade($container);
        $container = $this->addLocaleFacade($container);
        $container = $this->addCompanyUserFacade($container);
        $container = $this->addPermissionFacade($container);

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    protected function addSearchRankingClient(Container $container): Container
    {
        $container->set(static::CLIENT_SEARCH_RANKING_OPTIMIZER, fn (Container $container) => new SearchRankingOptimizerToSearchRankingClientBridge(
            $container->getLocator()->searchRankingOptimizer()->client(),
        ));

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    protected function addSearchRankingFacade(Container $container): Container
    {
        $container->set(static::FACADE_SEARCH_RANKING, fn (Container $container) => new SearchRankingOptimizerToSearchRankingFacadeBridge(
            $container->getLocator()->searchRanking()->facade(),
        ));

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    protected function addStoreFacade(Container $container): Container
    {
        $container->set(static::FACADE_STORE, fn (Container $container) => new SearchRankingOptimizerToStoreFacadeBridge(
            $container->getLocator()->store()->facade(),
        ));

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    protected function addLocaleFacade(Container $container): Container
    {
        $container->set(static::FACADE_LOCALE, fn (Container $container) => new SearchRankingOptimizerToLocaleFacadeBridge(
            $container->getLocator()->locale()->facade(),
        ));

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    protected function addCompanyUserFacade(Container $container): Container
    {
        $container->set(static::FACADE_COMPANY_USER, fn (Container $container) => new SearchRankingOptimizerToCompanyUserFacadeBridge(
            $container->getLocator()->companyUser()->facade(),
        ));

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    protected function addPermissionFacade(Container $container): Container
    {
        $container->set(static::FACADE_PERMISSION, fn (Container $container) => new SearchRankingOptimizerToPermissionFacadeBridge(
            $container->getLocator()->permission()->facade(),
        ));

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    protected function addAclFacade(Container $container): Container
    {
        $container->set(static::FACADE_ACL, fn (Container $container) => new SearchRankingOptimizerToAclFacadeBridge(
            $container->getLocator()->acl()->facade(),
        ));

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    protected function addAclQueryContainer(Container $container): Container
    {
        $container->set(static::QUERY_CONTAINER_ACL, fn (Container $container) => new SearchRankingOptimizerToAclQueryContainerBridge(
            $container->getLocator()->acl()->queryContainer(),
        ));

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    protected function addSymfonyMailerFacade(Container $container): Container
    {
        $container->set(static::FACADE_SYMFONY_MAILER, fn (Container $container) => new SearchRankingOptimizerToSymfonyMailerFacadeBridge(
            $container->getLocator()->symfonyMailer()->facade(),
        ));

        return $container;
    }
}
