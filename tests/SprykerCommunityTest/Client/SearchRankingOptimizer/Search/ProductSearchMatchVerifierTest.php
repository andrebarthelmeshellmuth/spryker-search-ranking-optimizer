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
use SprykerCommunity\Client\SearchRankingOptimizer\Search\ProductSearchMatchVerifier;

/**
 * INTEGRATION TEST — talks to a real Elasticsearch/OpenSearch, against this shop's own real product page
 * index, same portability tradeoff {@see CalibrationSearcherTest}/{@see RankEvalRunnerTest} already accept.
 * Reuses the same real, known "chair"-matching product abstract (id 9, "Besucherstuhl"/M1006811)
 * {@see RankEvalRunnerTest} already relies on.
 *
 * @group SprykerCommunityTest
 * @group Client
 * @group SearchRankingOptimizer
 * @group Search
 * @group ProductSearchMatchVerifierTest
 */
class ProductSearchMatchVerifierTest extends Unit
{
    /**
     * @var int
     */
    protected const ID_PRODUCT_ABSTRACT_BESUCHERSTUHL = 9;

    /**
     * @return void
     */
    public function testMatchesReturnsTrueForARealProductAmongTheRealSearchResultsForATerm(): void
    {
        // Act
        $matches = $this->createVerifier()->matches('chair', 'DE', 'en_US', static::ID_PRODUCT_ABSTRACT_BESUCHERSTUHL);

        // Assert
        $this->assertTrue($matches);
    }

    /**
     * @return void
     */
    public function testMatchesReturnsFalseWhenTheSearchTermHasNoRealCatalogMatches(): void
    {
        // Act
        $matches = $this->createVerifier()->matches(
            'zzz_no_such_product_will_ever_match_zzz',
            'DE',
            'en_US',
            static::ID_PRODUCT_ABSTRACT_BESUCHERSTUHL,
        );

        // Assert
        $this->assertFalse($matches);
    }

    /**
     * @return void
     */
    public function testMatchesReturnsFalseForAProductIdThatDoesNotExist(): void
    {
        // Act
        $matches = $this->createVerifier()->matches('chair', 'DE', 'en_US', 999999999);

        // Assert
        $this->assertFalse($matches);
    }

    /**
     * Same composition `SearchRankingOptimizerFactory::createProductSearchMatchVerifier()` uses in
     * production — directly-instantiable value objects, no Locator/container needed.
     *
     * @return \SprykerCommunity\Client\SearchRankingOptimizer\Search\ProductSearchMatchVerifier
     */
    protected function createVerifier(): ProductSearchMatchVerifier
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());
        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), $searchElasticsearchConfig);

        return new ProductSearchMatchVerifier($elasticaClient, $indexNameResolver, new LiveCatalogSearchQueryBuilder());
    }
}
