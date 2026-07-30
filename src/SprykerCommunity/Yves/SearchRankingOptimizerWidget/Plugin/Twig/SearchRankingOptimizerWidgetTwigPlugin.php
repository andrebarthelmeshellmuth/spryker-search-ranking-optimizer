<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Yves\SearchRankingOptimizerWidget\Plugin\Twig;

use Spryker\Service\Container\ContainerInterface;
use Spryker\Shared\TwigExtension\Dependency\Plugin\TwigPluginInterface;
use Spryker\Yves\Kernel\AbstractPlugin;
use Spryker\Yves\Kernel\PermissionAwareTrait;
use SprykerCommunity\Shared\SearchRankingOptimizer\Plugin\RateSearchRelevancePermissionPlugin;
use Twig\Environment;
use Twig\TwigFunction;

/**
 * Registers `canRateSearchRelevance()` for the storefront templates — lets the product-rating widget's
 * own include gate itself on the Relevance Rater permission without needing a project-level controller
 * edit to pre-compute and inject that boolean (the same reasoning
 * `SprykerCommunity\Yves\SearchDebug\Plugin\Twig\SearchDebugTwigPlugin` already established for a
 * presentation-only helper — this one happens to check a permission instead of computing colors, but
 * doesn't need a controller for the same reason: it derives entirely from the current session).
 */
class SearchRankingOptimizerWidgetTwigPlugin extends AbstractPlugin implements TwigPluginInterface
{
    use PermissionAwareTrait;

    /**
     * @var string
     */
    public const FUNCTION_NAME_CAN_RATE_SEARCH_RELEVANCE = 'canRateSearchRelevance';

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter $container is mandated by TwigPluginInterface.
     *
     * @param \Twig\Environment $twig
     * @param \Spryker\Service\Container\ContainerInterface $container
     *
     * @return \Twig\Environment
     */
    public function extend(Environment $twig, ContainerInterface $container): Environment
    {
        $twig->addFunction(new TwigFunction(
            static::FUNCTION_NAME_CAN_RATE_SEARCH_RELEVANCE,
            fn (): bool => $this->can(RateSearchRelevancePermissionPlugin::KEY),
        ));

        return $twig;
    }
}
