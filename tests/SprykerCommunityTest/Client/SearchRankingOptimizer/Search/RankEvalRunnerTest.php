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
use ReflectionMethod;
use ReflectionProperty;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolver;
use Spryker\Client\SearchElasticsearch\SearchElasticsearchConfig;
use Spryker\Shared\SearchElasticsearch\ElasticaClient\ElasticaClientFactory;
use SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilder;
use SprykerCommunity\Client\SearchRanking\SearchRankingClient;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientBridge;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientBridge;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilder;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\NeverInvokedStoreClient;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner;
use SprykerCommunity\Client\SearchRankingStorage\SearchRankingStorageClient;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

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

    /**
     * @return void
     */
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
     * Cutoff deliberately set well above this shop's total "chair" match count (58, confirmed live) rather
     * than the realistic top-10 window {@see testEvaluateReturnsAScoreForARealRatedQueryWithRealCatalogMatches}
     * uses: this test's whole point is comparing two DIFFERENT rankings of the SAME candidate set, and with
     * an all-tied degenerate override, the ES-internal tie-break order for a top-10 window is not
     * meaningfully correlated with the live baseline's window — both windows can (and, confirmed live, do)
     * miss both rated documents entirely, giving a false-negative 0.0-vs-0.0 regardless of whether the
     * override actually applied. A cutoff covering every possible match makes both rated documents' presence
     * (and therefore the assertion) independent of that ES tie-break/window-boundary noise.
     *
     * @return void
     */
    public function testEvaluateAppliesAnExplicitRankingConfigurationOverrideInsteadOfTheLiveOne(): void
    {
        // Arrange
        $buildRequestTransfer = function (): SearchRankingEvaluationRequestTransfer {
            return (new SearchRankingEvaluationRequestTransfer())
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
                );
        };

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
     * Proves Task #40's fix directly at the source: before it, `entropyProbeResultSize`/
     * `entropyWeightExponent`/`entropyWeightShiftMagnitude` were carried on every
     * `SearchRankingConfigurationStorageTransfer` but never actually read anywhere in this evaluation path.
     * Deliberately invokes the protected `applyEntropyWeighting()` directly (via reflection) against a
     * REAL base query and this shop's real "chair"-matching catalog data, rather than asserting on the
     * downstream `rank_eval` nDCG score -- an nDCG-based assertion turned out to be the wrong tool here: for
     * this query, real Lucene `_score`s across matching documents differ by only a few percent of their
     * absolute magnitude, so no `relevanceSaturationPoint` choice can make the text-relevance term's spread
     * competitive with a real business metric's spread, which means the two specific rated documents' rank
     * ORDER (all rank_eval/nDCG can see) stays identical across a wide range of relevanceWeight values even
     * though the actual score changes underneath -- confirmed empirically before landing on this approach.
     * Asserting on the adjusted `relevanceWeight` itself is the direct, non-flaky way to prove the shift is
     * real: a real "chair" query's top-10 raw scores are essentially never a perfectly symmetric
     * distribution (normalized entropy of exactly 0.5), so a non-zero `entropyWeightShiftMagnitude` must
     * produce a `relevanceWeight` different from the configured one.
     *
     * @return void
     */
    public function testApplyEntropyWeightingShiftsRelevanceWeightForARealAsymmetricScoreDistribution(): void
    {
        // Arrange -- entropy weighting itself has no runtime override mechanism to flip on for a test (see
        // createRankEvalRunnerWithEntropyWeightingForcedEnabled()'s own docblock), so this deliberately uses
        // the forced-enabled subclass rather than createRankEvalRunner().
        $runner = $this->createRankEvalRunnerWithEntropyWeightingForcedEnabled();
        $queryBuilder = new LiveCatalogSearchQueryBuilder();
        $baseQuery = $queryBuilder->build('chair', 'DE', 'en_US')->getQuery();

        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.5)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['pdp_impressions' => 1.0])
            ->setEntropyProbeResultSize(10)
            ->setEntropyWeightExponent(1.0)
            ->setEntropyWeightShiftMagnitude(0.4);

        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), new SearchElasticsearchConfig());
        $indexName = $indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, 'DE');

        $applyEntropyWeighting = new ReflectionMethod($runner, 'applyEntropyWeighting');

        // Act
        $adjustedConfigurationTransfer = $applyEntropyWeighting->invoke($runner, $baseQuery, $indexName, 'en_US', 'chair', $configurationTransfer);

        // Assert
        $this->assertNotSame(
            $configurationTransfer->getRelevanceWeight(),
            $adjustedConfigurationTransfer->getRelevanceWeight(),
            'A real, asymmetric top-10 score distribution for "chair" must produce a non-zero entropy shift -- if it doesn\'t, entropy-aware weighting is still inert.',
        );
        $this->assertGreaterThanOrEqual(0.0, $adjustedConfigurationTransfer->getRelevanceWeightOrFail());
        $this->assertLessThanOrEqual(1.0, $adjustedConfigurationTransfer->getRelevanceWeightOrFail());
    }

    /**
     * The `page` index this shop uses is one-per-store-multiple-locales — two locales sharing the same
     * literal search-term text used to collapse onto the SAME probe-score cache entry (the key was
     * `"<indexName>:<searchTerm>"`, no locale), silently handing one locale's entropy probe scores to the
     * other. Asserts the cache now holds two DISTINCT entries for the same index+term under two different
     * locales, via the real cache key format rather than the probe scores themselves (which, for the same
     * store/term, may legitimately be identical across locales — the key's distinctness is what this bug
     * was actually about).
     *
     * @return void
     */
    public function testFetchProbeScoresCachesSeparatelyPerLocale(): void
    {
        // Arrange
        $runner = $this->createRankEvalRunnerWithEntropyWeightingForcedEnabled();
        $queryBuilder = new LiveCatalogSearchQueryBuilder();
        $baseQueryEnUs = $queryBuilder->build('chair', 'DE', 'en_US')->getQuery();
        $baseQueryDeDe = $queryBuilder->build('chair', 'DE', 'de_DE')->getQuery();

        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), new SearchElasticsearchConfig());
        $indexName = $indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, 'DE');

        $fetchProbeScores = new ReflectionMethod($runner, 'fetchProbeScores');

        // Act
        $fetchProbeScores->invoke($runner, $baseQueryEnUs, $indexName, 'en_US', 'chair');
        $fetchProbeScores->invoke($runner, $baseQueryDeDe, $indexName, 'de_DE', 'chair');

        $cacheProperty = new ReflectionProperty(RankEvalRunner::class, 'probeScoresCache');
        $cacheProperty->setAccessible(true);
        $cache = $cacheProperty->getValue();

        // Assert
        $this->assertArrayHasKey($indexName . ':en_US:chair', $cache);
        $this->assertArrayHasKey($indexName . ':de_DE:chair', $cache);
    }

    /**
     * @return void
     */
    public function testApplyEntropyWeightingIsANoOpWhenProbeResultSizeIsNotConfigured(): void
    {
        // Arrange -- a live configuration that predates search-ranking's entropy weighting feature being
        // configured at all (entropyProbeResultSize still null) must never attempt the probe/shift at all.
        $runner = $this->createRankEvalRunnerWithEntropyWeightingForcedEnabled();
        $queryBuilder = new LiveCatalogSearchQueryBuilder();
        $baseQuery = $queryBuilder->build('chair', 'DE', 'en_US')->getQuery();

        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.5)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['pdp_impressions' => 1.0]);

        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), new SearchElasticsearchConfig());
        $indexName = $indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, 'DE');

        $applyEntropyWeighting = new ReflectionMethod($runner, 'applyEntropyWeighting');

        // Act
        $unchangedConfigurationTransfer = $applyEntropyWeighting->invoke($runner, $baseQuery, $indexName, 'en_US', 'chair', $configurationTransfer);

        // Assert
        $this->assertSame($configurationTransfer, $unchangedConfigurationTransfer);
    }

    /**
     * Proves Task #51's fix: evaluation must never apply an effect live traffic never applies, regardless
     * of what a candidate configuration's own entropy fields say. Deliberately uses an EXPLICIT
     * forced-disabled stub rather than the real, ambient `createRankEvalRunner()` -- now that
     * `isEntropyWeightingEnabled()` genuinely resolves through a project override (see
     * {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner}'s own docblock for the
     * fix), `createRankEvalRunner()`'s result legitimately depends on whatever THIS shop's own project
     * config says, which this test must not depend on to stay deterministic.
     *
     * @return void
     */
    public function testApplyEntropyWeightingIsANoOpWhenEntropyWeightingIsDisabled(): void
    {
        // Arrange -- a fully-populated entropy configuration that WOULD produce a real shift if entropy
        // weighting were enabled (see testApplyEntropyWeightingShiftsRelevanceWeightForARealAsymmetricScoreDistribution).
        $runner = $this->createRankEvalRunnerWithEntropyWeightingForcedDisabled();
        $queryBuilder = new LiveCatalogSearchQueryBuilder();
        $baseQuery = $queryBuilder->build('chair', 'DE', 'en_US')->getQuery();

        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.5)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['pdp_impressions' => 1.0])
            ->setEntropyProbeResultSize(10)
            ->setEntropyWeightExponent(1.0)
            ->setEntropyWeightShiftMagnitude(0.4);

        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), new SearchElasticsearchConfig());
        $indexName = $indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, 'DE');

        $applyEntropyWeighting = new ReflectionMethod($runner, 'applyEntropyWeighting');

        // Act
        $unchangedConfigurationTransfer = $applyEntropyWeighting->invoke($runner, $baseQuery, $indexName, 'en_US', 'chair', $configurationTransfer);

        // Assert
        $this->assertSame($configurationTransfer, $unchangedConfigurationTransfer);
    }

    /**
     * @return void
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

    /**
     * @return void
     */
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
     * Same composition `SearchRankingOptimizerFactory::createRankEvalRunner()` uses in production —
     * including the real `SearchRankingOptimizerToSearchRankingClientBridge`, so this exercises the actual
     * project-override-aware `isEntropyWeightingEnabled()` resolution (off by default, since nothing in
     * this test environment overrides it), not the old, no-longer-needed hardcoded-Shared-static fallback.
     *
     * @return \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner
     */
    protected function createRankEvalRunner(): RankEvalRunner
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());
        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), $searchElasticsearchConfig);

        return new RankEvalRunner(
            $elasticaClient,
            $indexNameResolver,
            new LiveCatalogSearchQueryBuilder(),
            new FunctionScoreBuilder(),
            new SearchRankingOptimizerToSearchRankingStorageClientBridge(new SearchRankingStorageClient()),
            null,
            new SearchRankingOptimizerToSearchRankingClientBridge(new SearchRankingClient()),
        );
    }

    /**
     * A real `SearchRankingOptimizerToSearchRankingClientInterface` stub forcing `true` — no longer an
     * anonymous `RankEvalRunner` subclass overriding a protected method, now that the entropy-enabled flag
     * genuinely IS dependency-injectable via the bridge {@see createRankEvalRunner()} also uses.
     *
     * @return \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner
     */
    protected function createRankEvalRunnerWithEntropyWeightingForcedEnabled(): RankEvalRunner
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());
        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), $searchElasticsearchConfig);

        $entropyWeightingForcedEnabledClient = new class implements SearchRankingOptimizerToSearchRankingClientInterface {
            /**
             * @return bool
             */
            public function isEntropyWeightingEnabled(): bool
            {
                return true;
            }
        };

        return new RankEvalRunner(
            $elasticaClient,
            $indexNameResolver,
            new LiveCatalogSearchQueryBuilder(),
            new FunctionScoreBuilder(),
            new SearchRankingOptimizerToSearchRankingStorageClientBridge(new SearchRankingStorageClient()),
            null,
            $entropyWeightingForcedEnabledClient,
        );
    }

    /**
     * The counterpart to {@see createRankEvalRunnerWithEntropyWeightingForcedEnabled()} — deterministically
     * OFF regardless of what this shop's own project config says, for tests that specifically need to
     * prove the disabled path rather than depend on ambient environment state.
     *
     * @return \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner
     */
    protected function createRankEvalRunnerWithEntropyWeightingForcedDisabled(): RankEvalRunner
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());
        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), $searchElasticsearchConfig);

        $entropyWeightingForcedDisabledClient = new class implements SearchRankingOptimizerToSearchRankingClientInterface {
            /**
             * @return bool
             */
            public function isEntropyWeightingEnabled(): bool
            {
                return false;
            }
        };

        return new RankEvalRunner(
            $elasticaClient,
            $indexNameResolver,
            new LiveCatalogSearchQueryBuilder(),
            new FunctionScoreBuilder(),
            new SearchRankingOptimizerToSearchRankingStorageClientBridge(new SearchRankingStorageClient()),
            null,
            $entropyWeightingForcedDisabledClient,
        );
    }
}
