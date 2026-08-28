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

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Optimization
 * @group ParameterVectorMapperRegistryTest
 * @group Portable
 */
class ParameterVectorMapperRegistryTest extends Unit
{
    public function testHasMapperForIsTrueForASeededStrategyName(): void
    {
        // Arrange
        $mapperMock = $this->createMock(ParameterVectorMapperInterface::class);
        $registry = new ParameterVectorMapperRegistry(['adaptive_formula' => $mapperMock]);

        // Act & Assert
        $this->assertTrue($registry->hasMapperFor('adaptive_formula'));
    }

    public function testHasMapperForIsFalseForAnUnknownStrategyName(): void
    {
        // Arrange
        $registry = new ParameterVectorMapperRegistry([
            'adaptive_formula' => $this->createMock(ParameterVectorMapperInterface::class),
        ]);

        // Act & Assert
        $this->assertFalse($registry->hasMapperFor('hybrid'));
    }

    public function testGetMapperForReturnsTheSeededMapper(): void
    {
        // Arrange
        $mapperMock = $this->createMock(ParameterVectorMapperInterface::class);
        $registry = new ParameterVectorMapperRegistry(['adaptive_formula' => $mapperMock]);

        // Act & Assert
        $this->assertSame($mapperMock, $registry->getMapperFor('adaptive_formula'));
    }

    public function testGetMapperForThrowsForAnUnknownStrategyName(): void
    {
        // Arrange
        $registry = new ParameterVectorMapperRegistry([
            'adaptive_formula' => $this->createMock(ParameterVectorMapperInterface::class),
        ]);

        // Assert
        $this->expectException(UnsupportedRankingStrategyException::class);
        $this->expectExceptionMessageMatches('/hybrid/');

        // Act
        $registry->getMapperFor('hybrid');
    }

    public function testGetRegisteredStrategyNamesReturnsEverySeededKey(): void
    {
        // Arrange
        $registry = new ParameterVectorMapperRegistry([
            'adaptive_formula' => $this->createMock(ParameterVectorMapperInterface::class),
            'hybrid' => $this->createMock(ParameterVectorMapperInterface::class),
        ]);

        // Act & Assert
        $this->assertSame(['adaptive_formula', 'hybrid'], $registry->getRegisteredStrategyNames());
    }
}
