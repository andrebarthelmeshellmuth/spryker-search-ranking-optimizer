<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\GroundTruth\SearchRankingOptimizer;

use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

/**
 * Opt-in, not part of the default test run -- see {@see AbstractGroundTruthTest}'s own docblock for why and
 * how.
 *
 * Unlike `relevanceWeight`/metric weights (which have 2 equally legitimate extremes a ground truth can
 * favor, either "this signal should dominate" or "the other one should"), `entropyWeightShiftMagnitude` has
 * only ONE documented-correct direction: positive (shift toward text relevance for a peaked/low-entropy
 * query, toward business signals for a flat/high-entropy one -- see `search-ranking`'s own README). There
 * is no legitimate "should converge negative" ground truth to build the way there is for the other
 * parameters, so this suite proves the one claim that IS meaningful: given a real peaked query (correctly
 * solved by relevanceWeight alone) and a real flat query (correctly solved only by leaning on a business
 * signal instead) evaluated SIMULTANEOUSLY in one run, the optimizer converges on a POSITIVE shift
 * magnitude -- the only way one static relevanceWeight-independent-of-shift could satisfy both queries at
 * once.
 */
class EntropyWeightShiftMagnitudeGroundTruthTest extends AbstractGroundTruthTest
{
    /**
     * @return void
     */
    public function testEntropyWeightShiftMagnitudeConvergesPositive(): void
    {
        [$peakedSearchTerm, $flatSearchTerm] = $this->discoverPeakedAndFlatSearchTerms();
        [$metricA] = $this->discoverTwoOptimizableMetricNames();
        $zeroedScores = $this->buildAllActiveMetricsZeroedOut();

        $peakedScores = $this->fetchRawTextRelevanceScores($peakedSearchTerm);
        $peakedProductIds = array_keys($peakedScores);
        $peakedWinner = $peakedProductIds[0];
        $peakedLoser = $peakedProductIds[1];

        [$flatWinner, $flatLoser] = $this->discoverTiedTextRelevancePair($flatSearchTerm);

        $originalPeakedWinnerScores = $this->readScores($peakedWinner);
        $originalPeakedLoserScores = $this->readScores($peakedLoser);
        $originalFlatWinnerScores = $this->readScores($flatWinner);
        $originalFlatLoserScores = $this->readScores($flatLoser);

        try {
            // Peaked query: relevanceWeight alone (no business signal at all) already ranks this correctly
            // -- rate strictly by the real, dominant text match.
            $this->overrideScores($peakedWinner, $zeroedScores);
            $this->overrideScores($peakedLoser, $zeroedScores);

            // Flat query: text is a genuine tie, so ONLY a business signal can rank this correctly -- rate
            // the one with a real, controlled metric signal as the winner.
            $this->overrideScores($flatWinner, array_merge($zeroedScores, [$metricA => 1.0]));
            $this->overrideScores($flatLoser, $zeroedScores);
            $this->refreshIndex();

            $idPeakedQuery = $this->insertSyntheticQuery($peakedSearchTerm);
            $this->insertSyntheticRating($idPeakedQuery, $peakedWinner, SearchRankingOptimizerConfig::RATING_TYPE_HEART);
            $this->insertSyntheticRating($idPeakedQuery, $peakedLoser, SearchRankingOptimizerConfig::RATING_TYPE_X);

            $idFlatQuery = $this->insertSyntheticQuery($flatSearchTerm);
            $this->insertSyntheticRating($idFlatQuery, $flatWinner, SearchRankingOptimizerConfig::RATING_TYPE_HEART);
            $this->insertSyntheticRating($idFlatQuery, $flatLoser, SearchRankingOptimizerConfig::RATING_TYPE_X);

            $runTransfer = $this->runRealOptimizationWithEntropyForcedEnabled();

            $this->deleteSyntheticQuery($idPeakedQuery);
            $this->deleteSyntheticQuery($idFlatQuery);

            $this->assertGreaterThan(
                0.0,
                $runTransfer->getBestEntropyWeightShiftMagnitudeOrFail(),
                sprintf(
                    'entropyWeightShiftMagnitude should converge positive: only a positive shift (toward relevance for the peaked query, toward business signal for the flat one) can satisfy both simultaneously with one shared relevanceWeight. Got %.6f.',
                    $runTransfer->getBestEntropyWeightShiftMagnitudeOrFail(),
                ),
            );
        } finally {
            $this->overrideScores($peakedWinner, $originalPeakedWinnerScores);
            $this->overrideScores($peakedLoser, $originalPeakedLoserScores);
            $this->overrideScores($flatWinner, $originalFlatWinnerScores);
            $this->overrideScores($flatLoser, $originalFlatLoserScores);
            $this->refreshIndex();
        }
    }
}
