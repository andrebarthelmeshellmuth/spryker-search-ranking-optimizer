<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf;

/**
 * Pure PHP, no ES dependency -- see {@see RrfScoreCalculatorInterface} for the full contract.
 */
class RrfScoreCalculator implements RrfScoreCalculatorInterface
{
    /**
     * {@inheritDoc}
     *
     * @param array<int, string> $lexicalRankedDocIds
     * @param array<int, string> $semanticRankedDocIds
     * @param int $rrfK
     *
     * @return array<int, string>
     */
    public function fuse(array $lexicalRankedDocIds, array $semanticRankedDocIds, int $rrfK): array
    {
        $lexicalRankByDocId = $this->buildRankByDocId($lexicalRankedDocIds);
        $semanticRankByDocId = $this->buildRankByDocId($semanticRankedDocIds);

        $docIds = array_unique(array_merge($lexicalRankedDocIds, $semanticRankedDocIds));

        $rows = [];

        foreach ($docIds as $docId) {
            $lexicalRank = $lexicalRankByDocId[$docId] ?? null;
            $semanticRank = $semanticRankByDocId[$docId] ?? null;

            $score = ($lexicalRank !== null ? 1 / ($rrfK + $lexicalRank) : 0.0)
                + ($semanticRank !== null ? 1 / ($rrfK + $semanticRank) : 0.0);

            $rows[] = [
                'docId' => $docId,
                'score' => $score,
                'lexicalRank' => $lexicalRank,
                'semanticRank' => $semanticRank,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $this->compareByTieBreakRank($a, $b);
        });

        return array_column($rows, 'docId');
    }

    /**
     * @param array<int, string> $rankedDocIds
     *
     * @return array<string, int>
     */
    protected function buildRankByDocId(array $rankedDocIds): array
    {
        $rankByDocId = [];

        foreach (array_values($rankedDocIds) as $index => $docId) {
            // 1-based rank; array_values() re-indexes so a caller-supplied array with gaps still works.
            $rankByDocId[$docId] = $index + 1;
        }

        return $rankByDocId;
    }

    /**
     * Tie-break: a doc that carries a lexical rank at all wins over one that doesn't (regardless of the
     * two ranks' relative magnitude, a doc present in the lexical list is "earlier" than one that's
     * effectively at infinite lexical rank); between two docs that both carry a lexical rank, the smaller
     * one wins; between two docs that carry NEITHER a lexical rank (both semantic-only), the smaller
     * semantic rank wins instead, since there is no lexical rank to break the tie by.
     *
     * @param array<string, mixed> $a array{docId: string, score: float, lexicalRank: int|null, semanticRank: int|null}
     * @param array<string, mixed> $b array{docId: string, score: float, lexicalRank: int|null, semanticRank: int|null}
     */
    protected function compareByTieBreakRank(array $a, array $b): int
    {
        if ($a['lexicalRank'] !== null && $b['lexicalRank'] !== null) {
            return $a['lexicalRank'] <=> $b['lexicalRank'];
        }

        if ($a['lexicalRank'] !== null) {
            return -1;
        }

        if ($b['lexicalRank'] !== null) {
            return 1;
        }

        return ($a['semanticRank'] ?? 0) <=> ($b['semanticRank'] ?? 0);
    }
}
