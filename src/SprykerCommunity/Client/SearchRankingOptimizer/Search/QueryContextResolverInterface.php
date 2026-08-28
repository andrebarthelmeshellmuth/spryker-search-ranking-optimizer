<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use Generated\Shared\Transfer\SearchRankingQueryContextTransfer;

interface QueryContextResolverInterface
{
    /**
     * Runs `SkuIdentifierAnalyzer`, `BrandAnalyzer`, and `CategoryAnalyzer` against `$searchTerm` so
     * evaluation honors Intent-Aware Alpha's identifier-match override AND Pass 3's navigational
     * relevanceWeight shift exactly like real storefront traffic does -- without this, an identifier/SKU
     * query's measured hybrid-search quality would reflect whatever `alpha` the candidate configuration
     * happens to specify globally, even though `FunctionScoreBuilder` itself would never actually apply
     * that alpha to this exact query live (see {@see \SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilder::buildTextComponent()}),
     * and a brand/category query's `brandMatchRelevanceWeightShift`/`categoryMatchRelevanceWeightShift`
     * would have nothing to act on at all.
     *
     * Returns `null` (never throws) whenever no storage client was wired in (the default -- degrades to
     * exactly today's behavior, no override/shift for any query) -- each analyzer's own `analyze()`
     * already degrades every internal failure (KV read error, malformed cached shape regex) to "no match"
     * without throwing, so nothing further needs catching here.
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     */
    public function resolve(string $searchTerm, string $storeName, string $localeName): ?SearchRankingQueryContextTransfer;

    /**
     * Composes {@see \SprykerCommunity\Client\SearchRanking\Search\NavigationalRelevanceWeightShiftCalculatorInterface}
     * on top of whatever `relevanceWeight` {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\SpecificityWeightingApplierInterface::apply()}
     * already produced — mirrors `SearchRankingFunctionScoreQueryExpanderPlugin::applyNavigationalShift()`'s
     * own composition order on the live storefront. A no-op (the same instance, unchanged) whenever there's
     * no configuration, no query context (e.g. no storage client wired in, so {@see resolve()} itself
     * already returned `null`), or no calculator wired in at all. Bundled onto this resolver, rather than
     * injected into `RankEvalRunner` as its own separate collaborator, since it only ever has anything to
     * act on when THIS resolver's own `resolve()` produced a real query context in the first place — the
     * two are the same "Intent-Aware Alpha" concern.
     *
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     * @param \Generated\Shared\Transfer\SearchRankingQueryContextTransfer|null $queryContextTransfer
     */
    public function applyNavigationalShift(
        ?SearchRankingConfigurationStorageTransfer $configurationTransfer,
        ?SearchRankingQueryContextTransfer $queryContextTransfer,
    ): ?SearchRankingConfigurationStorageTransfer;
}
