<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Client\SearchRankingOptimizer\Search\Rrf;

use Codeception\Test\Unit;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf\RrfScoreCalculator;

/**
 * @group SprykerCommunityTest
 * @group Client
 * @group SearchRankingOptimizer
 * @group Search
 * @group Rrf
 * @group RrfScoreCalculatorTest
 */
class RrfScoreCalculatorTest extends Unit
{
    /**
     * Hand-computed expected order, k=60:
     *
     * lexical = [A, B, C] (ranks 1, 2, 3)
     * semantic = [B, D, A] (ranks 1, 2, 3)
     *
     * A: 1/(60+1) + 1/(60+3) = 0.0163934426... + 0.0158730159... = 0.0322664585...
     * B: 1/(60+2) + 1/(60+1) = 0.0161290323... + 0.0163934426... = 0.0325224749...
     * C: 1/(60+3) + 0 = 0.0158730159...
     * D: 0 + 1/(60+2) = 0.0161290323...
     *
     *   Descending: B (0.03252...) > A (0.03227...) > D (0.01613...) > C (0.01587...)
     *
     * Proves a doc (B) that is only rank 2 lexically can still overtake the lexical rank-1 doc (A) once its
     * strong semantic rank is fused in -- the whole point of RRF over either list alone.
     */
    public function testFuseProducesTheHandComputedOrderForANonTrivialOverlap(): void
    {
        // Arrange
        $calculator = new RrfScoreCalculator();

        // Act
        $fusedOrder = $calculator->fuse(['A', 'B', 'C'], ['B', 'D', 'A'], 60);

        // Assert
        $this->assertSame(['B', 'A', 'D', 'C'], $fusedOrder);
    }

    public function testFuseIncludesADocAppearingInOnlyOneListAtItsOwnSoleContributedScore(): void
    {
        // Arrange -- 'Z' appears only in the semantic list.
        $calculator = new RrfScoreCalculator();

        // Act
        $fusedOrder = $calculator->fuse(['A', 'B'], ['Z'], 60);

        // Assert
        $this->assertContains('Z', $fusedOrder);
        $this->assertCount(3, $fusedOrder);
    }

    public function testFuseOfTwoIdenticalListsPreservesTheirSharedOrder(): void
    {
        // Arrange -- every doc has the identical (lexicalRank, semanticRank) pair as the other list, so
        // every doc's RRF score is exactly double its solo contribution, in the SAME relative order.
        $calculator = new RrfScoreCalculator();

        // Act
        $fusedOrder = $calculator->fuse(['A', 'B', 'C'], ['A', 'B', 'C'], 60);

        // Assert
        $this->assertSame(['A', 'B', 'C'], $fusedOrder);
    }

    /**
     * The graceful-degradation contract: an empty semantic list (semantic retrieval unavailable) must
     * degrade to EXACTLY the lexical list's own order -- mathematically guaranteed since every doc's score
     * then reduces to `1/(rrfK + lexicalRank)`, which is strictly monotonically DECREASING in rank (a
     * smaller/better rank always yields a strictly larger score), so sorting by score descending always
     * reproduces the original rank order.
     */
    public function testFuseWithAnEmptySemanticListDegradesToExactlyTheLexicalOrder(): void
    {
        // Arrange
        $calculator = new RrfScoreCalculator();
        $lexicalRankedDocIds = ['A', 'B', 'C', 'D', 'E'];

        // Act
        $fusedOrder = $calculator->fuse($lexicalRankedDocIds, [], 60);

        // Assert
        $this->assertSame($lexicalRankedDocIds, $fusedOrder);
    }

    /**
     * Symmetric counterpart to {@see testFuseWithAnEmptySemanticListDegradesToExactlyTheLexicalOrder()}.
     */
    public function testFuseWithAnEmptyLexicalListDegradesToExactlyTheSemanticOrder(): void
    {
        // Arrange
        $calculator = new RrfScoreCalculator();
        $semanticRankedDocIds = ['X', 'Y', 'Z'];

        // Act
        $fusedOrder = $calculator->fuse([], $semanticRankedDocIds, 60);

        // Assert
        $this->assertSame($semanticRankedDocIds, $fusedOrder);
    }

    public function testFuseWithBothListsEmptyReturnsAnEmptyResult(): void
    {
        // Arrange
        $calculator = new RrfScoreCalculator();

        // Act
        $fusedOrder = $calculator->fuse([], [], 60);

        // Assert
        $this->assertSame([], $fusedOrder);
    }
}
