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
use SprykerCommunity\Client\SearchRanking\Dependency\Client\SearchRankingToStorageClientInterface;
use SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface;
use SprykerCommunity\Client\SearchRanking\Search\NavigationalRelevanceWeightShiftCalculator;
use SprykerCommunity\Client\SearchRanking\Search\NavigationalRelevanceWeightShiftCalculatorInterface;
use SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculatorInterface;
use SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingClientInterface;
use SprykerCommunity\Client\SearchRanking\Semantic\SemanticQueryEmbeddingCacheInterface;
use SprykerCommunity\Client\SearchRanking\Semantic\TextEmbeddingsInferenceClient;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToZedRequestInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilder;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilderInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\NeverInvokedStoreClient;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\ProductSearchMatchVerifier;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\ProductSearchMatchVerifierInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\QueryContextResolver;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\QueryContextResolverInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\QueryVectorResolver;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\QueryVectorResolverInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunnerInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\RawRelevanceScoreExtractor;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\RawRelevanceScoreExtractorInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf\RrfCandidateQueryBuilder;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf\RrfCandidateQueryBuilderInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf\RrfEvaluationQueryBuilder;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf\RrfEvaluationQueryBuilderInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf\RrfScoreCalculator;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf\RrfScoreCalculatorInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\SaturationPointCalibrationSearcher;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\SaturationPointCalibrationSearcherInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\Semantic\InMemorySemanticQueryEmbeddingCache;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\SpecificitySearcher;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\SpecificitySearcherInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\SpecificityWeightingApplier;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\SpecificityWeightingApplierInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Zed\ProductRelevanceJudgmentStub;
use SprykerCommunity\Client\SearchRankingOptimizer\Zed\ProductRelevanceJudgmentStubInterface;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

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
        return $this->getSearchRankingClient()->createQuerySpecificityCalculator();
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
            $this->createSpecificityWeightingApplier(),
            $this->createQueryVectorResolver(),
            $this->createQueryContextResolver(),
            $this->createRrfEvaluationQueryBuilder(),
        );
    }

    /**
     * Bundles the IDF probe + specificity math + `isSpecificityWeightingEnabled()` project-override gating
     * into one collaborator -- see {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\SpecificityWeightingApplier}'s
     * own docblock.
     */
    public function createSpecificityWeightingApplier(): SpecificityWeightingApplierInterface
    {
        return new SpecificityWeightingApplier(
            $this->getElasticaClient(),
            $this->createQuerySpecificityCalculator(),
            $this->getSearchRankingClient(),
        );
    }

    public function createQueryVectorResolver(): QueryVectorResolverInterface
    {
        return new QueryVectorResolver(
            $this->createEmbeddingClient(),
            $this->createSemanticQueryEmbeddingCache(),
        );
    }

    public function createQueryContextResolver(): QueryContextResolverInterface
    {
        return new QueryContextResolver(
            $this->getElasticaClient(),
            $this->createIndexNameResolver(),
            $this->getStorageClient(),
            $this->createNavigationalRelevanceWeightShiftCalculator(),
        );
    }

    public function createRrfEvaluationQueryBuilder(): RrfEvaluationQueryBuilderInterface
    {
        return new RrfEvaluationQueryBuilder(
            $this->getElasticaClient(),
            $this->createLiveCatalogSearchQueryBuilder(),
            $this->createRrfScoreCalculator(),
            $this->createRrfCandidateQueryBuilder(),
        );
    }

    public function createRrfScoreCalculator(): RrfScoreCalculatorInterface
    {
        return new RrfScoreCalculator();
    }

    /**
     * Stateless/pure math (no IO, no Store-singleton dependency) — safe to construct directly here, same
     * reasoning as {@see createEmbeddingClient()}'s own docblock.
     */
    public function createNavigationalRelevanceWeightShiftCalculator(): NavigationalRelevanceWeightShiftCalculatorInterface
    {
        return new NavigationalRelevanceWeightShiftCalculator();
    }

    public function createRrfCandidateQueryBuilder(): RrfCandidateQueryBuilderInterface
    {
        return new RrfCandidateQueryBuilder();
    }

    /**
     * Constructed directly rather than routed through the `SearchRankingOptimizerToSearchRankingClient*`
     * bridge (unlike {@see createFunctionScoreBuilder()}/{@see createQuerySpecificityCalculator()} above):
     * `SprykerCommunity\Client\SearchRanking\SearchRankingClientInterface` does not expose an embedding
     * client/cache factory method at all -- adding one is out of this package's scope. This class (like
     * `TextEmbeddingsInferenceClient` itself) has no Store-singleton dependency, only a plain config
     * string, so constructing it directly here is safe in this package's Zed/console execution context --
     * same reasoning `RankEvalRunner`'s own docblock gives for reusing `QuerySpecificityCalculator`
     * directly while reimplementing only the Store-dependent IO half of the specificity probe.
     */
    public function createEmbeddingClient(): EmbeddingClientInterface
    {
        return new TextEmbeddingsInferenceClient(SearchRankingOptimizerConfig::getEmbeddingServiceUrl());
    }

    /**
     * @see createEmbeddingClient() for why this is constructed directly instead of via the bridge -- see
     * {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\Semantic\InMemorySemanticQueryEmbeddingCache}'s
     * own docblock for why THIS class, not search-ranking's own Redis-backed one.
     */
    public function createSemanticQueryEmbeddingCache(): SemanticQueryEmbeddingCacheInterface
    {
        return new InMemorySemanticQueryEmbeddingCache();
    }

    public function createFunctionScoreBuilder(): FunctionScoreBuilderInterface
    {
        return $this->getSearchRankingClient()->createFunctionScoreBuilder();
    }

    public function getSearchRankingStorageClient(): SearchRankingOptimizerToSearchRankingStorageClientInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::CLIENT_SEARCH_RANKING_STORAGE);
    }

    /**
     * Reuses search-ranking's own `SearchRankingToStorageClientInterface` directly, unwrapped -- the same
     * "no redundant package-specific bridge for a type this package already depends on via search-ranking's
     * Client layer" posture as {@see createFunctionScoreBuilder()}/{@see createQuerySpecificityCalculator()}
     * above. Backs {@see RankEvalRunner}'s Intent-Aware Alpha SKU-identifier lookup — see that class's own
     * `$storageClient` constructor docblock.
     */
    public function getStorageClient(): SearchRankingToStorageClientInterface
    {
        return $this->getProvidedDependency(SearchRankingOptimizerDependencyProvider::CLIENT_STORAGE);
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
