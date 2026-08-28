<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Elastica\Client;
use Elastica\Query\AbstractQuery;
use Elastica\Query\MatchAll;
use Elastica\Request;
use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationQueryScoreTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer;
use Generated\Shared\Transfer\SearchRankingQueryContextTransfer;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface;
use SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf\RrfEvaluationQueryBuilderInterface;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use Throwable;

/**
 * Fires `_rank_eval` requests against a real search index to measure ranking quality for a candidate (or
 * live) `search-ranking` configuration. This class stays deliberately thin -- it orchestrates the
 * per-query pipeline (specificity weighting, Intent-Aware Alpha query context, navigational relevanceWeight
 * shift, query-vector resolution, RRF vs. linear-blend query construction, the actual `_rank_eval` HTTP
 * call) by delegating each of those concerns to its own single-purpose collaborator -- see
 * {@see SpecificityWeightingApplierInterface}, {@see QueryContextResolverInterface},
 * {@see QueryVectorResolverInterface}, and {@see RrfEvaluationQueryBuilderInterface} for the mechanisms
 * themselves.
 */
class RankEvalRunner implements RankEvalRunnerInterface
{
    protected Client $elasticaClient;

    protected IndexNameResolverInterface $indexNameResolver;

    protected LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder;

    protected FunctionScoreBuilderInterface $functionScoreBuilder;

    protected SearchRankingOptimizerToSearchRankingStorageClientInterface $searchRankingStorageClient;

    /**
     * `_rank_eval` matches `ratings[]` entries to `hits[]` by the EXACT `(_index, _id)` pair — and a hit's
     * `_index` is always the concrete backing index a request against an alias actually resolved to, never
     * the alias name itself (standard Elasticsearch/OpenSearch behavior). By default, `$indexName`
     * throughout this class already IS a concrete index — the `_alias` lookup below is then a harmless
     * no-op that returns it unchanged. Installing `spryker-community/search-index-alias` (a separate,
     * optional package that adds no-downtime reindexing: reindex into a freshly timestamped concrete
     * index, then flip an Elasticsearch/OpenSearch alias to it atomically) changes that — `$indexName`
     * becomes the alias, resolved to a *different* concrete index on every reindex/flip. Without this
     * resolution, a `ratings[]._index` built from that alias string would then silently match NOTHING:
     * every hit comes back with `"rating": null`, `_rank_eval` treats the query as if it had zero ratings
     * at all, and `metric_score` is 0.0 for every single query — no error, no partial signal, just an
     * evaluation that always reports "no improvement possible" regardless of how much real relevance data
     * exists. Resolving to the concrete index once per {@see evaluate()} call and using THAT for every
     * `ratings[]._index` in the request keeps both cases correct with no version constraint on the alias
     * package needed. Cheap to resolve once per run, wasteful to resolve on every one of potentially
     * thousands of `evaluate()` calls within one optimization run -- process-scoped/short-TTL cached below
     * for exactly that reason.
     *
     * @var array<string, array{0: string, 1: float}>
     */
    protected static array $concreteIndexNameCache = [];

    /**
     * @var int
     */
    protected const CONCRETE_INDEX_NAME_CACHE_TTL_SECONDS = 60;

    /**
     * @param \Elastica\Client $elasticaClient
     * @param \Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface $indexNameResolver
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder
     * @param \SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface $functionScoreBuilder
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientInterface $searchRankingStorageClient
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\SpecificityWeightingApplierInterface $specificityWeightingApplier
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\QueryVectorResolverInterface|null $queryVectorResolver
     *   Omitted (the default) degrades to pure lexical evaluation -- no query vector is ever resolved, same
     *   as omitting an embedding client/cache in that resolver's own constructor.
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\QueryContextResolverInterface|null $queryContextResolver
     *   Backs the Intent-Aware Alpha SKU/brand/category lookups (see {@see resolveQueryContextTransfer()})
     *   — omitted (the default) degrades to no identifier/brand/category detection at all, i.e. today's
     *   behavior: `alpha` is never force-overridden to `1.0`, and `detectedBrand`/`detectedCategory` are
     *   never set, for any query.
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf\RrfEvaluationQueryBuilderInterface|null $rrfEvaluationQueryBuilder
     *   Backs `--fusion=rrf` evaluation mode (see {@see buildRrfWrappedQuery()}) — omitted (the default)
     *   degrades to the plain unwrapped lexical query whenever RRF mode is requested, same as that builder's
     *   own no-op fallback.
     */
    public function __construct(
        Client $elasticaClient,
        IndexNameResolverInterface $indexNameResolver,
        LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder,
        FunctionScoreBuilderInterface $functionScoreBuilder,
        SearchRankingOptimizerToSearchRankingStorageClientInterface $searchRankingStorageClient,
        protected SpecificityWeightingApplierInterface $specificityWeightingApplier,
        protected ?QueryVectorResolverInterface $queryVectorResolver = null,
        protected ?QueryContextResolverInterface $queryContextResolver = null,
        protected ?RrfEvaluationQueryBuilderInterface $rrfEvaluationQueryBuilder = null,
    ) {
        $this->elasticaClient = $elasticaClient;
        $this->indexNameResolver = $indexNameResolver;
        $this->liveCatalogSearchQueryBuilder = $liveCatalogSearchQueryBuilder;
        $this->functionScoreBuilder = $functionScoreBuilder;
        $this->searchRankingStorageClient = $searchRankingStorageClient;
    }

