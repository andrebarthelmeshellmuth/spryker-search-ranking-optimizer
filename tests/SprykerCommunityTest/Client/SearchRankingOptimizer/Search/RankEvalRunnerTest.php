<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Client\SearchRankingOptimizer\Search;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationProductGainTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationQueryTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolver;
use Spryker\Client\SearchElasticsearch\SearchElasticsearchConfig;
use Spryker\Shared\SearchElasticsearch\ElasticaClient\ElasticaClientFactory;
use SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilder;
use SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculator;
use SprykerCommunity\Client\SearchRanking\SearchRankingClient;
use SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingClientInterface;
use SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingUnavailableException;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientBridge;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientBridge;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilder;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\NeverInvokedStoreClient;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\QueryVectorResolver;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\Semantic\InMemorySemanticQueryEmbeddingCache;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\SpecificityWeightingApplier;
use SprykerCommunity\Client\SearchRankingStorage\SearchRankingStorageClient;

/**
 * INTEGRATION TEST — talks to a real Elasticsearch/OpenSearch, against this shop's own real product page
 * index, same portability tradeoff {@see CalibrationSearcherTest} already accepts. Uses two real, known
 * "chair"-matching product abstracts from this demoshop's seeded catalog (ids 9 and 62 — M1006811/M10871)
 * so the rank_eval request's ratings resolve against real documents, not fixtures.
 *
 * @group SprykerCommunityTest
 * @group Client
 * @group SearchRankingOptimizer
 * @group Search
 * @group RankEvalRunnerTest
 * @group NeedsSearch
 */
class RankEvalRunnerTest extends Unit
{
    /**
     * @var int
     */
    protected const ID_PRODUCT_ABSTRACT_BESUCHERSTUHL = 9;

    /**
     * @var int
     */
    protected const ID_PRODUCT_ABSTRACT_KONFERENZSTUHL = 62;

    public function testEvaluateReturnsAScoreForARealRatedQueryWithRealCatalogMatches(): void
    {
        // Arrange
        $requestTransfer = (new SearchRankingEvaluationRequestTransfer())
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setCutoff(10)
            ->addQuery(
                (new SearchRankingEvaluationQueryTransfer())
                    ->setIdSearchRankingQuery(1)
                    ->setSearchTerm('chair')
                    ->setImportanceWeight(1.0)
                    ->addProductGain(
                        (new SearchRankingEvaluationProductGainTransfer())
                            ->setIdProductAbstract(static::ID_PRODUCT_ABSTRACT_BESUCHERSTUHL)
                            ->setGain(3.0),
                    )
                    ->addProductGain(
                        (new SearchRankingEvaluationProductGainTransfer())
                            ->setIdProductAbstract(static::ID_PRODUCT_ABSTRACT_KONFERENZSTUHL)
                            ->setGain(1.0),
                    ),
            );

        // Act
        $responseTransfer = $this->createRankEvalRunner()->evaluate($requestTransfer);

        // Assert
        $queryScores = iterator_to_array($responseTransfer->getQueryScores());
        $this->assertCount(1, $queryScores);
        $this->assertSame(1, $queryScores[0]->getIdSearchRankingQuery());
        $this->assertIsFloat($queryScores[0]->getMetricScore());
        $this->assertGreaterThanOrEqual(0.0, $queryScores[0]->getMetricScoreOrFail());
        $this->assertLessThanOrEqual(1.0, $queryScores[0]->getMetricScoreOrFail(), 'nDCG is normalized to [0, 1].');
    }

    /**
     * Proves the fix for the gap where evaluation never applied search-ranking's own function_score
     * formula (it only ever fired the bare catalog query, so tuning relevanceWeight/metric weights could
     * never move the measured score at all). An explicit override with relevanceWeight=0 and a weight on a
     * metric name that deterministically doesn't exist on ANY document collapses every document's business
     * signal to a uniform 0 — turning ranking into an effectively arbitrary tie order — which must produce
     * a DIFFERENT score than the real, text-relevance-driven baseline for the exact same rated pair.
     *
     * Cutoff deliberately set well above this shop's total "chair" match count (58 in this shop's real
     * catalog) rather than the realistic top-10 window
     * {@see testEvaluateReturnsAScoreForARealRatedQueryWithRealCatalogMatches} uses: this test's whole
     * point is comparing two DIFFERENT rankings of the SAME candidate set, and with an all-tied degenerate
     * override, the ES-internal tie-break order for a top-10 window is not meaningfully correlated with the
     * live baseline's window — both windows can (and, in practice, do) miss both rated documents entirely,
     * giving a false-negative 0.0-vs-0.0 regardless of whether the override actually applied. A cutoff
     * covering every possible match makes both rated documents' presence (and therefore the assertion)
     * independent of that ES tie-break/window-boundary noise.
     */
    public function testEvaluateAppliesAnExplicitRankingConfigurationOverrideInsteadOfTheLiveOne(): void
    {
        // Arrange
        $buildRequestTransfer = (fn (): SearchRankingEvaluationRequestTransfer => (new SearchRankingEvaluationRequestTransfer())
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setCutoff(100)
            ->addQuery(
                (new SearchRankingEvaluationQueryTransfer())
                    ->setIdSearchRankingQuery(1)
                    ->setSearchTerm('chair')
                    ->setImportanceWeight(1.0)
                    ->addProductGain(
                        (new SearchRankingEvaluationProductGainTransfer())
                            ->setIdProductAbstract(static::ID_PRODUCT_ABSTRACT_BESUCHERSTUHL)
                            ->setGain(3.0),
                    )
                    ->addProductGain(
                        (new SearchRankingEvaluationProductGainTransfer())
                            ->setIdProductAbstract(static::ID_PRODUCT_ABSTRACT_KONFERENZSTUHL)
                            ->setGain(1.0),
                    ),
            ));

        $overriddenConfigurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.0)
            ->setRelevanceSaturationPoint(1.0)
            ->setMetricWeights(['definitely_nonexistent_metric_for_this_test' => 1.0]);

