<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Query;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\QueryImportanceWeightUpdater;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Query
 * @group QueryImportanceWeightUpdaterTest
 * Add your own group annotations below this line
 */
class QueryImportanceWeightUpdaterTest extends Unit
{
    /**
     * @return void
     */
    public function testUpdatePassesTheIdAndWeightThroughToTheEntityManagerUnchanged(): void
    {
        // Arrange
        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())
            ->method('updateQueryImportanceWeight')
            ->with(7, 3.5);

        $updater = new QueryImportanceWeightUpdater($entityManagerMock);

        // Act
        $updater->update(7, 3.5);
    }
}
