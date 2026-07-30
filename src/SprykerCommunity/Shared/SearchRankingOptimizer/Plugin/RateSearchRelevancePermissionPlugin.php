<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Shared\SearchRankingOptimizer\Plugin;

use Spryker\Shared\PermissionExtension\Dependency\Plugin\PermissionPluginInterface;

/**
 * Grants the **Relevance Rater** role: heart/checkmark/X buttons on the storefront search results page,
 * submitting an immediate per-(query, product) relevance judgment. A SEPARATE, orthogonal hierarchy from
 * search-ranking's own `search-score-admin` tiers — rating relevance and tuning business weights are
 * different skills, granted independently.
 *
 * For Zed & Client PermissionDependencyProvider::getPermissionPlugins() registration.
 */
class RateSearchRelevancePermissionPlugin implements PermissionPluginInterface
{
    /**
     * @var string
     */
    public const KEY = 'RateSearchRelevancePermissionPlugin';

    /**
     * @return string
     */
    public function getKey(): string
    {
        return static::KEY;
    }
}
