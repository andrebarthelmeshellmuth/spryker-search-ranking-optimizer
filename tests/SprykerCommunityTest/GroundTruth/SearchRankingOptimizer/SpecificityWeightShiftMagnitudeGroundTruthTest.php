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
 * favor, either "this signal should dominate" or "the other one should"), `specificityWeightShiftMagnitude`
 * has only ONE documented-correct direction: positive (shift toward text relevance for a highly specific
 * query, toward business signals for an unspecific/browsy one -- see `search-ranking`'s own README). There
 * is no legitimate "should converge negative" ground truth to build the way there is for the other
 * parameters, so this suite proves the one claim that IS meaningful: given a real specific query
 * (correctly solved by relevanceWeight alone) and a real unspecific query (correctly solved only by
 * leaning on a business signal instead) evaluated SIMULTANEOUSLY in one run, the optimizer converges on a
 * POSITIVE shift magnitude -- the only way one static relevanceWeight-independent-of-shift could satisfy
 * both queries at once.
 *
 * @group NeedsSearch
 */
class SpecificityWeightShiftMagnitudeGroundTruthTest extends AbstractGroundTruthTest
{
    public function testSpecificityWeightShiftMagnitudeConvergesPositive(): void
    {
        [$specificSearchTerm, $unspecificSearchTerm] = $this->discoverSpecificAndUnspecificSearchTerms();
        [$metricA] = $this->discoverTwoOptimizableMetricNames();
        $zeroedScores = $this->buildAllActiveMetricsZeroedOut();

        $specificScores = $this->fetchRawTextRelevanceScores($specificSearchTerm);
        $specificProductIds = array_keys($specificScores);
        $specificWinner = $specificProductIds[0];
        $specificLoser = $specificProductIds[1];

        [$unspecificWinner, $unspecificLoser] = $this->discoverTiedTextRelevancePair($unspecificSearchTerm);

        $originalSpecificWinnerScores = $this->readScores($specificWinner);
        $originalSpecificLoserScores = $this->readScores($specificLoser);
        $originalUnspecificWinnerScores = $this->readScores($unspecificWinner);
        $originalUnspecificLoserScores = $this->readScores($unspecificLoser);

        try {
            // Specific query: relevanceWeight alone (no business signal at all) already ranks this
            // correctly -- rate strictly by the real, dominant text match.
            $this->overrideScores($specificWinner, $zeroedScores);
            $this->overrideScores($specificLoser, $zeroedScores);

            // Unspecific query: text is a genuine tie, so ONLY a business signal can rank this correctly
            // -- rate the one with a real, controlled metric signal as the winner.
            $this->overrideScores($unspecificWinner, array_merge($zeroedScores, [$metricA => 1.0]));
            $this->overrideScores($unspecificLoser, $zeroedScores);
            $this->refreshIndex();

            $idSpecificQuery = $this->insertSyntheticQuery($specificSearchTerm);
            $this->insertSyntheticRating($idSpecificQuery, $specificWinner, SearchRankingOptimizerConfig::RATING_TYPE_HEART);
            $this->insertSyntheticRating($idSpecificQuery, $specificLoser, SearchRankingOptimizerConfig::RATING_TYPE_X);

            $idUnspecificQuery = $this->insertSyntheticQuery($unspecificSearchTerm);
            $this->insertSyntheticRating($idUnspecificQuery, $unspecificWinner, SearchRankingOptimizerConfig::RATING_TYPE_HEART);
            $this->insertSyntheticRating($idUnspecificQuery, $unspecificLoser, SearchRankingOptimizerConfig::RATING_TYPE_X);

            $runTransfer = $this->runRealOptimizationWithSpecificityForcedEnabled();

            $this->deleteSyntheticQuery($idSpecificQuery);
            $this->deleteSyntheticQuery($idUnspecificQuery);

            $this->assertGreaterThan(
                0.0,
                $runTransfer->getBestSpecificityWeightShiftMagnitudeOrFail(),
                sprintf(
                    'specificityWeightShiftMagnitude should converge positive: only a positive shift (toward relevance for the specific query, toward business signal for the unspecific one) can satisfy both simultaneously with one shared relevanceWeight. Got %.6f.',
                    $runTransfer->getBestSpecificityWeightShiftMagnitudeOrFail(),
                ),
            );
        } finally {
            $this->overrideScores($specificWinner, $originalSpecificWinnerScores);
            $this->overrideScores($specificLoser, $originalSpecificLoserScores);
            $this->overrideScores($unspecificWinner, $originalUnspecificWinnerScores);
            $this->overrideScores($unspecificLoser, $originalUnspecificLoserScores);
            $this->refreshIndex();
        }
    }
}
