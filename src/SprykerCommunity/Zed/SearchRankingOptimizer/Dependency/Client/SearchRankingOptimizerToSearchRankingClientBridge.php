<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client;

use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer;

class SearchRankingOptimizerToSearchRankingClientBridge implements SearchRankingOptimizerToSearchRankingClientInterface
{
    /**
     * @var \SprykerCommunity\Client\SearchRankingOptimizer\SearchRankingOptimizerClientInterface
     */
    protected $searchRankingOptimizerClient;

    /**
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\SearchRankingOptimizerClientInterface $searchRankingOptimizerClient
     */
    public function __construct($searchRankingOptimizerClient)
    {
        $this->searchRankingOptimizerClient = $searchRankingOptimizerClient;
    }

    /**
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $limit
     *
     * @return array<float>
     */
    public function getCalibrationScores(string $searchTerm, string $storeName, string $localeName, int $limit): array
    {
        return $this->searchRankingOptimizerClient->getCalibrationScores($searchTerm, $storeName, $localeName, $limit);
    }

    /**
     * @param string $searchTerm
     * @param string $storeName
     * @param float $blendWeight
     *
     * @return float
     */
    public function getCalibrationSpecificity(string $searchTerm, string $storeName, float $blendWeight): float
    {
        return $this->searchRankingOptimizerClient->getCalibrationSpecificity($searchTerm, $storeName, $blendWeight);
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer $requestTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer
     */
    public function evaluateRankings(SearchRankingEvaluationRequestTransfer $requestTransfer): SearchRankingEvaluationResponseTransfer
    {
        return $this->searchRankingOptimizerClient->evaluateRankings($requestTransfer);
    }

    /**
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $idProductAbstract
     *
     * @return bool
     */
    public function productMatchesSearch(string $searchTerm, string $storeName, string $localeName, int $idProductAbstract): bool
    {
        return $this->searchRankingOptimizerClient->productMatchesSearch($searchTerm, $storeName, $localeName, $idProductAbstract);
    }
}
