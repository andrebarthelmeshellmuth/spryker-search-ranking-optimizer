<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer;

use Elastica\Client;
use Spryker\Client\Kernel\AbstractFactory;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolver;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface;
use Spryker\Client\SearchElasticsearch\SearchElasticsearchConfig;
use Spryker\Shared\SearchElasticsearch\ElasticaClient\ElasticaClientFactory;
use SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilder;
use SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface;
use SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculator;
use SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculatorInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToZedRequestInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilder;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilderInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\NeverInvokedStoreClient;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\ProductSearchMatchVerifier;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\ProductSearchMatchVerifierInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunnerInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\RawRelevanceScoreExtractor;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\RawRelevanceScoreExtractorInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\SaturationPointCalibrationSearcher;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\SaturationPointCalibrationSearcherInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\SpecificitySearcher;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\SpecificitySearcherInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Zed\ProductRelevanceJudgmentStub;
use SprykerCommunity\Client\SearchRankingOptimizer\Zed\ProductRelevanceJudgmentStubInterface;

class SearchRankingOptimizerFactory extends AbstractFactory
{
    public function createCalibrationSearcher(): SaturationPointCalibrationSearcherInterface
    {
        return new SaturationPointCalibrationSearcher(
            $this->getElasticaClient(),
            $this->createIndexNameResolver(),
            $this->createRawRelevanceScoreExtractor(),
            $this->createLiveCatalogSearchQueryBuilder(),
        );
    }

    public function createRawRelevanceScoreExtractor(): RawRelevanceScoreExtractorInterface
    {
        return new RawRelevanceScoreExtractor();
    }

    public function createSpecificitySearcher(): SpecificitySearcherInterface
    {
        return new SpecificitySearcher(
            $this->getElasticaClient(),
            $this->createIndexNameResolver(),
            $this->createQuerySpecificityCalculator(),
            $this->getSearchRankingClient()->getSpecificityProbeFieldSearchAnalyzers(),
        );
    }

    public function createQuerySpecificityCalculator(): QuerySpecificityCalculatorInterface
    {
        return new QuerySpecificityCalculator();
    }

    public function createLiveCatalogSearchQueryBuilder(): LiveCatalogSearchQueryBuilderInterface
    {
        return new LiveCatalogSearchQueryBuilder();
    }

    public function createProductSearchMatchVerifier(): ProductSearchMatchVerifierInterface
    {
        return new ProductSearchMatchVerifier(
            $this->getElasticaClient(),
            $this->createIndexNameResolver(),
            $this->createLiveCatalogSearchQueryBuilder(),
        );
    }

    public function createRankEvalRunner(): RankEvalRunnerInterface
    {
        return new RankEvalRunner(
            $this->getElasticaClient(),
            $this->createIndexNameResolver(),
            $this->createLiveCatalogSearchQueryBuilder(),
            $this->createFunctionScoreBuilder(),
            $this->getSearchRankingStorageClient(),
            null,
            $this->getSearchRankingClient(),
        );
    }

    /**
     * COMPOSITION over spryker-community/search-ranking's own Client\SearchRanking\Query\FunctionScoreBuilder
     * — a stateless, dependency-free builder (plain query in, function_score out), so it's instantiated
     * directly here rather than behind a bridge, the same way core Spryker's ProductCatalogSearchQueryPlugin
     * already is in {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilder}.
     * This package already hard-requires spryker-community/search-ranking (composer.json), so this is a
     * real dependency, not a standalone-installability concern.
     */
    public function createFunctionScoreBuilder(): FunctionScoreBuilderInterface
    {
        return new FunctionScoreBuilder();
    }

    public function getSearchRankingStorageClient(): SearchRankingOptimizerToSearchRankingStorageClientInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::CLIENT_SEARCH_RANKING_STORAGE);
    }

    public function getSearchRankingClient(): SearchRankingOptimizerToSearchRankingClientInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::CLIENT_SEARCH_RANKING);
    }

    /**
     * COMPOSITION over the core SearchElasticsearch module, deliberately — the same pattern (and the same
     * reasoning) as the base spryker-community/search-ranking package's own Client factory, and as
     * `SprykerCommunity\Client\SearchDebug\SearchDebugFactory::getElasticaClient()`.
     */
    public function getElasticaClient(): Client
    {
        return $this->createElasticaClientFactory()->createClient(
            $this->createSearchElasticsearchConfig()->getClientConfig(),
        );
    }

    public function createElasticaClientFactory(): ElasticaClientFactory
    {
        return new ElasticaClientFactory();
    }

    public function createSearchElasticsearchConfig(): SearchElasticsearchConfig
    {
        return new SearchElasticsearchConfig();
    }

    public function createIndexNameResolver(): IndexNameResolverInterface
    {
        return new IndexNameResolver(
            $this->createNeverInvokedStoreClient(),
            $this->createSearchElasticsearchConfig(),
        );
    }

    public function createNeverInvokedStoreClient(): NeverInvokedStoreClient
    {
        return new NeverInvokedStoreClient();
    }

    public function createProductRelevanceJudgmentStub(): ProductRelevanceJudgmentStubInterface
    {
        return new ProductRelevanceJudgmentStub($this->getZedRequestClient());
    }

    public function getZedRequestClient(): SearchRankingOptimizerToZedRequestInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::CLIENT_ZED_REQUEST);
    }
}
