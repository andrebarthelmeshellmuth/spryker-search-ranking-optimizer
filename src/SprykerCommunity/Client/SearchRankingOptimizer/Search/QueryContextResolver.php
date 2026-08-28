<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Elastica\Client;
use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use Generated\Shared\Transfer\SearchRankingQueryContextTransfer;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface;
use SprykerCommunity\Client\SearchRanking\Dependency\Client\SearchRankingToStorageClientInterface;
use SprykerCommunity\Client\SearchRanking\Intent\BrandAnalyzer;
use SprykerCommunity\Client\SearchRanking\Intent\CategoryAnalyzer;
use SprykerCommunity\Client\SearchRanking\Intent\SkuIdentifierAnalyzer;
use SprykerCommunity\Client\SearchRanking\Intent\SuggestIndexEntityLookup;
use SprykerCommunity\Client\SearchRanking\Search\NavigationalRelevanceWeightShiftCalculatorInterface;
use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig;

/**
 * Split out of {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner} (which grew
 * too many orthogonal responsibilities across several build passes) — pure extraction, no behavioral
 * change; see that class's git history for the original single-class shape.
 */
class QueryContextResolver implements QueryContextResolverInterface
{
    /**
     * @var int
     */
    protected const ANALYZER_CACHE_TTL_SECONDS = 60;

    /**
     * Process-scoped cache of one {@see SkuIdentifierAnalyzer} per store name -- a fresh instance of this
     * class is built on every single `SearchRankingOptimizerFactory::createRankEvalRunner()` call, but the
     * {@see \SprykerCommunity\Client\SearchRanking\Intent\SuggestIndexEntityLookup} each analyzer wraps
     * fires a real OpenSearch request on every `exists()`/`suggest()` call, otherwise re-fired on every
     * single query term of every single `evaluate()` call, within one run that can fire thousands of them.
     * Store name is the only part of the entity-lookup index name that varies within one run, so it's the
     * cache key here too.
     *
     * NOT actually console-only: {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller\TestCurrentEvaluationController::indexAction()}
     * calls straight into `RankEvalRunner::evaluate()` from a normal Zed HTTP request, which under PHP-FPM
     * reuses worker processes across many unrelated requests -- these `static` properties do NOT reset
     * between them. `ANALYZER_CACHE_TTL_SECONDS` bounds the resulting staleness to a short window.
     *
     * @var array<string, array{0: \SprykerCommunity\Client\SearchRanking\Intent\SkuIdentifierAnalyzer, 1: float}>
     */
    protected static array $skuIdentifierAnalyzerCache = [];

    /**
     * Process-scoped cache of one {@see BrandAnalyzer} per store name -- same rationale/TTL as
     * {@see $skuIdentifierAnalyzerCache}.
     *
     * @var array<string, array{0: \SprykerCommunity\Client\SearchRanking\Intent\BrandAnalyzer, 1: float}>
     */
    protected static array $brandAnalyzerCache = [];

    /**
     * Process-scoped cache of one {@see CategoryAnalyzer} per store name -- same rationale/TTL as
     * {@see $skuIdentifierAnalyzerCache}.
     *
     * @var array<string, array{0: \SprykerCommunity\Client\SearchRanking\Intent\CategoryAnalyzer, 1: float}>
     */
    protected static array $categoryAnalyzerCache = [];

