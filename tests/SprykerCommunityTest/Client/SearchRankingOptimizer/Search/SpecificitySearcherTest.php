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
use SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculator;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\NeverInvokedStoreClient;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\SpecificitySearcher;

/**
 * INTEGRATION TEST — talks to a real Elasticsearch/OpenSearch, against the shop's OWN real product page
 * index, same portability tradeoff {@see \SprykerCommunityTest\Client\SearchRankingOptimizer\Search\CalibrationSearcherTest}
 * already accepts and for the same reason: this class exists specifically to fire a real `_termvectors`
 * probe, a throwaway fixture index would test nothing this class actually does. Relies on this demoshop's
 * own seeded catalog data.
 *
 * Every collaborator is constructed exactly as `SearchRankingOptimizerFactory::createSpecificitySearcher()`
 * builds them in production, except the field/analyzer map — hardcoded here to
 * `SearchRankingConfig::getSpecificityProbeFieldSearchAnalyzers()`'s own default (`full-text`,
 * `full-text-boosted` -> `standard`) rather than resolving it through `Client\SearchRanking`, since that
 * default is exactly what this demoshop's real `page.json` schema uses.
 *
 * @group SprykerCommunityTest
 * @group Client
 * @group SearchRankingOptimizer
 * @group Search
 * @group SpecificitySearcherTest
 * @group NeedsSearch
 */
class SpecificitySearcherTest extends Unit
{
    public function testReturnsAPositiveRawSpecificityForARealSearchTermWithRealCorpusEvidence(): void
    {
        // Act
        $rawSpecificity = $this->createSpecificitySearcher()->calculateRawSpecificity('chair', 'DE', 0.7);

        // Assert
        $this->assertGreaterThan(
            0.0,
            $rawSpecificity,
            'The "chair" search term is expected to have real matches (and therefore real idf evidence) in this demoshop\'s seeded catalog.',
        );
    }

    /**
     * A rarer, more specific real term must read as more specific than a common one — proves the probe's
     * idf derivation and the blend into `QuerySpecificityCalculator` are actually wired together, not just
     * that SOME positive number comes back.
     */
    public function testAMoreSpecificRealTermReadsAsMoreSpecificThanACommonOne(): void
    {
        // Arrange
        $specificitySearcher = $this->createSpecificitySearcher();

        // Act
        $commonSpecificity = $specificitySearcher->calculateRawSpecificity('chair', 'DE', 0.7);
        $specificSpecificity = $specificitySearcher->calculateRawSpecificity('topstar chair', 'DE', 0.7);

        // Assert
        $this->assertGreaterThan(
            $commonSpecificity,
            $specificSpecificity,
            'A more specific multi-word query should read as more specific than the generic term alone.',
        );
    }

    public function testEmptySearchTermReturnsZeroWithoutFiringAnyProbe(): void
    {
        // Act
        $rawSpecificity = $this->createSpecificitySearcher()->calculateRawSpecificity('', 'DE', 0.7);

        // Assert
        $this->assertSame(0.0, $rawSpecificity);
    }

    /**
     * A term with zero real corpus evidence must fall back to `0.0`, not error out — same floor
     * {@see \SprykerCommunity\Client\SearchRanking\Search\SpecificityWeightCalculator} itself enforces for
     * this exact scenario.
     */
    public function testATermAbsentFromTheCorpusReturnsZero(): void
    {
        // Act
        $rawSpecificity = $this->createSpecificitySearcher()->calculateRawSpecificity(
            'zzz_no_such_product_will_ever_match_zzz',
            'DE',
            0.7,
        );

        // Assert
        $this->assertSame(0.0, $rawSpecificity);
    }

    /**
     * An empty field/analyzer map means there is nothing to probe against — must short-circuit to `0.0`
     * without firing a request, the same way {@see \SprykerCommunity\Client\SearchRanking\Search\QueryTermFrequencyFetcher}
     * does for the identical case.
     */
    public function testEmptyFieldToSearchAnalyzerMapReturnsZeroWithoutFiringAnyProbe(): void
    {
        // Act
        $rawSpecificity = $this->createSpecificitySearcher([])->calculateRawSpecificity('chair', 'DE', 0.7);

        // Assert
        $this->assertSame(0.0, $rawSpecificity);
    }

    /**
     * A nonexistent index must fail gracefully (0.0), never throw — the probe firing alongside a real
     * calibration run must never be able to break it.
     */
    public function testAnUnresolvableStoreReturnsZeroRatherThanThrowing(): void
    {
        // Act
        $rawSpecificity = $this->createSpecificitySearcher()->calculateRawSpecificity(
            'chair',
            'NO_SUCH_STORE_XYZ',
            0.7,
        );

        // Assert
        $this->assertSame(0.0, $rawSpecificity);
    }

    /**
     * Same composition `SearchRankingOptimizerFactory::createSpecificitySearcher()` uses in production —
     * directly-instantiable value objects, no Locator/container needed.
     *
     * @param array<string, string>|null $fieldToSearchAnalyzer
     */
    protected function createSpecificitySearcher(?array $fieldToSearchAnalyzer = null): SpecificitySearcher
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());
        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), $searchElasticsearchConfig);

        return new SpecificitySearcher(
            $elasticaClient,
            $indexNameResolver,
            new QuerySpecificityCalculator(),
            $fieldToSearchAnalyzer ?? [
                'full-text' => 'standard',
                'full-text-boosted' => 'standard',
            ],
        );
    }
}
