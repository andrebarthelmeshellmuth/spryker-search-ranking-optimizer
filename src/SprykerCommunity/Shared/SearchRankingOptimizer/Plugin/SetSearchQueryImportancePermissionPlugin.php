<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Shared\SearchRankingOptimizer\Plugin;

use Spryker\Shared\PermissionExtension\Dependency\Plugin\PermissionPluginInterface;

/**
 * Grants the **Query Curator** role: assigning a real importance weight to a query once it has
 * accumulated at least one relevance rating (see {@see RateSearchRelevancePermissionPlugin}). A SEPARATE,
 * orthogonal hierarchy from both `search-score-admin` (weight tuning) and the Relevance Rater role above —
 * curating which queries matter most is a distinct skill from either.
 *
 * For Zed & Client PermissionDependencyProvider::getPermissionPlugins() registration.
 */
class SetSearchQueryImportancePermissionPlugin implements PermissionPluginInterface
{
    /**
     * @var string
     */
    public const KEY = 'SetSearchQueryImportancePermissionPlugin';

    /**
     * @return string
     */
    public function getKey(): string
    {
        return static::KEY;
    }
}
