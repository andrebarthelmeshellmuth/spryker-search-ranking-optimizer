<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Yves\SearchRankingOptimizerWidget\Plugin\Router;

use Spryker\Yves\Router\Plugin\RouteProvider\AbstractRouteProviderPlugin;
use Spryker\Yves\Router\Route\RouteCollection;

class SearchRankingOptimizerWidgetRouteProviderPlugin extends AbstractRouteProviderPlugin
{
    /**
     * @var string
     */
    public const ROUTE_NAME_SUBMIT_RELEVANCE_JUDGMENT = 'search-ranking-optimizer-widget/submit-relevance-judgment';

    /**
     * @var string
     */
    public const ROUTE_NAME_CLEAR_RELEVANCE_JUDGMENT = 'search-ranking-optimizer-widget/clear-relevance-judgment';

    /**
     * @param \Spryker\Yves\Router\Route\RouteCollection $routeCollection
     */
    public function addRoutes(RouteCollection $routeCollection): RouteCollection
    {
        $route = $this->buildRoute('/search-ranking-optimizer-widget/submit-relevance-judgment', 'SearchRankingOptimizerWidget', 'SubmitRelevanceJudgment', 'submitAction')
            ->setMethods(['POST']);
        $routeCollection->add(static::ROUTE_NAME_SUBMIT_RELEVANCE_JUDGMENT, $route);

        $clearRoute = $this->buildRoute('/search-ranking-optimizer-widget/clear-relevance-judgment', 'SearchRankingOptimizerWidget', 'SubmitRelevanceJudgment', 'clearAction')
            ->setMethods(['POST']);
        $routeCollection->add(static::ROUTE_NAME_CLEAR_RELEVANCE_JUDGMENT, $clearRoute);

        return $routeCollection;
    }
}
