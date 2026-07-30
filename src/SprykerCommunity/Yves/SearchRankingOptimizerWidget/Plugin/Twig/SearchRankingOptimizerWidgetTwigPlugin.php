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
 * @method \SprykerCommunity\Yves\SearchRankingOptimizerWidget\SearchRankingOptimizerWidgetFactory getFactory()
 *
 * Registers `canRateSearchRelevance()` for the storefront templates — lets the product-rating widget's
 * own include gate itself on the Relevance Rater permission without needing a project-level controller
 * edit to pre-compute and inject that boolean (the same reasoning
 * `SprykerCommunity\Yves\SearchDebug\Plugin\Twig\SearchDebugTwigPlugin` already established for a
 * presentation-only helper — this one happens to check a permission instead of computing colors, but
 * doesn't need a controller for the same reason: it derives entirely from the current session).
 *
 * Also registers `searchRankingOptimizerRatingCsrfToken()` for the same reason: the widget's submit/clear
 * AJAX endpoints are plain POST controllers, not bound to a Symfony Form, so they'd otherwise carry none of
 * the CSRF protection every Form-backed POST in this project gets automatically — same
 * `Symfony\Component\Security\Csrf\CsrfTokenManagerInterface` mechanism `spryker/multi-factor-auth`'s own
 * Yves module uses for its own non-Form AJAX actions.
 */
class SearchRankingOptimizerWidgetTwigPlugin extends AbstractPlugin implements TwigPluginInterface
{
    use PermissionAwareTrait;

    /**
     * @var string
     */
    public const FUNCTION_NAME_CAN_RATE_SEARCH_RELEVANCE = 'canRateSearchRelevance';

    /**
     * @var string
     */
    public const FUNCTION_NAME_RATING_CSRF_TOKEN = 'searchRankingOptimizerRatingCsrfToken';

    /**
     * Shared by both submit and clear — re-checked in {@see \SprykerCommunity\Yves\SearchRankingOptimizerWidget\Controller\SubmitRelevanceJudgmentController},
     * not tied to either action individually, so one token per page render covers both.
     *
     * @var string
     */
    public const CSRF_TOKEN_ID = 'search-ranking-optimizer-widget-rate';

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

        $twig->addFunction(new TwigFunction(
            static::FUNCTION_NAME_RATING_CSRF_TOKEN,
            fn (): string => $this->getFactory()->getCsrfTokenManager()->getToken(static::CSRF_TOKEN_ID)->getValue(),
        ));

        return $twig;
    }
}
