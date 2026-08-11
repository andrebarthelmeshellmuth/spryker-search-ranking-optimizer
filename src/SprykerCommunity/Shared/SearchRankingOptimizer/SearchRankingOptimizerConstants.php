<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Shared\SearchRankingOptimizer;

/**
 * Declares global environment configuration keys. Do not use it for other class constants.
 */
interface SearchRankingOptimizerConstants
{
    /**
     * Specification:
     * - Toggles whether the `search-ranking-optimizer-widget/check-installation` Yves diagnostic page's
     *   route registers at all.
     * - Defaults to **disabled**: the route does not exist anywhere unless a project opts in. Fail-closed
     *   by default, matching this package's own convention (plugins, routes and the permission itself all
     *   require explicit registration, nothing auto-activates), the sibling
     *   `spryker-community/search-debug`'s identical flag, and Spryker core's own idiom for a dev
     *   diagnostic (`Spryker\Shared\WebProfiler\WebProfilerConstants::IS_WEB_PROFILER_ENABLED` likewise
     *   defaults to `false`).
     * - Set to `true` in a project's development-tier config (e.g. `config_default-development.php`) to opt
     *   in. Doing so early is the recommendation, not an afterthought: the Yves half of this package's
     *   installation is precisely the half that fails silently — a missing `activeRatingType` wiring or an
     *   unbuilt frontend asset produces a widget that looks installed and simply never reflects a stored
     *   judgment.
     * - The permission check in {@see \SprykerCommunity\Yves\SearchRankingOptimizerWidget\Controller\CheckInstallationController}
     *   still applies wherever a project opts this flag on, so enabling it does not by itself expose the
     *   page to anyone but a permitted customer. Not registering the route at all is still the stronger
     *   default: a permission check alone would leak "this route exists and is gated" to an
     *   unauthenticated prober, which leaving the flag off entirely avoids.
     *
     * @api
     *
     * @var string
     */
    public const IS_CHECK_INSTALLATION_PAGE_ENABLED = 'SEARCH_RANKING_OPTIMIZER:IS_CHECK_INSTALLATION_PAGE_ENABLED';
}
