<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client;

interface SearchRankingOptimizerToSearchRankingClientInterface
{
    /**
     * @return bool
     */
    public function isSpecificityWeightingEnabled(): bool;

    /**
     * @return array<string, string>
     */
    public function getSpecificityProbeFieldSearchAnalyzers(): array;
}
