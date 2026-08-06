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
use SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface;
use SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculator;
use SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculatorInterface;
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
     * Proves the specificity-weighting reimplementation directly at the source: before it existed,
     * `specificityBlendWeight`/`specificitySaturationPoint`/`specificityWeightExponent`/
     * `specificityWeightShiftMagnitude` were carried on every `SearchRankingConfigurationStorageTransfer`
     * but never actually read anywhere in this evaluation path. Deliberately invokes the protected
     * `applySpecificityWeighting()` directly (via reflection) against this shop's real "chair" search term
     * and real catalog data, rather than asserting on the downstream `rank_eval` nDCG score -- same
     * reasoning `testEvaluateAppliesAnExplicitRankingConfigurationOverrideInsteadOfTheLiveOne()`'s docblock
     * gives for why a direct assertion on the adjusted value is the non-flaky way to prove a shift is real.
     * A saturation point far below "chair"'s own real specificity (see this package's README) guarantees
     * normalized specificity lands well above the neutral 0.5 point, so a non-zero
     * `specificityWeightShiftMagnitude` must produce a `relevanceWeight` different from the configured one.
     */
    public function testApplySpecificityWeightingShiftsRelevanceWeightForARealQueryTerm(): void
    {
        // Arrange -- specificity weighting itself has no runtime override mechanism to flip on for a test
        // (see createRankEvalRunnerWithSpecificityWeightingForcedEnabled()'s own docblock), so this
        // deliberately uses the forced-enabled subclass rather than createRankEvalRunner().
        $runner = $this->createRankEvalRunnerWithSpecificityWeightingForcedEnabled();

        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.5)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['pdp_impressions' => 1.0])
            ->setSpecificityBlendWeight(0.7)
            ->setSpecificitySaturationPoint(1.0)
            ->setSpecificityWeightExponent(1.0)
            ->setSpecificityWeightShiftMagnitude(0.4);

        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), new SearchElasticsearchConfig());
        $indexName = $indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, 'DE');

        $applySpecificityWeighting = new ReflectionMethod($runner, 'applySpecificityWeighting');

        // Act
        $adjustedConfigurationTransfer = $applySpecificityWeighting->invoke($runner, $indexName, 'chair', $configurationTransfer);

        // Assert
        $this->assertNotSame(
            $configurationTransfer->getRelevanceWeight(),
            $adjustedConfigurationTransfer->getRelevanceWeight(),
            'A real query term must produce a non-zero specificity shift -- if it doesn\'t, specificity-aware weighting is still inert.',
        );
        $this->assertGreaterThanOrEqual(0.0, $adjustedConfigurationTransfer->getRelevanceWeightOrFail());
        $this->assertLessThanOrEqual(1.0, $adjustedConfigurationTransfer->getRelevanceWeightOrFail());
    }

    /**
     * Proves `specificityCurveExponent` actually reaches this reimplementation's own shift math, not just
     * the `?? 1.0` fallback -- the exact bug class Phase 5 already found and fixed once here (a forgotten
     * parameter making a knob silently inert during optimizer evaluation, see this class's own sibling
     * {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner}'s docblock). Every other
     * test in this class either omits `specificityCurveExponent` entirely (falling back to the
     * pivot-neutral 1.0) or never varies it, so none of them would notice if this parameter were dropped
     * on the floor again. Uses a deliberately small `specificityWeightShiftMagnitude` (0.05, vs the other
     * tests' 0.4) so the final, clamped `relevanceWeight` has room to move in either direction regardless
     * of which exponent produces the larger shift -- with a bigger magnitude, both exponents could clamp
     * to the same boundary value and give a false pass that looks identical to the real bug.
     */
    public function testApplySpecificityWeightingRespectsTheConfiguredCurveExponent(): void
    {
        // Arrange
        $runner = $this->createRankEvalRunnerWithSpecificityWeightingForcedEnabled();

        $buildConfigurationTransfer = fn (float $curveExponent): SearchRankingConfigurationStorageTransfer => (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.5)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['pdp_impressions' => 1.0])
            ->setSpecificityBlendWeight(0.7)
            ->setSpecificitySaturationPoint(1.0)
            ->setSpecificityCurveExponent($curveExponent)
            ->setSpecificityWeightExponent(1.0)
            ->setSpecificityWeightShiftMagnitude(0.05);

        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), new SearchElasticsearchConfig());
        $indexName = $indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, 'DE');

        $applySpecificityWeighting = new ReflectionMethod($runner, 'applySpecificityWeighting');

        // Act
        $pivotNeutralConfigurationTransfer = $applySpecificityWeighting->invoke($runner, $indexName, 'chair', $buildConfigurationTransfer(1.0));
        $sharpenedConfigurationTransfer = $applySpecificityWeighting->invoke($runner, $indexName, 'chair', $buildConfigurationTransfer(4.0));

        // Assert
        $this->assertNotSame(
            $pivotNeutralConfigurationTransfer->getRelevanceWeight(),
            $sharpenedConfigurationTransfer->getRelevanceWeight(),
            'Two different specificityCurveExponent values must produce two different adjusted relevanceWeight results for the same real query term -- if they don\'t, specificityCurveExponent is silently inert in this reimplementation, exactly the Phase 5 bug class this test guards against.',
        );
    }

    public function testFetchIdfByTermCachesTheResultAcrossRepeatedCalls(): void
    {
        // Arrange
        $runner = $this->createRankEvalRunnerWithSpecificityWeightingForcedEnabled();

        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), new SearchElasticsearchConfig());
        $indexName = $indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, 'DE');

        $fetchIdfByTerm = new ReflectionMethod($runner, 'fetchIdfByTerm');

        // Act
        $fetchIdfByTerm->invoke($runner, $indexName, 'chair');

        $cacheProperty = new ReflectionProperty(RankEvalRunner::class, 'idfCache');
        $cache = $cacheProperty->getValue();

        // Assert
        $this->assertArrayHasKey($indexName . ':chair', $cache);
    }

    public function testApplySpecificityWeightingIsANoOpWhenNoQueryTermCarriesRealCorpusEvidence(): void
    {
        // Arrange -- a search term that matches nothing in the corpus at all has no idf to compute.
        $runner = $this->createRankEvalRunnerWithSpecificityWeightingForcedEnabled();

        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.5)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['pdp_impressions' => 1.0])
            ->setSpecificityBlendWeight(0.7)
            ->setSpecificitySaturationPoint(1.0)
            ->setSpecificityWeightExponent(1.0)
            ->setSpecificityWeightShiftMagnitude(0.4);

        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), new SearchElasticsearchConfig());
        $indexName = $indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, 'DE');

        $applySpecificityWeighting = new ReflectionMethod($runner, 'applySpecificityWeighting');

        // Act
        $unchangedConfigurationTransfer = $applySpecificityWeighting->invoke($runner, $indexName, 'nonexistenttermforthistest', $configurationTransfer);

        // Assert
        $this->assertSame($configurationTransfer, $unchangedConfigurationTransfer);
    }

    /**
     * Proves evaluation must never apply an effect live traffic never applies, regardless of what a
     * candidate configuration's own specificity fields say. Deliberately uses an EXPLICIT forced-disabled
     * stub rather than the real, ambient `createRankEvalRunner()` -- now that
     * `isSpecificityWeightingEnabled()` genuinely resolves through a project override (see
     * {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner}'s own docblock for the
     * fix), `createRankEvalRunner()`'s result legitimately depends on whatever THIS shop's own project
     * config says, which this test must not depend on to stay deterministic.
     */
    public function testApplySpecificityWeightingIsANoOpWhenSpecificityWeightingIsDisabled(): void
    {
        // Arrange -- a fully-populated specificity configuration that WOULD produce a real shift if
        // specificity weighting were enabled (see testApplySpecificityWeightingShiftsRelevanceWeightForARealQueryTerm).
        $runner = $this->createRankEvalRunnerWithSpecificityWeightingForcedDisabled();

        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.5)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['pdp_impressions' => 1.0])
            ->setSpecificityBlendWeight(0.7)
            ->setSpecificitySaturationPoint(1.0)
            ->setSpecificityWeightExponent(1.0)
            ->setSpecificityWeightShiftMagnitude(0.4);

        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), new SearchElasticsearchConfig());
        $indexName = $indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, 'DE');

        $applySpecificityWeighting = new ReflectionMethod($runner, 'applySpecificityWeighting');

        // Act
        $unchangedConfigurationTransfer = $applySpecificityWeighting->invoke($runner, $indexName, 'chair', $configurationTransfer);

        // Assert
        $this->assertSame($configurationTransfer, $unchangedConfigurationTransfer);
    }

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

        return new RankEvalRunner(
            $elasticaClient,
            $indexNameResolver,
            new LiveCatalogSearchQueryBuilder(),
            new FunctionScoreBuilder(),
            new SearchRankingOptimizerToSearchRankingStorageClientBridge(new SearchRankingStorageClient()),
            new QuerySpecificityCalculator(),
            new SearchRankingOptimizerToSearchRankingClientBridge(new SearchRankingClient()),
        );
    }

    /**
     * A real `SearchRankingOptimizerToSearchRankingClientInterface` stub forcing `true` — no longer an
     * anonymous `RankEvalRunner` subclass overriding a protected method, now that the specificity-enabled
     * flag genuinely IS dependency-injectable via the bridge {@see createRankEvalRunner()} also uses.
     * `getSpecificityProbeFieldSearchAnalyzers()` mirrors this shop's own real project override
     * (`Pyz\Client\SearchRanking\SearchRankingConfig`), since the field/analyzer names must match this
     * shop's real `page.json` schema for a live `_termvectors` probe to find anything at all.
     */
    protected function createRankEvalRunnerWithSpecificityWeightingForcedEnabled(): RankEvalRunner
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());
        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), $searchElasticsearchConfig);

        $specificityWeightingForcedEnabledClient = new class implements SearchRankingOptimizerToSearchRankingClientInterface {
            public function isSpecificityWeightingEnabled(): bool
            {
                return true;
            }

            /**
             * @return array<string, string>
             */
            public function getSpecificityProbeFieldSearchAnalyzers(): array
            {
                return [
                    'full-text' => 'fulltext_search_analyzer',
                    'full-text-boosted' => 'fulltext_search_analyzer',
                ];
            }

            public function createFunctionScoreBuilder(): FunctionScoreBuilderInterface
            {
                return new FunctionScoreBuilder();
            }

            public function createQuerySpecificityCalculator(): QuerySpecificityCalculatorInterface
            {
                return new QuerySpecificityCalculator();
            }
        };

        return new RankEvalRunner(
            $elasticaClient,
            $indexNameResolver,
            new LiveCatalogSearchQueryBuilder(),
            new FunctionScoreBuilder(),
            new SearchRankingOptimizerToSearchRankingStorageClientBridge(new SearchRankingStorageClient()),
            new QuerySpecificityCalculator(),
            $specificityWeightingForcedEnabledClient,
        );
    }

    /**
     * The counterpart to {@see createRankEvalRunnerWithSpecificityWeightingForcedEnabled()} —
     * deterministically OFF regardless of what this shop's own project config says, for tests that
     * specifically need to prove the disabled path rather than depend on ambient environment state.
     */
    protected function createRankEvalRunnerWithSpecificityWeightingForcedDisabled(): RankEvalRunner
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());
        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), $searchElasticsearchConfig);

        $specificityWeightingForcedDisabledClient = new class implements SearchRankingOptimizerToSearchRankingClientInterface {
            public function isSpecificityWeightingEnabled(): bool
            {
                return false;
            }

            /**
             * @return array<string, string>
             */
            public function getSpecificityProbeFieldSearchAnalyzers(): array
            {
                return [
                    'full-text' => 'fulltext_search_analyzer',
                    'full-text-boosted' => 'fulltext_search_analyzer',
                ];
            }

            public function createFunctionScoreBuilder(): FunctionScoreBuilderInterface
            {
                return new FunctionScoreBuilder();
            }

            public function createQuerySpecificityCalculator(): QuerySpecificityCalculatorInterface
            {
                return new QuerySpecificityCalculator();
            }
        };

        return new RankEvalRunner(
            $elasticaClient,
            $indexNameResolver,
            new LiveCatalogSearchQueryBuilder(),
            new FunctionScoreBuilder(),
            new SearchRankingOptimizerToSearchRankingStorageClientBridge(new SearchRankingStorageClient()),
            new QuerySpecificityCalculator(),
            $specificityWeightingForcedDisabledClient,
        );
    }
}
