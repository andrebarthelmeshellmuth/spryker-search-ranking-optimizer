<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Elastica\Client;
use Elastica\Request;
use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculatorInterface;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig;
use Throwable;

/**
 * Split out of {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner} (which grew
 * too many orthogonal responsibilities across several build passes) — pure extraction, no behavioral
 * change; see that class's git history for the original single-class shape.
 *
 * Specificity-aware relevance weighting (see `search-ranking`'s own README) is applied here too, not just
 * on the live storefront: `search-ranking`'s `SearchRankingFunctionScoreQueryExpanderPlugin` is the ONLY
 * place that mechanism runs live, and `RankEvalRunner::applyRankingFormula()` calls
 * `FunctionScoreBuilder::build()` directly, bypassing that plugin entirely -- so a candidate/live
 * configuration's own specificityBlendWeight/specificityCurveExponent/specificityWeightExponent/specificityWeightShiftMagnitude
 * were silently inert during evaluation despite always being present on `SearchRankingConfigurationStorageTransfer`.
 * This class closes that gap by reimplementing the same shift formula
 * {@see \SprykerCommunity\Client\SearchRanking\Search\SpecificityWeightCalculator} uses -- reimplemented
 * rather than reusing that class (and its `QueryTermFrequencyFetcher` IO dependency) directly, since that
 * fetcher resolves its own index name via `Spryker\Shared\Kernel\Store::getInstance()`, unavailable in
 * this package's Zed/console execution context. `QuerySpecificityCalculator` itself (pure math, no Store
 * dependency at all) IS reused directly -- only the IO half needs reimplementing.
 *
 * `isSpecificityWeightingEnabled()`'s project-override resolution is NOT similarly reimplemented, though:
 * it asks {@see \SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface::isSpecificityWeightingEnabled()}
 * (a thin bridge to search-ranking's own Client), which is the correctly Locator-resolved, genuinely
 * project-override-aware path -- unlike `Shared\SearchRanking\SearchRankingConfig::isSpecificityWeightingEnabled()`
 * (a hardcoded `return false;`), that Client method has no Store-singleton/execution-context concern to
 * route around. `getSpecificityProbeFieldSearchAnalyzers()` is resolved the exact same way, for the exact
 * same reason.
 */
class SpecificityWeightingApplier implements SpecificityWeightingApplierInterface
{
    /**
     * @var int
     */
    protected const IDF_CACHE_TTL_SECONDS = 60;

    /**
     * Process-scoped cache of per-term idf values (`ln(N/df)`) per `"<indexName>:<searchTerm>"` —
     * deliberately `static`, not an instance property, for the same PHP-FPM-worker-reuse/one-run-fires-
     * this-thousands-of-times reasoning documented on {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner}'s
     * own former `$idfCache`.
     *
     * @var array<string, array{0: array<string, float>, 1: float}>
     */
    protected static array $idfCache = [];

    /**
     * @param \Elastica\Client $elasticaClient
     * @param \SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculatorInterface $querySpecificityCalculator
     * @param \SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface|null $searchRankingClient
     */
    public function __construct(
        protected Client $elasticaClient,
        protected QuerySpecificityCalculatorInterface $querySpecificityCalculator,
        protected ?SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient = null,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param string $indexName
     * @param string $searchTerm
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     */
    public function apply(
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
     * `_termvectors` probe against an artificial document — no real catalog query at all. A failing probe,
     * or a corpus with zero documents, must never break the evaluation it was fired alongside, so any
     * Throwable here is treated as "no usable signal" (falls back to the unadjusted configured
     * relevanceWeight via the empty array this returns). A term with zero real corpus evidence is skipped,
     * same reasoning as {@see \SprykerCommunity\Client\SearchRanking\Search\SpecificityWeightCalculator}.
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
}