    /**
     * {@inheritDoc}
     *
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer $requestTransfer
     */
    public function evaluate(SearchRankingEvaluationRequestTransfer $requestTransfer): SearchRankingEvaluationResponseTransfer
    {
        $responseTransfer = new SearchRankingEvaluationResponseTransfer();

        $storeName = $requestTransfer->getStoreNameOrFail();
        $localeName = $requestTransfer->getLocaleNameOrFail();
        $indexName = $this->indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, $storeName);
        $configurationTransfer = $requestTransfer->getRankingConfiguration() ?? $this->searchRankingStorageClient->findRankingConfiguration($storeName, $localeName);

        $rankEvalRequests = $this->buildRankEvalRequests($requestTransfer, $storeName, $localeName, $indexName, $configurationTransfer);

        if ($rankEvalRequests === []) {
            return $responseTransfer;
        }

        $responseData = $this->elasticaClient->request(sprintf('%s/_rank_eval', $indexName), Request::POST, [
            'requests' => $rankEvalRequests,
            'metric' => [
                'dcg' => [
                    'k' => $requestTransfer->getCutoffOrFail(),
                    'normalize' => true,
                ],
            ],
        ])->getData();

        $details = is_array($responseData['details'] ?? null) ? $responseData['details'] : [];

        foreach ($rankEvalRequests as $rankEvalRequest) {
            $queryId = $rankEvalRequest['id'];
            $metricScore = (float)($details[$queryId]['metric_score'] ?? 0.0);

            $responseTransfer->addQueryScore(
                (new SearchRankingEvaluationQueryScoreTransfer())
                    ->setIdSearchRankingQuery((int)$queryId)
                    ->setMetricScore($metricScore),
            );
        }

