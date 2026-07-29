<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query;

use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;

class QueryImportanceWeightUpdater implements QueryImportanceWeightUpdaterInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     */
    public function __construct(protected SearchRankingOptimizerEntityManagerInterface $entityManager)
    {
    }

    /**
     * @param int $idSearchRankingQuery
     * @param float $importanceWeight
     *
     * @return void
     */
    public function update(int $idSearchRankingQuery, float $importanceWeight): void
    {
        $this->entityManager->updateQueryImportanceWeight($idSearchRankingQuery, $importanceWeight);
    }
}
