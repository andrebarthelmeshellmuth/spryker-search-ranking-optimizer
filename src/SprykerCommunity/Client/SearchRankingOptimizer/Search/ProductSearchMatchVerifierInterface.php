<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

interface ProductSearchMatchVerifierInterface
{
    /**
     * Runs the same live catalog search {@see LiveCatalogSearchQueryBuilderInterface} builds for
     * calibration/rank_eval, narrowed to a single candidate document by `_id`, and reports whether that
     * document is actually among the (unbounded) result set for $searchTerm -- i.e. whether a customer
     * genuinely could have found $idProductAbstract by searching $searchTerm, rather than the pair being
     * fabricated client-side before being submitted as a relevance judgment.
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $idProductAbstract
     */
    public function matches(string $searchTerm, string $storeName, string $localeName, int $idProductAbstract): bool;
}
