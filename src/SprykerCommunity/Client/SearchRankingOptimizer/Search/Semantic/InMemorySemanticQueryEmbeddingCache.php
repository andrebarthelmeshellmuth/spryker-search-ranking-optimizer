<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search\Semantic;

use SprykerCommunity\Client\SearchRanking\Semantic\SemanticQueryEmbeddingCacheInterface;

/**
 * Process-scoped (static array, NOT Redis) query-embedding cache used only by this package's own
 * evaluation tooling ({@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner}) --
 * deliberately not spryker-community/search-ranking's own Redis-backed `SemanticQueryEmbeddingCache`,
 * which needs a `SearchRankingToStorageClientInterface` this package would otherwise have to plumb a
 * whole new cross-cutting Storage Client dependency in just for this one, purely-internal, dedup concern.
 *
 * The `static` array (not an instance property) matters for the same reason
 * {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner::$idfCache} is `static`:
 * `SearchRankingOptimizerFactory::createRankEvalRunner()` constructs a FRESH `RankEvalRunner` -- and
 * therefore a fresh instance of whatever cache is injected -- on every single call, but one automated
 * optimization/evaluation run fires `evaluate()` potentially thousands of times within one continuous
 * console-command process. An instance-level array would never see a cache hit across those fresh
 * instances; this one does, for the lifetime of the process.
 *
 * Never persists beyond one process, unlike search-ranking's own Redis-backed cache used by real
 * storefront traffic -- acceptable here since this class only ever serves batch evaluation runs, never
 * live shopper queries.
 */
class InMemorySemanticQueryEmbeddingCache implements SemanticQueryEmbeddingCacheInterface
{
    /**
     * @var array<string, array<int, float>>
     */
    protected static array $cache = [];

    /**
     * @param string $queryString
     * @param string $modelId
     *
     * @return array<int, float>|null
     */
    public function get(string $queryString, string $modelId): ?array
    {
        return static::$cache[$this->buildKey($queryString, $modelId)] ?? null;
    }

    /**
     * @param string $queryString
     * @param string $modelId
     * @param array<int, float> $vector
     */
    public function set(string $queryString, string $modelId, array $vector): void
    {
        static::$cache[$this->buildKey($queryString, $modelId)] = $vector;
    }

    /**
     * @param string $queryString
     * @param string $modelId
     */
    protected function buildKey(string $queryString, string $modelId): string
    {
        return $modelId . ':' . sha1($queryString);
    }
}
