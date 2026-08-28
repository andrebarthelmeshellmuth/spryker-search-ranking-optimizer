<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Client\SearchRankingOptimizer\Search;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingClientInterface;
use SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingUnavailableException;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\QueryVectorResolver;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\Semantic\InMemorySemanticQueryEmbeddingCache;

/**
 * Split out of {@see \SprykerCommunityTest\Client\SearchRankingOptimizer\Search\RankEvalRunnerTest}'s own
 * test suite when {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner} was
 * decomposed into smaller collaborators -- `resolve()` is public here, so this test no longer needs
 * reflection into a protected `RankEvalRunner` method.
 *
 * @group SprykerCommunityTest
 * @group Client
 * @group SearchRankingOptimizer
 * @group Search
 * @group QueryVectorResolverTest
 */
class QueryVectorResolverTest extends Unit
{
    /**
     * Proves `embed()` is never even attempted once `alpha >= 1.0` — the exact short-circuit
     * {@see \SprykerCommunity\Client\SearchRanking\Plugin\Catalog\SearchRankingFunctionScoreQueryExpanderPlugin::resolveQueryVector()}
     * itself applies. Uses a spying stub rather than a real embedding client so a bug that fires an
     * unnecessary embedding call (wasted latency/cost against a real embedding service, once one exists) is
     * caught even though it wouldn't currently change any score.
     *
     * @throws \SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingUnavailableException
     */
    public function testResolveNeverCallsEmbedWhenAlphaIsAtOrAboveOne(): void
    {
        // Arrange
        $spyingEmbeddingClient = new class implements EmbeddingClientInterface {
            public int $embedCallCount = 0;

            // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- signature is fixed by the
            //   interface this test double implements; never actually reached by this test.
            /**
             * @param string $text
             *
             * @throws \SprykerCommunity\Client\SearchRanking\Semantic\EmbeddingUnavailableException
             */
            public function embed(string $text): array
            {
                // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter
                $this->embedCallCount++;

                throw new EmbeddingUnavailableException('Should never be called for alpha >= 1.0.');
            }
        };

        $resolver = new QueryVectorResolver($spyingEmbeddingClient, new InMemorySemanticQueryEmbeddingCache());

        $lexicalConfigurationTransfer = (new SearchRankingConfigurationStorageTransfer())->setAlpha(1.0);
        $unsetAlphaConfigurationTransfer = new SearchRankingConfigurationStorageTransfer();

        // Act
        $resultForAlphaOne = $resolver->resolve('chair', $lexicalConfigurationTransfer);
        $resultForUnsetAlpha = $resolver->resolve('chair', $unsetAlphaConfigurationTransfer);

        // Assert
        $this->assertNull($resultForAlphaOne);
        $this->assertNull($resultForUnsetAlpha);
        $this->assertSame(0, $spyingEmbeddingClient->embedCallCount, 'embed() must never be called when alpha >= 1.0 or unset -- there is no vector to blend in either case.');
    }

    public function testResolveReturnsNullWhenNoEmbeddingClientIsWiredIn(): void
    {
        // Arrange
        $resolver = new QueryVectorResolver(null, new InMemorySemanticQueryEmbeddingCache());
        $configurationTransfer = (new SearchRankingConfigurationStorageTransfer())->setAlpha(0.5);

        // Act
        $result = $resolver->resolve('chair', $configurationTransfer);

        // Assert
        $this->assertNull($result);
    }
}
