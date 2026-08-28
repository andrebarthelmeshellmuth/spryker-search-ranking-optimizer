<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Client\SearchRankingOptimizer\Search;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use ReflectionProperty;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolver;
use Spryker\Client\SearchElasticsearch\SearchElasticsearchConfig;
use Spryker\Shared\SearchElasticsearch\ElasticaClient\ElasticaClientFactory;
use SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilder;
use SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface;
use SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculator;
use SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculatorInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\NeverInvokedStoreClient;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\SpecificityWeightingApplier;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

/**
 * INTEGRATION TEST — talks to a real Elasticsearch/OpenSearch, against this shop's own real product page
 * index, same portability tradeoff {@see \SprykerCommunityTest\Client\SearchRankingOptimizer\Search\RankEvalRunnerTest}
 * already accepts. Split out of that class's own test suite when
 * {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner} was decomposed into smaller
 * collaborators -- `apply()` is public here, so these tests no longer need reflection into a protected
 * `RankEvalRunner` method.
 *
 * @group SprykerCommunityTest
 * @group Client
 * @group SearchRankingOptimizer
 * @group Search
 * @group SpecificityWeightingApplierTest
 * @group NeedsSearch
 */
class SpecificityWeightingApplierTest extends Unit
{
    /**
     * Proves the specificity-weighting reimplementation directly at the source: before it existed,
     * `specificityBlendWeight`/`specificitySaturationPoint`/`specificityWeightExponent`/
     * `specificityWeightShiftMagnitude` were carried on every `SearchRankingConfigurationStorageTransfer`
     * but never actually read anywhere in the evaluation path. A saturation point far below "chair"'s own
     * real specificity (see this package's README) guarantees normalized specificity lands well above the
     * neutral 0.5 point, so a non-zero `specificityWeightShiftMagnitude` must produce a `relevanceWeight`
     * different from the configured one.
     */
    public function testApplyShiftsRelevanceWeightForARealQueryTerm(): void
    {
        // Arrange -- specificity weighting itself has no runtime override mechanism to flip on for a test
        // (see createApplierWithSpecificityWeightingForcedEnabled()'s own docblock), so this deliberately
        // uses the forced-enabled stub client rather than a real bridge.
        $applier = $this->createApplierWithSpecificityWeightingForcedEnabled();

        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.5)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['pdp_impressions' => 1.0])
            ->setSpecificityBlendWeight(0.7)
            ->setSpecificitySaturationPoint(1.0)
            ->setSpecificityWeightExponent(1.0)
            ->setSpecificityWeightShiftMagnitude(0.4);

        $indexName = $this->resolveIndexName();

        // Act
        $adjustedConfigurationTransfer = $applier->apply($indexName, 'chair', $configurationTransfer);

        // Assert
        $this->assertInstanceOf(SearchRankingConfigurationStorageTransfer::class, $adjustedConfigurationTransfer);
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
     * the `?? 1.0` fallback -- a forgotten parameter here has already once made a knob silently inert
     * during optimizer evaluation. Every other test in this class either omits `specificityCurveExponent`
     * entirely (falling back to the pivot-neutral 1.0) or never varies it, so none of them would notice if
     * this parameter were dropped on the floor again. Uses a deliberately small
     * `specificityWeightShiftMagnitude` (0.05, vs the other tests' 0.4) so the final, clamped
     * `relevanceWeight` has room to move in either direction regardless of which exponent produces the
     * larger shift -- with a bigger magnitude, both exponents could clamp to the same boundary value and
     * give a false pass that looks identical to the real bug.
     */
    public function testApplyRespectsTheConfiguredCurveExponent(): void
    {
        // Arrange
        $applier = $this->createApplierWithSpecificityWeightingForcedEnabled();

        $buildConfigurationTransfer = fn (float $curveExponent): SearchRankingConfigurationStorageTransfer => (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.5)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['pdp_impressions' => 1.0])
            ->setSpecificityBlendWeight(0.7)
            ->setSpecificitySaturationPoint(1.0)
            ->setSpecificityCurveExponent($curveExponent)
            ->setSpecificityWeightExponent(1.0)
            ->setSpecificityWeightShiftMagnitude(0.05);

        $indexName = $this->resolveIndexName();

        // Act
        $pivotNeutralConfigurationTransfer = $applier->apply($indexName, 'chair', $buildConfigurationTransfer(1.0));
        $sharpenedConfigurationTransfer = $applier->apply($indexName, 'chair', $buildConfigurationTransfer(4.0));

        // Assert
        $this->assertInstanceOf(SearchRankingConfigurationStorageTransfer::class, $pivotNeutralConfigurationTransfer);
        $this->assertInstanceOf(SearchRankingConfigurationStorageTransfer::class, $sharpenedConfigurationTransfer);
        $this->assertNotSame(
            $pivotNeutralConfigurationTransfer->getRelevanceWeight(),
            $sharpenedConfigurationTransfer->getRelevanceWeight(),
            'Two different specificityCurveExponent values must produce two different adjusted relevanceWeight results for the same real query term -- if they don\'t, specificityCurveExponent is silently inert in this reimplementation.',
        );
    }

    public function testFetchIdfByTermCachesTheResultAcrossRepeatedCalls(): void
    {
        // Arrange
        $applier = $this->createApplierWithSpecificityWeightingForcedEnabled();
        $indexName = $this->resolveIndexName();

        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.5)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['pdp_impressions' => 1.0])
            ->setSpecificityBlendWeight(0.7)
            ->setSpecificitySaturationPoint(1.0)
            ->setSpecificityWeightExponent(1.0)
            ->setSpecificityWeightShiftMagnitude(0.4);

        // Act
        $applier->apply($indexName, 'chair', $configurationTransfer);

        $cacheProperty = new ReflectionProperty(SpecificityWeightingApplier::class, 'idfCache');
        $cache = $cacheProperty->getValue();

        // Assert
        $this->assertArrayHasKey($indexName . ':chair', $cache);
    }

    public function testApplyIsANoOpWhenNoQueryTermCarriesRealCorpusEvidence(): void
    {
        // Arrange -- a search term that matches nothing in the corpus at all has no idf to compute.
        $applier = $this->createApplierWithSpecificityWeightingForcedEnabled();

        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.5)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['pdp_impressions' => 1.0])
            ->setSpecificityBlendWeight(0.7)
            ->setSpecificitySaturationPoint(1.0)
            ->setSpecificityWeightExponent(1.0)
            ->setSpecificityWeightShiftMagnitude(0.4);

        $indexName = $this->resolveIndexName();

        // Act
        $unchangedConfigurationTransfer = $applier->apply($indexName, 'nonexistenttermforthistest', $configurationTransfer);

        // Assert
        $this->assertSame($configurationTransfer, $unchangedConfigurationTransfer);
    }

    /**
     * Proves evaluation must never apply an effect live traffic never applies, regardless of what a
     * candidate configuration's own specificity fields say. Deliberately uses an EXPLICIT forced-disabled
     * stub rather than a real bridge, so this test stays deterministic regardless of what this shop's own
     * project config says.
     */
    public function testApplyIsANoOpWhenSpecificityWeightingIsDisabled(): void
    {
        // Arrange -- a fully-populated specificity configuration that WOULD produce a real shift if
        // specificity weighting were enabled (see testApplyShiftsRelevanceWeightForARealQueryTerm).
        $applier = $this->createApplierWithSpecificityWeightingForcedDisabled();

        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight(0.5)
            ->setRelevanceSaturationPoint(12.0)
            ->setMetricWeights(['pdp_impressions' => 1.0])
            ->setSpecificityBlendWeight(0.7)
            ->setSpecificitySaturationPoint(1.0)
            ->setSpecificityWeightExponent(1.0)
            ->setSpecificityWeightShiftMagnitude(0.4);

        $indexName = $this->resolveIndexName();

        // Act
        $unchangedConfigurationTransfer = $applier->apply($indexName, 'chair', $configurationTransfer);

        // Assert
        $this->assertSame($configurationTransfer, $unchangedConfigurationTransfer);
    }

    /**
     * @param string $storeName
     */
    protected function resolveIndexName(string $storeName = 'DE'): string
    {
        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), new SearchElasticsearchConfig());

        return $indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, $storeName);
    }

    /**
     * A real `SearchRankingOptimizerToSearchRankingClientInterface` stub forcing `true`.
     * `getSpecificityProbeFieldSearchAnalyzers()` mirrors this shop's own real project override
     * (`Pyz\Client\SearchRanking\SearchRankingConfig`), since the field/analyzer names must match this
     * shop's real `page.json` schema for a live `_termvectors` probe to find anything at all.
     */
    protected function createApplierWithSpecificityWeightingForcedEnabled(): SpecificityWeightingApplier
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());

        return new SpecificityWeightingApplier(
            $elasticaClient,
            new QuerySpecificityCalculator(),
            $this->createSearchRankingClientStub(true),
        );
    }

    /**
     * The counterpart to {@see createApplierWithSpecificityWeightingForcedEnabled()} —
     * deterministically OFF regardless of what this shop's own project config says, for tests that
     * specifically need to prove the disabled path rather than depend on ambient environment state.
     */
    protected function createApplierWithSpecificityWeightingForcedDisabled(): SpecificityWeightingApplier
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());

        return new SpecificityWeightingApplier(
            $elasticaClient,
            new QuerySpecificityCalculator(),
            $this->createSearchRankingClientStub(false),
        );
    }

    /**
     * @param bool $isSpecificityWeightingEnabled
     */
    protected function createSearchRankingClientStub(bool $isSpecificityWeightingEnabled): SearchRankingOptimizerToSearchRankingClientInterface
    {
        return new class ($isSpecificityWeightingEnabled) implements SearchRankingOptimizerToSearchRankingClientInterface {
            protected bool $isSpecificityWeightingEnabled;

            public function __construct(bool $isSpecificityWeightingEnabled)
            {
                $this->isSpecificityWeightingEnabled = $isSpecificityWeightingEnabled;
            }

            public function isSpecificityWeightingEnabled(): bool
            {
                return $this->isSpecificityWeightingEnabled;
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

            /**
             * @return array<int, string>
             */
            public function getRegisteredRankingStrategyNames(): array
            {
                return ['adaptive_formula'];
            }
        };
    }
}
