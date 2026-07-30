<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Elastica\Query;

interface LiveCatalogSearchQueryBuilderInterface
{
    /**
     * Specification:
     * - Builds the SAME catalog search-string query shape the live storefront uses for $searchTerm (base
     *   product full-text query + store/locale/is_active/is_active_in_date_range filters) — the shared
     *   building block behind both {@see CalibrationSearcherInterface} (which additionally sets
     *   `explain`/`size`) and the rank_eval evaluation runner (which needs the bare query clause, no
     *   result-set concerns at all).
     * - $limit is optional: null leaves the query's size unset (the caller decides, or doesn't care —
     *   e.g. rank_eval only ever reads the `query` clause back out, never executes this as a search
     *   itself).
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int|null $limit
     *
     * @return \Elastica\Query
     */
    public function build(string $searchTerm, string $storeName, string $localeName, ?int $limit = null): Query;
}
