<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Elastica\Client;
use Elastica\Query\AbstractQuery;
use Elastica\Request;
use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationQueryScoreTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface;
use SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface;
use SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculatorInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientInterface;
use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use Throwable;

/**
 * Specificity-aware relevance weighting (see `search-ranking`'s own README) is applied here too, not just
 * on the live storefront: `search-ranking`'s `SearchRankingFunctionScoreQueryExpanderPlugin` is the ONLY
 * place that mechanism runs live, and this class's `applyRankingFormula()` calls
 * `FunctionScoreBuilder::build()` directly, bypassing that plugin entirely -- so a candidate/live
 * configuration's own specificityBlendWeight/specificityCurveExponent/specificityWeightExponent/specificityWeightShiftMagnitude
 * were silently inert here despite always being present on `SearchRankingConfigurationStorageTransfer`.
 * `applySpecificityWeighting()` below closes that gap by reimplementing the same shift formula
 * {@see \SprykerCommunity\Client\SearchRanking\Search\SpecificityWeightCalculator} uses -- reimplemented
 * rather than reusing that class (and its `QueryTermFrequencyFetcher` IO dependency) directly, since that
 * fetcher resolves its own index name via `Spryker\Shared\Kernel\Store::getInstance()`, unavailable in
 * this package's Zed/console execution context (the same reason this class already builds its own
 * raw-Elastica queries with an explicit index name instead of the higher-level Store-dependent Client
 * abstractions). `QuerySpecificityCalculator` itself (pure math, no Store dependency at all) IS reused
 * directly -- only the IO half needs reimplementing.
 *
 * `isSpecificityWeightingEnabled()`'s project-override resolution is NOT similarly reimplemented, though:
 * it asks {@see \SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface::isSpecificityWeightingEnabled()}
 * (a thin bridge to search-ranking's own Client), which is the correctly Locator-resolved, genuinely
 * project-override-aware path -- unlike `Shared\SearchRanking\SearchRankingConfig::isSpecificityWeightingEnabled()`
 * (a hardcoded `return false;` this class used to call directly), that Client method has no
 * Store-singleton/execution-context concern to route around. `getSpecificityProbeFieldSearchAnalyzers()`
 * is resolved the exact same way, for the exact same reason.
 */
class RankEvalRunner implements RankEvalRunnerInterface
{
    protected Client $elasticaClient;

    protected IndexNameResolverInterface $indexNameResolver;

    protected LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder;

    protected FunctionScoreBuilderInterface $functionScoreBuilder;

    protected SearchRankingOptimizerToSearchRankingStorageClientInterface $searchRankingStorageClient;

    protected ?SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient;

    /**
     * @var int
     */
    protected const IDF_CACHE_TTL_SECONDS = 60;

    /**
     * Process-scoped cache of per-term idf values (`ln(N/df)`) per `"<indexName>:<searchTerm>"` —
     * deliberately `static`, not an instance property: {@see \SprykerCommunity\Client\SearchRankingOptimizer\SearchRankingOptimizerFactory::createRankEvalRunner()}
     * constructs a FRESH instance on every single call, but one automated optimization run fires this
     * class's `evaluate()` potentially thousands of times within ONE continuous console-command process —
     * and a search term's own idf values don't depend on anything the optimizer actually searches over
     * (relevanceWeight/metric weights/specificity blend/exponent/shift), only on the term itself and the
     * corpus, fetched once here regardless of what any single candidate proposes. Unlike the entropy-era
     * probe cache this replaces, the key does NOT include `localeName`: `_termvectors`' corpus stats are
     * already index-wide (this shop's `page` index is one-per-store-multiple-locales, see this package's
     * README on that approximation) regardless of which locale's live query happens to be running.
     *
     * NOT actually console-only, though: {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller\TestCurrentEvaluationController::indexAction()}
     * calls straight into `evaluate()` from a normal Zed HTTP request, which under PHP-FPM reuses worker
     * processes across many unrelated requests — this `static` property does NOT reset between them.
     * `IDF_CACHE_TTL_SECONDS` bounds the resulting staleness to a short window (still long enough for one
     * optimization run's thousands of calls to benefit from a single fetch) instead of caching forever.
     *
     * @var array<string, array{0: array<string, float>, 1: float}>
     */
    protected static array $idfCache = [];

