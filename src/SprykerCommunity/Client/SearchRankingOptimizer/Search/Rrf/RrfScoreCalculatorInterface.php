<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf;

interface RrfScoreCalculatorInterface
{
    /**
     * Specification:
     * - Fuses two independently-ranked doc-id lists (best-to-worst, array index 0 = rank 1) via Reciprocal
     *   Rank Fusion: for the UNION of doc ids appearing in either list,
     *   `score(d) = 1/(rrfK + rankInLexical(d)) + 1/(rrfK + rankInSemantic(d))`, where rank is 1-based and a
     *   doc absent from one list contributes exactly 0 from that list (not an error, not a worst-case
     *   rank).
     * - Returns the FUSED doc-id order only (best-to-worst) -- the RRF score values themselves are an
     *   implementation detail the caller never needs, only the resulting order (nDCG only cares about
     *   order, not score magnitude).
     * - Ties are broken by original lexical rank (a doc earlier in $lexicalRankedDocIds wins a tie) --
     *   arbitrary but deterministic, so results are reproducible across runs. A doc present only in
     *   $semanticRankedDocIds, tied against another doc present only in $semanticRankedDocIds, is ordered by
     *   its own semantic rank instead (there is no lexical rank to break the tie by), since it is absent from
     *   the lexical list entirely.
     * - An empty $semanticRankedDocIds degrades to exactly $lexicalRankedDocIds's own order (every doc's
     *   score reduces to `1/(rrfK + lexicalRank)`, which is strictly monotonic in rank, so the RRF order and
     *   the original lexical order coincide) -- the graceful-degradation path for when semantic retrieval is
     *   unavailable. Symmetrically, an empty $lexicalRankedDocIds degrades to $semanticRankedDocIds's own
     *   order. Both empty returns an empty result.
     *
     * @param array<int, string> $lexicalRankedDocIds Ordered best-to-worst, array index 0 = rank 1.
     * @param array<int, string> $semanticRankedDocIds Ordered best-to-worst, array index 0 = rank 1.
     * @param int $rrfK Smoothing constant (standard default: 60).
     *
     * @return array<int, string> The fused ranking, ordered best-to-worst.
     */
    public function fuse(array $lexicalRankedDocIds, array $semanticRankedDocIds, int $rrfK): array;
}
