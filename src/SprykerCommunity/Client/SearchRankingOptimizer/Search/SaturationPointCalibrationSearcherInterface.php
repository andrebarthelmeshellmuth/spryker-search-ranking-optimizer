<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

interface SaturationPointCalibrationSearcherInterface
{
    /**
     * Specification:
     * - Fires the SAME catalog search-string query shape the live storefront uses for $searchTerm
     *   (base product full-text query + store/locale/is_active/is_active_in_date_range filters), with
     *   `explain: true` and the query limited to $limit hits — directly against Elasticsearch, NOT
     *   through `Client\Catalog`/`Client\Search`.
     * - Both of those modules are unusable from Zed in this shop: their query-building/index-resolution
     *   internally requires an HTTP-request-scoped "current store" (`Client\Store::getCurrentStore()`,
     *   backed by `SPRYKER_DYNAMIC_STORE_MODE`) that plain Zed console/controller execution never has —
     *   both throw a `TypeError` before any calibration-specific code even runs. $storeName and
     *   $localeName are passed in explicitly instead (picked by the admin at upload time, since Zed has
     *   no implicit "current store" to fall back on).
     * - Returns each hit's raw text-relevance score — BEFORE any `function_score` wrapper, regardless of
     *   whether one happens to be wired in — via {@see RawRelevanceScoreExtractorInterface}.
     * - Returns fewer than $limit scores when the term matched fewer products; an empty array when it
     *   matched none.
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $limit
     *
     * @return array<float>
     */
    public function searchScores(string $searchTerm, string $storeName, string $localeName, int $limit): array;
}
