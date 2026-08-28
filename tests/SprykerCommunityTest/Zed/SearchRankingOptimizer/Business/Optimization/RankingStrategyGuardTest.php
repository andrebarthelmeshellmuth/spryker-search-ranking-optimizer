<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Optimization;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\UnsupportedRankingStrategyException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\ParameterVectorMapperInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\ParameterVectorMapperRegistry;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\RankingStrategyGuard;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Optimization
 * @group RankingStrategyGuardTest
 * @group Portable
 */
class RankingStrategyGuardTest extends Unit
{
    public function testPassesWhenOnlyAdaptiveFormulaIsRegistered(): void
    {
        // Arrange
        $guard = $this->createGuard(['adaptive_formula'], ['adaptive_formula']);

        // Act
        $guard->assertActiveStrategyIsTunable();

        // Assert
        $this->addToAssertionCount(1);
    }

    public function testPassesWhenAnAdditionalStrategyHasItsOwnRegisteredMapper(): void
    {
        // Arrange
        $guard = $this->createGuard(['adaptive_formula', 'hybrid'], ['adaptive_formula', 'hybrid']);

        // Act
        $guard->assertActiveStrategyIsTunable();

        // Assert
        $this->addToAssertionCount(1);
    }

    public function testThrowsNamingTheStrategyWhenARegisteredStrategyHasNoMapper(): void
    {
        // Arrange
        $guard = $this->createGuard(['adaptive_formula', 'hybrid'], ['adaptive_formula']);

        // Assert
        $this->expectException(UnsupportedRankingStrategyException::class);
        $this->expectExceptionMessageMatches('/"hybrid"/');

        // Act
        $guard->assertActiveStrategyIsTunable();
    }

    public function testThrowsListingEveryUnmappedStrategy(): void
    {
        // Arrange
        $guard = $this->createGuard(['adaptive_formula', 'hybrid', 'neural_rerank'], ['adaptive_formula']);

        // Act
        try {
            $guard->assertActiveStrategyIsTunable();
            $this->fail('Expected UnsupportedRankingStrategyException.');
        } catch (UnsupportedRankingStrategyException $exception) {
            // Assert
            $this->assertStringContainsString('hybrid', $exception->getMessage());
            $this->assertStringContainsString('neural_rerank', $exception->getMessage());
        }
    }

    public function testPassesOnAnEmptyStrategyList(): void
    {
        // Arrange
        $guard = $this->createGuard([], ['adaptive_formula']);

        // Act
        $guard->assertActiveStrategyIsTunable();

        // Assert
        $this->addToAssertionCount(1);
    }

    /**
     * @param array<int, string> $registeredStrategyNames
     * @param array<int, string> $mappedStrategyNames
     */
    protected function createGuard(array $registeredStrategyNames, array $mappedStrategyNames): RankingStrategyGuard
    {
        $searchRankingClientMock = $this->createMock(SearchRankingOptimizerToSearchRankingClientInterface::class);
        $searchRankingClientMock->method('getRegisteredRankingStrategyNames')->willReturn($registeredStrategyNames);

        $mappersByStrategyName = [];

        foreach ($mappedStrategyNames as $mappedStrategyName) {
            $mappersByStrategyName[$mappedStrategyName] = $this->createMock(ParameterVectorMapperInterface::class);
        }

        return new RankingStrategyGuard($searchRankingClientMock, new ParameterVectorMapperRegistry($mappersByStrategyName));
    }
}
