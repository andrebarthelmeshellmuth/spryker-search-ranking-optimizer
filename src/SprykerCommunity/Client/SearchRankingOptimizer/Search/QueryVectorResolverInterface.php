<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;

interface QueryVectorResolverInterface
{
    /**
     * Resolves `$searchTerm`'s semantic embedding for the hybrid-search blend -- mirrors
     * {@see \SprykerCommunity\Client\SearchRanking\Plugin\Catalog\SearchRankingFunctionScoreQueryExpanderPlugin::resolveQueryVector()}'s
     * exact contract (same cache-first-then-embed-with-graceful-degradation shape, same `alpha == 1.0`
     * short-circuit), reimplemented here rather than reused directly for the same Store/Locator-context
     * reason {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner}'s own docblock
     * gives for reimplementing the specificity-probe IO instead of reusing `QueryTermFrequencyFetcher`:
     * that plugin only runs inside a real Yves/Client request, unreachable from this package's Zed/console
     * execution context.
     *
     * Returns `null` (never throws) whenever no vector can usefully be used: no embedding client/cache
     * wired in, no configuration at all, `alpha == null` or `alpha >= 1.0` (100% lexical, no point paying
     * for an embedding that would never be blended in) -- unless `$ignoreAlphaGate` is `true`, or a cache
     * miss followed by any {@see \SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingUnavailableException}
     * (embedding service down/timed out/misconfigured/not yet booted). `FunctionScoreBuilder::build()`
     * degrades to exactly the lexical-only formula whenever this returns `null` -- an embedding failure
     * must never abort the whole evaluation run, exactly like the live plugin never lets it abort a real
     * search request.
     *
     * @param string $searchTerm
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     * @param bool $ignoreAlphaGate RRF mode always needs a real vector to run its own kNN candidate query,
     *   regardless of what `alpha` is set to -- RRF is a fundamentally different fusion mode that doesn't
     *   gate semantic retrieval on alpha at all (that knob only governs the LINEAR blend). Every other
     *   caller keeps the original alpha-gated contract by leaving this `false`.
     *
     * @return array<int, float>|null
     */
    public function resolve(
        string $searchTerm,
        ?SearchRankingConfigurationStorageTransfer $configurationTransfer,
        bool $ignoreAlphaGate = false,
    ): ?array;
}
