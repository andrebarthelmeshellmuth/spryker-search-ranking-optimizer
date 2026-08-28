<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingClientInterface;
use SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingUnavailableException;
use SprykerCommunity\Client\SearchRanking\Semantic\SemanticQueryEmbeddingCacheInterface;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

/**
 * Split out of {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner} (which grew
 * too many orthogonal responsibilities across several build passes) — pure extraction, no behavioral
 * change; see that class's git history for the original single-class shape.
 */
class QueryVectorResolver implements QueryVectorResolverInterface
{
    /**
     * @param \SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingClientInterface|null $embeddingClient
     * @param \SprykerCommunity\Client\SearchRanking\Semantic\SemanticQueryEmbeddingCacheInterface|null $semanticQueryEmbeddingCache
     */
    public function __construct(
        protected ?EmbeddingClientInterface $embeddingClient = null,
        protected ?SemanticQueryEmbeddingCacheInterface $semanticQueryEmbeddingCache = null,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param string $searchTerm
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $configurationTransfer
     * @param bool $ignoreAlphaGate
     */
    public function resolve(
        string $searchTerm,
        ?SearchRankingConfigurationStorageTransfer $configurationTransfer,
        bool $ignoreAlphaGate = false,
    ): ?array {
        if ($this->embeddingClient === null || $this->semanticQueryEmbeddingCache === null) {
            return null;
        }

        if (!$ignoreAlphaGate) {
            if ($configurationTransfer === null) {
                return null;
            }

            $alpha = $configurationTransfer->getAlpha();

            // A null alpha (no explicit setAlpha() call) is treated the same as the documented default of
            // 1.0 -- see FunctionScoreBuilder::buildTextComponent()'s own matching guard.
            if ($alpha === null || $alpha >= 1.0) {
                return null;
            }
        }

        $modelId = SearchRankingOptimizerConfig::getEmbeddingModelId();
        $cachedVector = $this->semanticQueryEmbeddingCache->get($searchTerm, $modelId);

        if ($cachedVector !== null) {
            return $cachedVector;
        }

        $queryText = SearchRankingOptimizerConfig::getEmbeddingQueryInstructionPrefix() . $searchTerm;

        try {
            $vector = $this->embeddingClient->embed($queryText);
        } catch (EmbeddingUnavailableException) {
            return null;
        }

        $this->semanticQueryEmbeddingCache->set($searchTerm, $modelId, $vector);

        return $vector;
    }
}
