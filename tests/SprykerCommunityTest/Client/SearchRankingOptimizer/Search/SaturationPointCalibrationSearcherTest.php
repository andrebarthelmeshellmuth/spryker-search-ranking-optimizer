<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Client\SearchRankingOptimizer\Search;

use Codeception\Test\Unit;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolver;
use Spryker\Client\SearchElasticsearch\SearchElasticsearchConfig;
use Spryker\Shared\SearchElasticsearch\ElasticaClient\ElasticaClientFactory;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilder;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\NeverInvokedStoreClient;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\RawRelevanceScoreExtractor;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\SaturationPointCalibrationSearcher;

/**
 * INTEGRATION TEST — talks to a real Elasticsearch/OpenSearch, against the shop's OWN real product page
 * index (unlike search-ranking's `FunctionScoreExecutionTest`, which owns a throwaway test index).
 * `SaturationPointCalibrationSearcher` exists specifically to sample real relevance scores from the real catalog — a
 * throwaway fixture index would test nothing this class actually does. Relies on this demoshop's own
 * seeded catalog data (a "chair" search returning real office-furniture hits is exercised elsewhere in
 * this project's own manual verification too), same portability tradeoff search-debug's own
 * engine-verification tests already accept for this reason.
 *
 * Every collaborator is constructed exactly as `SearchRankingOptimizerFactory` builds them in production —
 * no mocks anywhere in this test, real Elastica\Client, real IndexNameResolver, real
 * RawRelevanceScoreExtractor — so this closes the one gap a mocked unit test structurally cannot: whether
 * `buildQuery()`'s composed query plugins actually execute against a real engine and come back with a real
 * `_explanation` the extractor can parse.
 *
 * @group SprykerCommunityTest
 * @group Client
 * @group SearchRankingOptimizer
 * @group Search
 * @group SaturationPointCalibrationSearcherTest
 * @group NeedsSearch
 */
class SaturationPointCalibrationSearcherTest extends Unit
{
    public function testSearchScoresReturnsRealNonZeroRelevanceScoresForATermWithRealCatalogMatches(): void
    {
        // Act
        $scores = $this->createCalibrationSearcher()->searchScores('chair', 'DE', 'en_US', 5);

        // Assert
        $this->assertNotEmpty($scores, 'The "chair" search term is expected to have real matches in this demoshop\'s seeded catalog.');
        $this->assertLessThanOrEqual(5, count($scores), 'searchScores() must respect the requested limit.');

        foreach ($scores as $score) {
            $this->assertIsFloat($score);
            $this->assertGreaterThan(0.0, $score, 'A real text-relevance explanation score for a matched document must be positive.');
        }
    }

    public function testSearchScoresReturnsAnEmptyArrayForATermWithNoRealCatalogMatches(): void
    {
        // Act
        $scores = $this->createCalibrationSearcher()->searchScores('zzz_no_such_product_will_ever_match_zzz', 'DE', 'en_US', 5);

        // Assert
        $this->assertSame([], $scores);
    }

    /**
     * Same composition `SearchRankingOptimizerFactory::createCalibrationSearcher()` uses in production —
     * directly-instantiable value objects, no Locator/container needed.
     */
    protected function createCalibrationSearcher(): SaturationPointCalibrationSearcher
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());
        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), $searchElasticsearchConfig);

        return new SaturationPointCalibrationSearcher($elasticaClient, $indexNameResolver, new RawRelevanceScoreExtractor(), new LiveCatalogSearchQueryBuilder());
    }
}
