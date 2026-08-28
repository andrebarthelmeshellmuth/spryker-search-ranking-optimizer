<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client;

use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer;

interface SearchRankingOptimizerToSearchRankingClientInterface
{
    /**
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $limit
     *
     * @return array<float>
     */
    public function getCalibrationScores(string $searchTerm, string $storeName, string $localeName, int $limit): array;

    /**
     * @param string $searchTerm
     * @param string $storeName
     * @param float $blendWeight
     */
    public function getCalibrationSpecificity(string $searchTerm, string $storeName, float $blendWeight): float;

    /**
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer $requestTransfer
     */
    public function evaluateRankings(SearchRankingEvaluationRequestTransfer $requestTransfer): SearchRankingEvaluationResponseTransfer;

    /**
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $idProductAbstract
     */
    public function productMatchesSearch(string $searchTerm, string $storeName, string $localeName, int $idProductAbstract): bool;

    /**
     * @return array<int, string>
     */
    public function getRegisteredRankingStrategyNames(): array;
}