        $runner = $this->createRankEvalRunner();

        // Act
        $baselineResponseTransfer = $runner->evaluate($buildRequestTransfer());
        $overriddenResponseTransfer = $runner->evaluate($buildRequestTransfer()->setRankingConfiguration($overriddenConfigurationTransfer));

        // Assert
        $baselineScore = iterator_to_array($baselineResponseTransfer->getQueryScores())[0]->getMetricScoreOrFail();
        $overriddenScore = iterator_to_array($overriddenResponseTransfer->getQueryScores())[0]->getMetricScoreOrFail();
        $this->assertNotSame(
            $baselineScore,
            $overriddenScore,
            'An explicit ranking-configuration override must change the fired query (and therefore the score) -- if it doesn\'t, evaluation is still blind to the ranking formula.',
        );
    }

    /**
     * Specificity-weighting shift/curve-exponent/caching/no-op coverage now lives directly on
     * {@see \SprykerCommunityTest\Client\SearchRankingOptimizer\Search\SpecificityWeightingApplierTest} —
     * that class's own `apply()` is public, so those tests no longer need reflection into a protected
     * `RankEvalRunner` method, following this class's split into single-purpose collaborators.
     */
    public function testEvaluateSkipsAQueryWithNoRatedProductsRatherThanSendingAnEmptyRatingsArray(): void
    {
        // Arrange — rank_eval itself rejects a request with an empty `ratings` array, so a query with no
        // rated products must never reach the actual HTTP call at all.
        $requestTransfer = (new SearchRankingEvaluationRequestTransfer())
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setCutoff(10)
            ->addQuery(
                (new SearchRankingEvaluationQueryTransfer())
                    ->setIdSearchRankingQuery(1)
                    ->setSearchTerm('chair')
                    ->setImportanceWeight(1.0),
            );

        // Act
        $responseTransfer = $this->createRankEvalRunner()->evaluate($requestTransfer);

        // Assert
        $this->assertCount(0, iterator_to_array($responseTransfer->getQueryScores()));
    }

    public function testEvaluateReturnsAnEmptyResponseForARequestWithNoQueriesAtAll(): void
    {
        // Arrange
        $requestTransfer = (new SearchRankingEvaluationRequestTransfer())
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setCutoff(10);

        // Act
        $responseTransfer = $this->createRankEvalRunner()->evaluate($requestTransfer);

        // Assert
        $this->assertCount(0, iterator_to_array($responseTransfer->getQueryScores()));
    }

    /**
     * Proves the P4 graceful-degradation contract end to end: a candidate configuration with `alpha < 1.0`
     * (hybrid) whose embedding client is unavailable must produce EXACTLY the same score a plain
     * `alpha = 1.0` (lexical-only) baseline produces for the same query — i.e. the failure genuinely
     * degrades all the way down to the pre-existing lexical formula, not some broken half-state (a vector
     * silently left partially applied, a script that errors, or a script that CAN'T degrade because
     * `FunctionScoreBuilder`'s own `$queryVector === null` guard was never reached). This is the exact
     * scenario this shop is in right now (P4's `embeddings` Docker service not yet booted — see this
     * package's own README) and the one Step 3's `evaluate-hybrid` console command depends on being
     * airtight before any real embedding service exists to test the non-degraded path against.
     */
    public function testEvaluateDegradesToLexicalOnlyWhenTheEmbeddingClientIsUnavailable(): void
    {
        // Arrange
        $buildRequestTransfer = (fn (): SearchRankingEvaluationRequestTransfer => (new SearchRankingEvaluationRequestTransfer())
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setCutoff(100)
            ->addQuery(
                (new SearchRankingEvaluationQueryTransfer())
                    ->setIdSearchRankingQuery(1)
                    ->setSearchTerm('chair')
                    ->setImportanceWeight(1.0)
                    ->addProductGain(
                        (new SearchRankingEvaluationProductGainTransfer())
                            ->setIdProductAbstract(static::ID_PRODUCT_ABSTRACT_BESUCHERSTUHL)
                            ->setGain(3.0),
                    )
                    ->addProductGain(
                        (new SearchRankingEvaluationProductGainTransfer())
                            ->setIdProductAbstract(static::ID_PRODUCT_ABSTRACT_KONFERENZSTUHL)
                            ->setGain(1.0),
                    ),
            ));

        $sharedConfiguration = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.5)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['pdp_impressions' => 1.0]);

        $lexicalConfigurationTransfer = (clone $sharedConfiguration)->setAlpha(1.0);
        $hybridConfigurationWithBrokenEmbeddingClientTransfer = (clone $sharedConfiguration)->setAlpha(0.5);

        $lexicalRunner = $this->createRankEvalRunner();
        $hybridRunnerWithThrowingEmbeddingClient = $this->createRankEvalRunnerWithThrowingEmbeddingClient();

        // Act
        $lexicalResponseTransfer = $lexicalRunner->evaluate($buildRequestTransfer()->setRankingConfiguration($lexicalConfigurationTransfer));
        $hybridResponseTransfer = $hybridRunnerWithThrowingEmbeddingClient->evaluate($buildRequestTransfer()->setRankingConfiguration($hybridConfigurationWithBrokenEmbeddingClientTransfer));

        // Assert
        $lexicalScore = iterator_to_array($lexicalResponseTransfer->getQueryScores())[0]->getMetricScoreOrFail();
        $hybridScore = iterator_to_array($hybridResponseTransfer->getQueryScores())[0]->getMetricScoreOrFail();
        $this->assertSame(
            $lexicalScore,
            $hybridScore,
            'An unavailable embedding service must degrade a hybrid candidate to EXACTLY the lexical-only score -- if it doesn\'t, graceful degradation is broken.',
        );
    }

    /**
     * Same composition `SearchRankingOptimizerFactory::createRankEvalRunner()` uses in production —
     * including the real `SearchRankingOptimizerToSearchRankingClientBridge`, so this exercises the actual
     * project-override-aware `isSpecificityWeightingEnabled()` resolution (off by default, since nothing in
     * this test environment overrides it), not the old, no-longer-needed hardcoded-Shared-static fallback.
     */
    protected function createRankEvalRunner(): RankEvalRunner
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());
        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), $searchElasticsearchConfig);
        $searchRankingClient = new SearchRankingOptimizerToSearchRankingClientBridge(new SearchRankingClient());

        return new RankEvalRunner(
            $elasticaClient,
            $indexNameResolver,
            new LiveCatalogSearchQueryBuilder(),
            new FunctionScoreBuilder(),
            new SearchRankingOptimizerToSearchRankingStorageClientBridge(new SearchRankingStorageClient()),
            new SpecificityWeightingApplier($elasticaClient, new QuerySpecificityCalculator(), $searchRankingClient),
        );
    }

    /**
     * A `RankEvalRunner` wired with a real embedding-client stub that ALWAYS throws
     * {@see \SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingUnavailableException} — the exact
     * "embedding service unreachable" state this shop is in right now (P4's `embeddings` Docker service
     * not yet booted), used to prove {@see testEvaluateDegradesToLexicalOnlyWhenTheEmbeddingClientIsUnavailable()}'s
     * graceful-degradation contract.
     *
     * @throws \SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingUnavailableException
     */
    protected function createRankEvalRunnerWithThrowingEmbeddingClient(): RankEvalRunner
    {
        $throwingEmbeddingClient = new class implements EmbeddingClientInterface {
            // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- signature is fixed by the
            //   interface this test double implements; never actually reached by this test.
            /**
             * @param string $text
             *
             * @throws \SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingUnavailableException
             */
            public function embed(string $text): array
            {
                // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter
                throw new EmbeddingUnavailableException('Embedding service unavailable (test double).');
            }
        };

        return $this->createRankEvalRunnerWithEmbeddingClient($throwingEmbeddingClient);
    }

    /**
     * @param \SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingClientInterface $embeddingClient
     */
    protected function createRankEvalRunnerWithEmbeddingClient(EmbeddingClientInterface $embeddingClient): RankEvalRunner
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());
        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), $searchElasticsearchConfig);
        $searchRankingClient = new SearchRankingOptimizerToSearchRankingClientBridge(new SearchRankingClient());

        return new RankEvalRunner(
            $elasticaClient,
            $indexNameResolver,
            new LiveCatalogSearchQueryBuilder(),
            new FunctionScoreBuilder(),
            new SearchRankingOptimizerToSearchRankingStorageClientBridge(new SearchRankingStorageClient()),
            new SpecificityWeightingApplier($elasticaClient, new QuerySpecificityCalculator(), $searchRankingClient),
            new QueryVectorResolver($embeddingClient, new InMemorySemanticQueryEmbeddingCache()),
        );
    }
}