    /**
     * @param \Elastica\Client $elasticaClient
     * @param \Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface $indexNameResolver
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder
     * @param \SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface $functionScoreBuilder
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientInterface $searchRankingStorageClient
     * @param \SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculatorInterface $querySpecificityCalculator
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface|null $searchRankingClient
     */
    public function __construct(
        Client $elasticaClient,
        IndexNameResolverInterface $indexNameResolver,
        LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder,
        FunctionScoreBuilderInterface $functionScoreBuilder,
        SearchRankingOptimizerToSearchRankingStorageClientInterface $searchRankingStorageClient,
        protected QuerySpecificityCalculatorInterface $querySpecificityCalculator,
        ?SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient = null,
    ) {
        $this->elasticaClient = $elasticaClient;
        $this->indexNameResolver = $indexNameResolver;
        $this->liveCatalogSearchQueryBuilder = $liveCatalogSearchQueryBuilder;
        $this->functionScoreBuilder = $functionScoreBuilder;
        $this->searchRankingStorageClient = $searchRankingStorageClient;
        $this->searchRankingClient = $searchRankingClient;
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

        foreach ($requestTransfer->getQueries() as $queryTransfer) {
            $ratings = [];

            foreach ($queryTransfer->getProductGains() as $productGainTransfer) {
                $ratings[] = [
                    '_index' => $indexName,
                    '_id' => $this->buildProductDocumentId($storeName, $localeName, $productGainTransfer->getIdProductAbstractOrFail()),
                    'rating' => $productGainTransfer->getGainOrFail(),
                ];
            }

            if ($ratings === []) {
                continue;
            }

            $searchTerm = $queryTransfer->getSearchTermOrFail();
            $elasticaQuery = $this->liveCatalogSearchQueryBuilder->build($searchTerm, $storeName, $localeName);
            $baseQuery = $elasticaQuery->getQuery();

            $perQueryConfigurationTransfer = $this->applySpecificityWeighting($indexName, $searchTerm, $configurationTransfer);
            $queryClause = $this->applyRankingFormula($baseQuery, $perQueryConfigurationTransfer);

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
     * Wraps the base catalog query in search-ranking's own business-signal function_score — the same
     * blend `SearchRankingFunctionScoreQueryExpanderPlugin` applies to every real storefront search — so
     * this evaluation measures the ACTUAL configured (or candidate) ranking formula's quality, not raw
     * Elasticsearch text relevance. Falls back to the unwrapped query whenever there's nothing to blend
     * (no configuration at all, or a configuration with no active non-zero metric weight) — exactly
     * mirroring that plugin's own "leave the query untouched" fallback.
     *
     * @param mixed $queryClause
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     */
    protected function applyRankingFormula(
        mixed $queryClause,
        ?SearchRankingConfigurationStorageTransfer $configurationTransfer,
    ): mixed {
        if ($configurationTransfer === null || !($queryClause instanceof AbstractQuery)) {
            return $queryClause;
        }

        $functionScore = $this->functionScoreBuilder->build($queryClause, $configurationTransfer);

        return $functionScore ?? $queryClause;
    }

    /**
     * Returns a clone of $configurationTransfer with `relevanceWeight` replaced by the specificity-adjusted
     * value for THIS search term — a no-op (the same instance, unchanged) when there's no configuration at
     * all, {@see \SprykerCommunity\Shared\SearchRanking\SearchRankingConfig::isSpecificityWeightingEnabled()}
     * is off (the same project-level gate {@see \SprykerCommunity\Client\SearchRanking\Plugin\Catalog\SearchRankingFunctionScoreQueryExpanderPlugin}
     * itself checks before ever firing the live probe — evaluation must never apply an effect live traffic
     * never applies, regardless of what a candidate configuration's own specificity fields say), or no
     * query term carries any real corpus evidence at all.
     *
     * @param string $indexName
     * @param string $searchTerm
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     */
    protected function applySpecificityWeighting(
        string $indexName,
        string $searchTerm,
        ?SearchRankingConfigurationStorageTransfer $configurationTransfer,
    ): ?SearchRankingConfigurationStorageTransfer {
        if ($configurationTransfer === null || !$this->isSpecificityWeightingEnabled()) {
            return $configurationTransfer;
        }

        $idfByTerm = $this->fetchIdfByTerm($indexName, $searchTerm);

        if ($idfByTerm === []) {
            return $configurationTransfer;
        }

        $rawSpecificity = $this->querySpecificityCalculator->calculateRawSpecificity(
            $idfByTerm,
            (float)$configurationTransfer->getSpecificityBlendWeight(),
        );
        $normalizedSpecificity = $this->querySpecificityCalculator->normalize(
            $rawSpecificity,
            (float)$configurationTransfer->getSpecificitySaturationPoint(),
            $configurationTransfer->getSpecificityCurveExponent() ?? 1.0,
        );

        $shift = $this->calculateSpecificityShift(
            $normalizedSpecificity,
            (float)$configurationTransfer->getSpecificityWeightExponent(),
            (float)$configurationTransfer->getSpecificityWeightShiftMagnitude(),
        );
        $adjustedRelevanceWeight = max(0.0, min(1.0, (float)$configurationTransfer->getRelevanceWeight() + $shift));

        $perQueryConfigurationTransfer = clone $configurationTransfer;
        $perQueryConfigurationTransfer->setRelevanceWeight($adjustedRelevanceWeight);

        return $perQueryConfigurationTransfer;
    }

    /**
     * Fetches (and process-caches, see {@see $idfCache}) per-term idf values for `$searchTerm` via ONE
     * `_termvectors` probe against an artificial document — no real catalog query at all, unlike the
     * entropy-era probe this replaces. A failing probe, or a corpus with zero documents, must never break
     * the evaluation it was fired alongside, so any Throwable here is treated as "no usable signal" (falls
     * back to the unadjusted configured relevanceWeight via the empty array this returns). A term with
     * zero real corpus evidence is skipped, same reasoning as {@see \SprykerCommunity\Client\SearchRanking\Search\SpecificityWeightCalculator}.
     * Same store-only-index tradeoff as {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\SpecificitySearcher}'s
     * own docblock (dictionary lookup, corpus-wide, blended across locales sharing one store) — see there
     * for why, and for the aggregation-based alternative if that blending ever needs fixing for real.
     *
     * @param string $indexName
     * @param string $searchTerm
     *
     * @return array<string, float>
     */
    protected function fetchIdfByTerm(string $indexName, string $searchTerm): array
    {
        $cacheKey = $indexName . ':' . $searchTerm;

        if (isset(static::$idfCache[$cacheKey]) && static::$idfCache[$cacheKey][1] > microtime(true)) {
            return static::$idfCache[$cacheKey][0];
        }

        $fieldToSearchAnalyzer = $this->searchRankingClient !== null
            ? $this->searchRankingClient->getSpecificityProbeFieldSearchAnalyzers()
            : [];

        $idfByTerm = [];

        if ($searchTerm !== '' && $fieldToSearchAnalyzer !== []) {
            try {
                $responseData = $this->elasticaClient->request(
                    sprintf('%s/_termvectors', $indexName),
                    Request::POST,
                    [
                        'doc' => array_fill_keys(array_keys($fieldToSearchAnalyzer), $searchTerm),
                        'fields' => array_keys($fieldToSearchAnalyzer),
                        'per_field_analyzer' => $fieldToSearchAnalyzer,
                        'term_statistics' => true,
                        'field_statistics' => true,
                    ],
                )->getData();

                $idfByTerm = $this->calculateIdfByTermFromResponse($responseData);
            } catch (Throwable) {
                $idfByTerm = [];
            }
        }

        static::$idfCache[$cacheKey] = [$idfByTerm, microtime(true) + static::IDF_CACHE_TTL_SECONDS];

        return $idfByTerm;
    }

    /**
     * Mirrors {@see \SprykerCommunity\Client\SearchRanking\Search\QueryTermFrequencyFetcher}'s own
     * `_termvectors` response parsing (`doc_freq` `max()`-combined across fields, `doc_count` `max()`-ed
     * across fields, a missing key treated as a real `0`) plus
     * {@see \SprykerCommunity\Client\SearchRanking\Search\SpecificityWeightCalculator}'s own idf derivation
     * (`ln(N/df)`, skipping any term with zero real corpus evidence) in one pass, since this class needs
     * only the final per-term idf map, not the intermediate doc-frequency result object.
     *
     * @param array<string, mixed> $responseData
     *
     * @return array<string, float>
     */
    protected function calculateIdfByTermFromResponse(array $responseData): array
    {
        [$docCount, $termDocumentFrequencies] = $this->extractDocCountAndTermFrequencies($responseData);

        if ($docCount <= 0) {
            return [];
        }

        $idfByTerm = [];

        foreach ($termDocumentFrequencies as $term => $documentFrequency) {
            if ($documentFrequency <= 0) {
                continue;
            }

            $idfByTerm[$term] = max(0.0, log($docCount / $documentFrequency));
        }

        return $idfByTerm;
    }

    /**
     * The `doc_count`/`doc_freq` extraction half of {@see calculateIdfByTermFromResponse()}, split out
     * purely to keep that method's own cyclomatic/NPath complexity down — no behavioral change.
     *
     * @param array<string, mixed> $responseData
     *
     * @return array{0: int, 1: array<string, int>}
     */
    protected function extractDocCountAndTermFrequencies(array $responseData): array
    {
        $termVectorsByField = is_array($responseData['term_vectors'] ?? null) ? $responseData['term_vectors'] : [];

        $docCount = 0;
        $termDocumentFrequencies = [];

        foreach ($termVectorsByField as $fieldTermVector) {
            if (!is_array($fieldTermVector)) {
                continue;
            }

            $fieldStatistics = is_array($fieldTermVector['field_statistics'] ?? null) ? $fieldTermVector['field_statistics'] : [];
            $docCount = max($docCount, (int)($fieldStatistics['doc_count'] ?? 0));

            $terms = is_array($fieldTermVector['terms'] ?? null) ? $fieldTermVector['terms'] : [];

            foreach ($terms as $term => $termStatistics) {
                $documentFrequency = (int)($termStatistics['doc_freq'] ?? 0);
                $termDocumentFrequencies[$term] = max($termDocumentFrequencies[$term] ?? 0, $documentFrequency);
            }
        }

        return [$docCount, $termDocumentFrequencies];
    }

    /**
     * Ported from `search-ranking`'s own {@see \SprykerCommunity\Client\SearchRanking\Search\SpecificityWeightCalculator::calculateShift()}
     * — see this class's own docblock for why it's reimplemented rather than reused directly. `2 *
     * normalizedSpecificity - 1` maps specificity's `[0;1[` range onto a signed `[-1;1]` deviation from the
     * neutral point (normalized specificity exactly 0.5 → deviation 0): positive when the query is MORE
     * specific than typical (shift toward text relevance), negative when it's LESS specific than typical
     * (shift toward business signals). The exponent is applied to the deviation's MAGNITUDE only, sign
     * preserved separately.
     *
     * @param float $normalizedSpecificity
     * @param float $exponent
     * @param float $shiftMagnitude
     */
    protected function calculateSpecificityShift(float $normalizedSpecificity, float $exponent, float $shiftMagnitude): float
    {
        $signedDeviation = (2 * $normalizedSpecificity) - 1;
        $shapedDeviation = ($signedDeviation <=> 0) * (abs($signedDeviation) ** $exponent);

        return $shiftMagnitude * $shapedDeviation;
    }

    /**
     * Delegates to search-ranking's own Client (via the injected bridge) whenever one was provided — the
     * correctly Locator-resolved, genuinely project-override-aware answer, matching exactly what
     * `SearchRankingFunctionScoreQueryExpanderPlugin` itself checks before firing the live probe. Falls
     * back to `Shared\SearchRanking\SearchRankingConfig::isSpecificityWeightingEnabled()` — a hardcoded
     * `return false;` with no project-override path of its own — only when no bridge was given at all.
     * The bridge parameter is optional, so this method never hard-fails when it's omitted; it just can't
     * honor a project override without it.
     */
    protected function isSpecificityWeightingEnabled(): bool
    {
        if ($this->searchRankingClient !== null) {
            return $this->searchRankingClient->isSpecificityWeightingEnabled();
        }

        return SearchRankingConfig::isSpecificityWeightingEnabled();
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
}