    /**
     * @param \Elastica\Client $elasticaClient
     * @param \Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface $indexNameResolver
     * @param \SprykerCommunity\Client\SearchRanking\Dependency\Client\SearchRankingToStorageClientInterface|null $storageClient
     *   Omitted (the default) degrades to no identifier/brand/category detection at all -- see
     *   {@see resolve()}'s own docblock.
     * @param \SprykerCommunity\Client\SearchRanking\Search\NavigationalRelevanceWeightShiftCalculatorInterface|null $navigationalRelevanceWeightShiftCalculator
     *   Backs {@see applyNavigationalShift()} -- omitted (the default) degrades to no shift applied at all.
     *   Stateless/pure math (no IO), bundled onto this class rather than injected into `RankEvalRunner`
     *   directly -- see {@see applyNavigationalShift()}'s own docblock for why.
     */
    public function __construct(
        protected Client $elasticaClient,
        protected IndexNameResolverInterface $indexNameResolver,
        protected ?SearchRankingToStorageClientInterface $storageClient = null,
        protected ?NavigationalRelevanceWeightShiftCalculatorInterface $navigationalRelevanceWeightShiftCalculator = null,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     */
    public function resolve(string $searchTerm, string $storeName, string $localeName): ?SearchRankingQueryContextTransfer
    {
        if ($this->storageClient === null) {
            return null;
        }

        $queryContextTransfer = (new SearchRankingQueryContextTransfer())
            ->setSearchString($searchTerm)
            ->setStoreName($storeName)
            ->setLocaleName($localeName);

        $queryContextTransfer = $this->getSkuIdentifierAnalyzer($storeName)->analyze($queryContextTransfer);
        $queryContextTransfer = $this->getBrandAnalyzer($storeName)->analyze($queryContextTransfer);

        return $this->getCategoryAnalyzer($storeName)->analyze($queryContextTransfer);
    }

    /**
     * {@inheritDoc}
     *
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     * @param \Generated\Shared\Transfer\SearchRankingQueryContextTransfer|null $queryContextTransfer
     */
    public function applyNavigationalShift(
        ?SearchRankingConfigurationStorageTransfer $configurationTransfer,
        ?SearchRankingQueryContextTransfer $queryContextTransfer,
    ): ?SearchRankingConfigurationStorageTransfer {
        if ($configurationTransfer === null || $queryContextTransfer === null || $this->navigationalRelevanceWeightShiftCalculator === null) {
            return $configurationTransfer;
        }

        $effectiveRelevanceWeight = $this->navigationalRelevanceWeightShiftCalculator->calculateEffectiveRelevanceWeight(
            (float)$configurationTransfer->getRelevanceWeight(),
            $configurationTransfer,
            $queryContextTransfer,
        );

        return (clone $configurationTransfer)->setRelevanceWeight($effectiveRelevanceWeight);
    }

    /**
     * @see $skuIdentifierAnalyzerCache for the process-scoped caching rationale.
     *
     * @param string $storeName
     */
    protected function getSkuIdentifierAnalyzer(string $storeName): SkuIdentifierAnalyzer
    {
        $cached = static::$skuIdentifierAnalyzerCache[$storeName] ?? null;

        if ($cached !== null && $cached[1] > microtime(true)) {
            return $cached[0];
        }

        $skuEntityLookup = $this->createSuggestIndexEntityLookup($storeName, SearchRankingConfig::ENTITY_LOOKUP_TYPE_SKU);
        $skuIdentifierAnalyzer = new SkuIdentifierAnalyzer($skuEntityLookup);

        static::$skuIdentifierAnalyzerCache[$storeName] = [$skuIdentifierAnalyzer, microtime(true) + static::ANALYZER_CACHE_TTL_SECONDS];

        return $skuIdentifierAnalyzer;
    }

    /**
     * @see $brandAnalyzerCache for the process-scoped caching rationale. Same
     * `{prefix}_{storeName}_entity-lookup` index-name scheme `search-ranking`'s own
     * `SearchRankingFactory::createSuggestIndexEntityLookup()` uses (ES is the only entity-lookup backend
     * now — see that package's README/install checker).
     *
     * @param string $storeName
     */
    protected function getBrandAnalyzer(string $storeName): BrandAnalyzer
    {
        $cached = static::$brandAnalyzerCache[$storeName] ?? null;

        if ($cached !== null && $cached[1] > microtime(true)) {
            return $cached[0];
        }

        $brandEntityLookup = $this->createSuggestIndexEntityLookup($storeName, SearchRankingConfig::ENTITY_LOOKUP_TYPE_BRAND);
        // `search-ranking`'s own BrandAnalyzer takes a second, category-scoped lookup for brand/category
        // disambiguation (a term matching both, e.g. "office", defers to category -- see BrandAnalyzer's own
        // docblock) -- mirror that here with a fresh SuggestIndexEntityLookup instance over the same entity
        // type {@see getCategoryAnalyzer()} uses, so this harness's brandMatchRelevanceWeightShift measurement
        // reflects the same disambiguation real storefront traffic gets.
        $categoryEntityLookupForBrandDisambiguation = $this->createSuggestIndexEntityLookup($storeName, SearchRankingConfig::ENTITY_LOOKUP_TYPE_CATEGORY);
        $brandAnalyzer = new BrandAnalyzer($brandEntityLookup, $categoryEntityLookupForBrandDisambiguation);

        static::$brandAnalyzerCache[$storeName] = [$brandAnalyzer, microtime(true) + static::ANALYZER_CACHE_TTL_SECONDS];

        return $brandAnalyzer;
    }

    /**
     * @see $categoryAnalyzerCache for the process-scoped caching rationale. Same index-name scheme as
     * {@see getBrandAnalyzer()}.
     *
     * @param string $storeName
     */
    protected function getCategoryAnalyzer(string $storeName): CategoryAnalyzer
    {
        $cached = static::$categoryAnalyzerCache[$storeName] ?? null;

        if ($cached !== null && $cached[1] > microtime(true)) {
            return $cached[0];
        }

        $categoryEntityLookup = $this->createSuggestIndexEntityLookup($storeName, SearchRankingConfig::ENTITY_LOOKUP_TYPE_CATEGORY);
        $categoryAnalyzer = new CategoryAnalyzer($categoryEntityLookup);

        static::$categoryAnalyzerCache[$storeName] = [$categoryAnalyzer, microtime(true) + static::ANALYZER_CACHE_TTL_SECONDS];

        return $categoryAnalyzer;
    }

    /**
     * Builds a {@see SuggestIndexEntityLookup} for `$entityType`, scoped to `$storeName` — same
     * `{prefix}_{storeName}_entity-lookup` index-name scheme `search-ranking`'s own
     * `SearchRankingFactory::createSuggestIndexEntityLookup()` uses, resolved here via
     * `$indexNameResolver` (already available on this class for the exact same Store-singleton-avoidance
     * reason documented on {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner})
     * instead of that factory's own `getStoreClient()->getCurrentStore()`-based resolution, which is
     * unavailable in this package's Zed/console execution context.
     *
     * @param string $storeName
     * @param string $entityType
     */
    protected function createSuggestIndexEntityLookup(string $storeName, string $entityType): SuggestIndexEntityLookup
    {
        $indexName = $this->indexNameResolver->resolve(SearchRankingConfig::ENTITY_LOOKUP_SUGGEST_INDEX_SOURCE_IDENTIFIER, $storeName);

        return new SuggestIndexEntityLookup($this->elasticaClient, $indexName, $entityType);
    }
}
