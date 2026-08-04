<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

interface SpecificitySearcherInterface
{
    /**
     * Specification:
     * - Fires ONE `_termvectors` probe for $searchTerm directly against Elasticsearch — NOT a real catalog
     *   query at all, unlike {@see CalibrationSearcherInterface::searchScores()} — and returns the SAME
     *   blended raw specificity value {@see \SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculator}
     *   would compute live, using this project's own field/analyzer map (see
     *   {@see \SprykerCommunity\Client\SearchRanking\SearchRankingClientInterface::getSpecificityProbeFieldSearchAnalyzers()}).
     * - $storeName is used only to resolve the index name (picked by the admin at upload time, since Zed
     *   has no implicit "current store") — this project's page index is one-per-store-multiple-locales, so
     *   no locale parameter is needed at all.
     * - $blendWeight is the CURRENTLY CONFIGURED `specificityBlendWeight` (alpha), passed in by the caller
     *   rather than read from a config default here — calibration's whole point is finding the saturation
     *   point for THIS blend weight's raw output distribution, so it must use the live value, not an
     *   independent one.
     * - Returns `0.0` when no query term carries any real corpus evidence (same floor
     *   {@see \SprykerCommunity\Client\SearchRanking\Search\SpecificityWeightCalculator} itself enforces),
     *   or when the probe itself fails — never throws.
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param float $blendWeight
     */
    public function calculateRawSpecificity(string $searchTerm, string $storeName, float $blendWeight): float;
}