        return $responseTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer $requestTransfer
     * @param string $storeName
     * @param string $localeName
     * @param string $indexName
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildRankEvalRequests(
        SearchRankingEvaluationRequestTransfer $requestTransfer,
        string $storeName,
        string $localeName,
        string $indexName,
        ?SearchRankingConfigurationStorageTransfer $configurationTransfer,
    ): array {
        $rankEvalRequests = [];
        $concreteIndexName = $this->resolveConcreteIndexName($indexName);
        $queryVectorsBySearchTerm = [];
        $fusionMode = $requestTransfer->getFusionMode() ?? SearchRankingOptimizerConfig::FUSION_MODE_LINEAR;
        $isRrfMode = $fusionMode === SearchRankingOptimizerConfig::FUSION_MODE_RRF;

        foreach ($requestTransfer->getQueries() as $queryTransfer) {
            $ratings = [];

            foreach ($queryTransfer->getProductGains() as $productGainTransfer) {
                $ratings[] = [
                    '_index' => $concreteIndexName,
                    '_id' => $this->buildProductDocumentId($storeName, $localeName, $productGainTransfer->getIdProductAbstractOrFail()),
                    'rating' => $productGainTransfer->getGainOrFail(),
                ];
            }

            if ($ratings === []) {
                continue;
            }

            $searchTerm = $queryTransfer->getSearchTermOrFail();
            $perQueryConfigurationTransfer = $this->specificityWeightingApplier->apply($indexName, $searchTerm, $configurationTransfer);
            $queryContextTransfer = $this->resolveQueryContextTransfer($searchTerm, $storeName, $localeName);
            $perQueryConfigurationTransfer = $this->applyNavigationalShift($perQueryConfigurationTransfer, $queryContextTransfer);

            if (!array_key_exists($searchTerm, $queryVectorsBySearchTerm)) {
                // RRF mode always attempts a vector regardless of alpha (see resolveQueryVector()'s own
                // docblock) -- alpha only governs the LINEAR blend's own gating.
                $queryVectorsBySearchTerm[$searchTerm] = $this->resolveQueryVector($searchTerm, $configurationTransfer, $isRrfMode);
            }

            if ($isRrfMode) {
                $wrappedQuery = $this->buildRrfWrappedQuery($searchTerm, $storeName, $localeName, $indexName, $queryVectorsBySearchTerm[$searchTerm]);
                // RRF already fused the semantic signal in at the retrieval stage -- passing a query
                // vector here too would double-count it, see this class's own RRF docblock. The query
                // context is passed through anyway for consistency -- harmless, since the identifier-match
                // override only ever affects the semantic term FunctionScoreBuilder builds from a
                // non-null query vector, and that's already null on this path.
                $queryClause = $this->applyRankingFormula($wrappedQuery, $perQueryConfigurationTransfer, null, $queryContextTransfer);
            } else {
                $elasticaQuery = $this->liveCatalogSearchQueryBuilder->build($searchTerm, $storeName, $localeName);
                $baseQuery = $elasticaQuery->getQuery();
                $queryClause = $this->applyRankingFormula($baseQuery, $perQueryConfigurationTransfer, $queryVectorsBySearchTerm[$searchTerm], $queryContextTransfer);
            }

            $rankEvalRequests[] = [
                'id' => (string)$queryTransfer->getIdSearchRankingQueryOrFail(),
                'request' => [
                    'query' => $queryClause instanceof AbstractQuery ? $queryClause->toArray() : $queryClause,
                ],
                'ratings' => $ratings,
            ];
        }

        return $rankEvalRequests;
    }

    /**
     * @see \SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf\RrfEvaluationQueryBuilderInterface for
     * the full RRF mechanism and degradation contract. Falls back to the plain unwrapped lexical query
     * (never throws) whenever no builder was wired in at all -- the same "no RRF collaborators available"
     * fallback that builder's own default implementation applies internally, kept here too so this method
     * behaves identically whether the builder itself is missing or merely degrading internally.
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param string $indexName
     * @param array<int, float>|null $queryVector Already resolved via {@see resolveQueryVector()} with
     *   `$ignoreAlphaGate = true` by the caller -- `null` when unavailable.
     */
    protected function buildRrfWrappedQuery(
        string $searchTerm,
        string $storeName,
        string $localeName,
        string $indexName,
        ?array $queryVector,
    ): AbstractQuery {
        if ($this->rrfEvaluationQueryBuilder === null) {
            $fallbackQuery = $this->liveCatalogSearchQueryBuilder->build($searchTerm, $storeName, $localeName)->getQuery();

            return $fallbackQuery instanceof AbstractQuery ? $fallbackQuery : new MatchAll();
        }

        return $this->rrfEvaluationQueryBuilder->build($searchTerm, $storeName, $localeName, $indexName, $queryVector);
    }

    /**
     * Wraps the base catalog query in search-ranking's own business-signal function_score — the same
     * blend `SearchRankingFunctionScoreQueryExpanderPlugin` applies to every real storefront search — so
     * this evaluation measures the ACTUAL configured (or candidate) ranking formula's quality, not raw
     * Elasticsearch text relevance. Falls back to the unwrapped query whenever there's nothing to blend
     * (no configuration at all, or a configuration with no active non-zero metric weight) — exactly
     * mirroring that plugin's own "leave the query untouched" fallback.
     *
     * @param mixed $queryClause
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     * @param array<int, float>|null $queryVector
     * @param \Generated\Shared\Transfer\SearchRankingQueryContextTransfer|null $queryContextTransfer
     */
    protected function applyRankingFormula(
        mixed $queryClause,
        ?SearchRankingConfigurationStorageTransfer $configurationTransfer,
        ?array $queryVector = null,
        ?SearchRankingQueryContextTransfer $queryContextTransfer = null,
    ): mixed {
        if ($configurationTransfer === null || !($queryClause instanceof AbstractQuery)) {
            return $queryClause;
        }

        $functionScore = $this->functionScoreBuilder->build($queryClause, $configurationTransfer, $queryVector, $queryContextTransfer);

        return $functionScore ?? $queryClause;
    }

