<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Elastica\Client;
use Elastica\Query;
use Elastica\Query\AbstractQuery;
use Elastica\Request;
use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationQueryScoreTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface;
use SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface;
use SprykerCommunity\Client\SearchRanking\Search\ShannonEntropyCalculator;
use SprykerCommunity\Client\SearchRanking\Search\ShannonEntropyCalculatorInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientInterface;
use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use Throwable;

/**
 * Entropy-aware relevance weighting (see `search-ranking`'s own README) is applied here too, not just on
 * the live storefront: `search-ranking`'s `SearchRankingFunctionScoreQueryExpanderPlugin` is the ONLY place
 * that mechanism runs live, and this class's `applyRankingFormula()` calls `FunctionScoreBuilder::build()`
 * directly, bypassing that plugin entirely -- so a candidate/live configuration's own
 * entropyProbeResultSize/entropyWeightExponent/entropyWeightShiftMagnitude were silently inert here despite
 * always being present on `SearchRankingConfigurationStorageTransfer`. `applyEntropyWeighting()` below
 * closes that gap by reimplementing the same shift formula {@see \SprykerCommunity\Client\SearchRanking\Search\EntropyWeightCalculator}
 * uses -- reimplemented rather than reusing that class directly, since it resolves its own index name via
 * `Spryker\Shared\Kernel\Store::getInstance()`, unavailable in this package's Zed/console execution context
 * (the same reason this class already builds its own raw-Elastica queries with an explicit index name
 * instead of the higher-level Store-dependent Client abstractions).
 */
class RankEvalRunner implements RankEvalRunnerInterface
{
    /**
     * @var \Elastica\Client
     */
    protected Client $elasticaClient;

    /**
     * @var \Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface
     */
    protected IndexNameResolverInterface $indexNameResolver;

    /**
     * @var \SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilderInterface
     */
    protected LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder;

    /**
     * @var \SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface
     */
    protected FunctionScoreBuilderInterface $functionScoreBuilder;

    /**
     * @var \SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientInterface
     */
    protected SearchRankingOptimizerToSearchRankingStorageClientInterface $searchRankingStorageClient;

    /**
     * @var \SprykerCommunity\Client\SearchRanking\Search\ShannonEntropyCalculatorInterface
     */
    protected ShannonEntropyCalculatorInterface $entropyCalculator;

    /**
     * Process-scoped cache of raw `_score` values per `"<indexName>:<searchTerm>"` — deliberately
     * `static`, not an instance property: {@see \SprykerCommunity\Client\SearchRankingOptimizer\SearchRankingOptimizerFactory::createRankEvalRunner()}
     * constructs a FRESH instance on every single call, but one automated optimization run fires this
     * class's `evaluate()` potentially thousands of times within ONE continuous console-command process —
     * and the raw probe scores for a given search term don't depend on anything the optimizer actually
     * searches over (relevanceWeight/metric weights/entropy exponent/shift), only on
     * `entropyProbeResultSize`'s configured UPPER bound, fetched once here regardless of what any single
     * candidate proposes. Safe specifically because this class is only ever driven from a short-lived
     * console-command process, never a long-lived web worker that would leak this across unrelated
     * requests.
     *
     * @var array<string, array<float>>
     */
    protected static array $probeScoresCache = [];

    /**
     * @param \Elastica\Client $elasticaClient
     * @param \Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolverInterface $indexNameResolver
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder
     * @param \SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilderInterface $functionScoreBuilder
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientInterface $searchRankingStorageClient
     * @param \SprykerCommunity\Client\SearchRanking\Search\ShannonEntropyCalculatorInterface|null $entropyCalculator
     */
    public function __construct(
        Client $elasticaClient,
        IndexNameResolverInterface $indexNameResolver,
        LiveCatalogSearchQueryBuilderInterface $liveCatalogSearchQueryBuilder,
        FunctionScoreBuilderInterface $functionScoreBuilder,
        SearchRankingOptimizerToSearchRankingStorageClientInterface $searchRankingStorageClient,
        ?ShannonEntropyCalculatorInterface $entropyCalculator = null,
    ) {
        $this->elasticaClient = $elasticaClient;
        $this->indexNameResolver = $indexNameResolver;
        $this->liveCatalogSearchQueryBuilder = $liveCatalogSearchQueryBuilder;
        $this->functionScoreBuilder = $functionScoreBuilder;
        $this->searchRankingStorageClient = $searchRankingStorageClient;
        $this->entropyCalculator = $entropyCalculator ?? new ShannonEntropyCalculator();
    }

    /**
     * {@inheritDoc}
     *
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer $requestTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer
     */
    public function evaluate(SearchRankingEvaluationRequestTransfer $requestTransfer): SearchRankingEvaluationResponseTransfer
    {
        $responseTransfer = new SearchRankingEvaluationResponseTransfer();

        $storeName = $requestTransfer->getStoreNameOrFail();
        $localeName = $requestTransfer->getLocaleNameOrFail();
        $indexName = $this->indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, $storeName);
        $configurationTransfer = $requestTransfer->getRankingConfiguration() ?? $this->searchRankingStorageClient->findRankingConfiguration();

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

