<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Client\SearchRankingOptimizer\Search\Rrf;

use Codeception\Test\Unit;
use Elastica\Query\FunctionScore;
use Elastica\Query\MatchNone;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf\RrfCandidateQueryBuilder;

/**
 * @group SprykerCommunityTest
 * @group Client
 * @group SearchRankingOptimizer
 * @group Search
 * @group Rrf
 * @group RrfCandidateQueryBuilderTest
 */
class RrfCandidateQueryBuilderTest extends Unit
{
    public function testBuildProducesAnIdsRestrictedFunctionScoreWithDescendingWeightsMatchingInputOrder(): void
    {
        // Arrange
        $builder = new RrfCandidateQueryBuilder();
        $fusedRankedDocIds = ['doc-1', 'doc-2', 'doc-3'];

        // Act
        $query = $builder->build($fusedRankedDocIds);

        // Assert
        $this->assertInstanceOf(FunctionScore::class, $query);
        $queryArray = $query->toArray();

        $this->assertSame(['doc-1', 'doc-2', 'doc-3'], $queryArray['function_score']['query']['ids']['values']);
        $this->assertSame('replace', $queryArray['function_score']['boost_mode']);
        $this->assertSame('max', $queryArray['function_score']['score_mode']);

        $functions = $queryArray['function_score']['functions'];
        $this->assertCount(3, $functions);

        // Best-ranked doc (index 0) gets the highest weight, decreasing by 1 per rank position.
        $this->assertSame(3.0, $functions[0]['weight']);
        $this->assertSame('doc-1', $functions[0]['filter']['term']['_id']['value']);

        $this->assertSame(2.0, $functions[1]['weight']);
        $this->assertSame('doc-2', $functions[1]['filter']['term']['_id']['value']);

        $this->assertSame(1.0, $functions[2]['weight']);
        $this->assertSame('doc-3', $functions[2]['filter']['term']['_id']['value']);
    }

    public function testBuildWithAnEmptyFusedListReturnsAMatchNoneQuery(): void
    {
        // Arrange
        $builder = new RrfCandidateQueryBuilder();

        // Act
        $query = $builder->build([]);

        // Assert
        $this->assertInstanceOf(MatchNone::class, $query);
    }
}
