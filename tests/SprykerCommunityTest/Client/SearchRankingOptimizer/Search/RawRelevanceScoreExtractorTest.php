<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Client\SearchRankingOptimizer\Search;

use Codeception\Test\Unit;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\RawRelevanceScoreExtractor;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Client
 * @group@group SearchRankingOptimizer
 * @group Search
 * @group RawRelevanceScoreExtractorTest
 * Add your own group annotations below this line
 */
class RawRelevanceScoreExtractorTest extends Unit
{
    /**
     * @return void
     */
    public function testFallsBackToTheRootValueWhenNotFunctionScoreWrapped(): void
    {
        // Arrange
        $extractor = new RawRelevanceScoreExtractor();
        $explanation = [
            'value' => 16.826042,
            'description' => 'sum of:',
            'details' => [
                ['value' => 16.826042, 'description' => 'weight(full-text:chair in 7) [PerFieldSimilarity], result of:', 'details' => []],
            ],
        ];

        // Act
        $score = $extractor->extract($explanation);

        // Assert
        $this->assertSame(16.826042, $score);
    }

    /**
     * @return void
     */
    public function testReturnsTheWrappedQueryScoreWhenFunctionScoreWrapped(): void
    {
        // Arrange
        $extractor = new RawRelevanceScoreExtractor();
        $explanation = [
            'value' => 5.2405,
            'description' => 'sum of:',
            'details' => [
                [
                    'value' => 5.2405,
                    'description' => 'min of:',
                    'details' => [
                        [
                            'value' => 5.2405,
                            'description' => 'script score function, computed with script:...',
                            'details' => [
                                ['value' => 19.7407, 'description' => '_score: ', 'details' => []],
                            ],
                        ],
                        ['value' => 3.4028235E38, 'description' => 'maxBoost', 'details' => []],
                    ],
                ],
            ],
        ];

        // Act
        $score = $extractor->extract($explanation);

        // Assert
        $this->assertSame(19.7407, $score);
    }

    /**
     * The script-score node can sit arbitrarily deep — the extractor must find it regardless of nesting,
     * not just at a fixed depth.
     *
     * @return void
     */
    public function testFindsTheScriptScoreNodeRegardlessOfNestingDepth(): void
    {
        // Arrange
        $extractor = new RawRelevanceScoreExtractor();
        $explanation = [
            'value' => 2.0,
            'description' => 'outer wrapper',
            'details' => [
                [
                    'value' => 2.0,
                    'description' => 'inner wrapper',
                    'details' => [
                        [
                            'value' => 2.0,
                            'description' => 'script score function, ...',
                            'details' => [
                                ['value' => 8.0, 'description' => '_score: ', 'details' => []],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Act
        $score = $extractor->extract($explanation);

        // Assert
        $this->assertSame(8.0, $score);
    }

    /**
     * A zero-valued node is filter-clause noise (e.g. an inactive-facet filter) and must not be
     * descended into, even if it happens to contain a deeper non-zero value.
     *
     * @return void
     */
    public function testDoesNotDescendIntoAZeroValuedNode(): void
    {
        // Arrange
        $extractor = new RawRelevanceScoreExtractor();
        $explanation = [
            'value' => 12.5,
            'description' => 'sum of:',
            'details' => [
                [
                    'value' => 0.0,
                    'description' => 'match on required clause (0)',
                    'details' => [
                        ['value' => 1.0, 'description' => '*:*', 'details' => []],
                    ],
                ],
                ['value' => 12.5, 'description' => 'weight(full-text:chair in 7) [PerFieldSimilarity], result of:', 'details' => []],
            ],
        ];

        // Act
        $score = $extractor->extract($explanation);

        // Assert
        $this->assertSame(12.5, $score);
    }
}