            $perQueryConfigurationTransfer = $this->applyEntropyWeighting($baseQuery, $indexName, $searchTerm, $configurationTransfer);
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
     *
     * @return mixed
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
     * Returns a clone of $configurationTransfer with `relevanceWeight` replaced by the entropy-adjusted
     * value for THIS query's own raw-score distribution — a no-op (the same instance, unchanged) when
     * there's no configuration at all, {@see \SprykerCommunity\Shared\SearchRanking\SearchRankingConfig::isEntropyWeightingEnabled()}
     * is off (the same project-level gate {@see \SprykerCommunity\Client\SearchRanking\Plugin\Catalog\SearchRankingFunctionScoreQueryExpanderPlugin}
     * itself checks before ever firing the live probe query — evaluation must never apply an effect live
     * traffic never applies, regardless of what a candidate configuration's own entropy fields say), or its
     * `entropyProbeResultSize` isn't a meaningful value (unset, or below the 2 scores
     * {@see \SprykerCommunity\Client\SearchRanking\Search\ShannonEntropyCalculator} itself requires to
     * compute anything).
     *
     * @param \Elastica\Query\AbstractQuery $baseQuery
     * @param string $indexName
     * @param string $searchTerm
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null
     */
    protected function applyEntropyWeighting(
        AbstractQuery $baseQuery,
        string $indexName,
        string $searchTerm,
        ?SearchRankingConfigurationStorageTransfer $configurationTransfer,
    ): ?SearchRankingConfigurationStorageTransfer {
        if ($configurationTransfer === null || !$this->isEntropyWeightingEnabled()) {
            return $configurationTransfer;
        }

        $probeResultSize = $configurationTransfer->getEntropyProbeResultSize();

        if ($probeResultSize === null || $probeResultSize < 2) {
            return $configurationTransfer;
        }

        $scores = array_slice($this->fetchProbeScores($baseQuery, $indexName, $searchTerm), 0, $probeResultSize);

        if (count($scores) < 2) {
            return $configurationTransfer;
        }

        $normalizedEntropy = $this->entropyCalculator->calculateNormalizedEntropy($scores);
        $shift = $this->calculateEntropyShift(
            $normalizedEntropy,
            (float)$configurationTransfer->getEntropyWeightExponent(),
            (float)$configurationTransfer->getEntropyWeightShiftMagnitude(),
        );
        $adjustedRelevanceWeight = max(0.0, min(1.0, (float)$configurationTransfer->getRelevanceWeight() + $shift));

        $perQueryConfigurationTransfer = clone $configurationTransfer;
        $perQueryConfigurationTransfer->setRelevanceWeight($adjustedRelevanceWeight);

        return $perQueryConfigurationTransfer;
    }

    /**
     * Fetches (and process-caches, see {@see $probeScoresCache}) the raw `_score` values for the top
     * {@see \SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig::getMaxEntropyProbeResultSize()}
     * results of $baseQuery — a failing or empty probe must never break the evaluation it was fired
     * alongside, so any Throwable here is treated as "no usable signal" (falls back to the unadjusted
     * configured relevanceWeight via the empty array this returns).
     *
     * @param \Elastica\Query\AbstractQuery $baseQuery
     * @param string $indexName
     * @param string $searchTerm
     *
     * @return array<float>
     */
    protected function fetchProbeScores(AbstractQuery $baseQuery, string $indexName, string $searchTerm): array
    {
        $cacheKey = $indexName . ':' . $searchTerm;

        if (isset(static::$probeScoresCache[$cacheKey])) {
            return static::$probeScoresCache[$cacheKey];
        }

        try {
            $probeQuery = Query::create($baseQuery);
            $probeQuery->setSize(SearchRankingOptimizerConfig::getMaxEntropyProbeResultSize());
            $probeQuery->setSource(false);

            $resultSet = $this->elasticaClient->getIndex($indexName)->search($probeQuery);
            $scores = array_map(static fn ($result) => $result->getScore(), $resultSet->getResults());
        } catch (Throwable) {
            $scores = [];
        }

        static::$probeScoresCache[$cacheKey] = $scores;

        return $scores;
    }

    /**
     * Ported from `search-ranking`'s own {@see \SprykerCommunity\Client\SearchRanking\Search\EntropyWeightCalculator::calculateShift()}
     * — see this class's own docblock for why it's reimplemented rather than reused directly. `1 - 2 *
     * normalizedEntropy` maps entropy's `[0;1]` range onto a signed `[-1;1]` deviation from the neutral
     * point (entropy exactly 0.5 → deviation 0): positive when one score dominates (shift toward text
     * relevance), negative when the distribution is flat (shift toward business signals). The exponent is
     * applied to the deviation's MAGNITUDE only, sign preserved separately.
     *
     * @param float $normalizedEntropy
     * @param float $exponent
     * @param float $shiftMagnitude
     *
     * @return float
     */
    protected function calculateEntropyShift(float $normalizedEntropy, float $exponent, float $shiftMagnitude): float
    {
        $signedDeviation = 1 - (2 * $normalizedEntropy);
        $shapedDeviation = ($signedDeviation <=> 0) * (abs($signedDeviation) ** $exponent);

        return $shiftMagnitude * $shapedDeviation;
    }

    /**
     * Wraps the static call behind an overridable instance method purely for testability (the flag itself
     * has no runtime override mechanism in `search-ranking` — it's a hardcoded `return false;`, project
     * overrides are claimed in that class's own docblock but nothing anywhere actually resolves through
     * one) — a test subclass can override this one method to exercise the enabled branch without needing
     * to fake a project-level config class.
     *
     * @return bool
     */
    protected function isEntropyWeightingEnabled(): bool
    {
        return SearchRankingConfig::isEntropyWeightingEnabled();
    }

    /**
     * The `page` index's own document id format, confirmed live against this shop's real OpenSearch index
     * (`product_abstract:{store}:{locale}:{idProductAbstract}`, store/locale lowercased) — computed
     * directly rather than looked up, since the id_product_abstract is already exactly what
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface}
     * stores per rating.
     *
     * @param string $storeName
     * @param string $localeName
     * @param int $idProductAbstract
     *
     * @return string
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