    /**
     * @see \SprykerCommunity\Client\SearchRankingOptimizer\Search\QueryContextResolverInterface for the
     * full contract. Delegates to the injected resolver, degrading to `null` (never throws) whenever none
     * was wired in.
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     */
    protected function resolveQueryContextTransfer(string $searchTerm, string $storeName, string $localeName): ?SearchRankingQueryContextTransfer
    {
        return $this->queryContextResolver?->resolve($searchTerm, $storeName, $localeName);
    }

    /**
     * @see \SprykerCommunity\Client\SearchRankingOptimizer\Search\QueryContextResolverInterface::applyNavigationalShift()
     * for the full contract -- bundled onto that resolver rather than injected here as its own separate
     * collaborator, see that method's own docblock for why. A no-op (the same instance, unchanged, never
     * throws) whenever no resolver was wired in at all.
     *
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     * @param \Generated\Shared\Transfer\SearchRankingQueryContextTransfer|null $queryContextTransfer
     */
    protected function applyNavigationalShift(
        ?SearchRankingConfigurationStorageTransfer $configurationTransfer,
        ?SearchRankingQueryContextTransfer $queryContextTransfer,
    ): ?SearchRankingConfigurationStorageTransfer {
        if ($this->queryContextResolver === null) {
            return $configurationTransfer;
        }

        return $this->queryContextResolver->applyNavigationalShift($configurationTransfer, $queryContextTransfer);
    }

    /**
     * @see \SprykerCommunity\Client\SearchRankingOptimizer\Search\QueryVectorResolverInterface for the full
     * contract. Delegates to the injected resolver, degrading to `null` (never throws) whenever none was
     * wired in -- the same "pure lexical evaluation" degradation that resolver's own missing-embedding-
     * client branch already applies.
     *
     * @param string $searchTerm
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     * @param bool $ignoreAlphaGate
     *
     * @return array<int, float>|null
     */
    protected function resolveQueryVector(
        string $searchTerm,
        ?SearchRankingConfigurationStorageTransfer $configurationTransfer,
        bool $ignoreAlphaGate = false,
    ): ?array {
        return $this->queryVectorResolver?->resolve($searchTerm, $configurationTransfer, $ignoreAlphaGate);
    }

    /**
     * The `page` index's own document id format, matching this shop's real OpenSearch index
     * (`product_abstract:{store}:{locale}:{idProductAbstract}`, store/locale lowercased) — computed
     * directly rather than looked up, since the id_product_abstract is already exactly what
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface}
     * stores per rating.
     *
     * @param string $storeName
     * @param string $localeName
     * @param int $idProductAbstract
     */
    protected function buildProductDocumentId(string $storeName, string $localeName, int $idProductAbstract): string
    {
        return sprintf(
            'product_abstract:%s:%s:%d',
            strtolower($storeName),
            strtolower($localeName),
            $idProductAbstract,
        );
    }

    /**
     * Resolves `$indexName` to the concrete index it currently points to — see
     * {@see $concreteIndexNameCache} for why this matters for `_rank_eval` specifically, and why it's a
     * no-op on the common, non-aliased case. `GET {indexName}/_alias` returns
     * `{"<concreteName>": {"aliases": {...}}}` when `$indexName` is an alias (e.g. with
     * `spryker-community/search-index-alias` installed); the first (and, for a single-index alias as used
     * there, only) top-level key is the concrete name. Falls back to the given `$indexName` unchanged if
     * the lookup fails or the response shape is unexpected — including the default case where `$indexName`
     * is already a concrete index with no alias at all — the same "don't hard-fail evaluation over an
     * index-naming edge case" posture the rest of this class already takes.
     *
     * @param string $indexName
     */
    protected function resolveConcreteIndexName(string $indexName): string
    {
        $cached = static::$concreteIndexNameCache[$indexName] ?? null;

        if ($cached !== null && $cached[1] > microtime(true) - static::CONCRETE_INDEX_NAME_CACHE_TTL_SECONDS) {
            return $cached[0];
        }

        try {
            $responseData = $this->elasticaClient->request(sprintf('%s/_alias', $indexName), Request::GET)->getData();
            $concreteIndexName = array_key_first($responseData);
        } catch (Throwable) {
            $concreteIndexName = null;
        }

        $concreteIndexName ??= $indexName;

        static::$concreteIndexNameCache[$indexName] = [$concreteIndexName, microtime(true)];

        return $concreteIndexName;
    }
}
