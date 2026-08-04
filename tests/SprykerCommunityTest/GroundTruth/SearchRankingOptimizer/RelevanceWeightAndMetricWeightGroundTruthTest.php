<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\GroundTruth\SearchRankingOptimizer;

use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

/**
 * Opt-in, not part of the default test run -- see {@see AbstractGroundTruthTest}'s own docblock for why and
 * how. Each test skews a real, live ground truth toward one extreme, runs the REAL automated optimizer
 * end-to-end (via this package's own public Facade, exactly the "Run now" Zed button's own code path) 3
 * times and takes the median (see {@see AbstractGroundTruthTest::runRealOptimizationRepeatedMedian()} for
 * why a single run isn't reliable enough on its own), then flips the ground truth to the opposite extreme
 * and does the same again -- asserting only that the median winning value moved in the expected DIRECTION
 * between the two, never an exact number.
 */
class RelevanceWeightAndMetricWeightGroundTruthTest extends AbstractGroundTruthTest
{
    public function testMetricWeightConvergesTowardWhicheverMetricTheGroundTruthFavors(): void
    {
        [$searchTerm] = $this->discoverTwoRatedProductIdsAndSearchTerm();
        [$metricA, $metricB] = $this->discoverTwoOptimizableMetricNames();
        // Deliberately a score-TIED pair, not an arbitrary rated pair or even just an adjacent-ranked one --
        // see discoverTiedTextRelevancePair()'s own docblock: anything less than an exact tie leaves
        // relevanceWeight (also free in this real end-to-end run) a lever to satisfy the ground truth on
        // its own, letting the metric-weight comparison this test means to isolate settle arbitrarily --
        // confirmed empirically (an adjacent-but-not-tied pair produced a reversed result twice in a row).
        [$productA, $productB] = $this->discoverTiedTextRelevancePair($searchTerm);
        $zeroedScores = $this->buildAllActiveMetricsZeroedOut();

        $originalScoresA = $this->readScores($productA);
        $originalScoresB = $this->readScores($productB);

        try {
            // Scenario 1: productA dominates on metricA, productB dominates on metricB, every OTHER active
            // metric zeroed on both (no uncontrolled real signal left to confound the comparison) -- rating
            // productA heart and productB x means only a HIGH metricA weight (relative to metricB) ranks
            // correctly.
            $this->overrideScores($productA, array_merge($zeroedScores, [$metricA => 1.0, $metricB => 0.0]));
            $this->overrideScores($productB, array_merge($zeroedScores, [$metricA => 0.0, $metricB => 1.0]));
            $this->refreshIndex();

            $idQueryScenario1 = $this->insertSyntheticQuery($searchTerm);
            $this->insertSyntheticRating($idQueryScenario1, $productA, SearchRankingOptimizerConfig::RATING_TYPE_HEART);
            $this->insertSyntheticRating($idQueryScenario1, $productB, SearchRankingOptimizerConfig::RATING_TYPE_X);

            $weightOfMetricAWhenFavored = $this->runRealOptimizationRepeatedMedian(
                fn (SearchRankingOptimizerRunTransfer $runTransfer) => $this->extractMetricWeight($runTransfer, $metricA),
            );

            $this->deleteSyntheticQuery($idQueryScenario1);

            // Scenario 2: flip which product dominates which metric, same ratings -- now only a HIGH
            // metricB weight (relative to metricA) ranks correctly.
            $this->overrideScores($productA, array_merge($zeroedScores, [$metricA => 0.0, $metricB => 1.0]));
            $this->overrideScores($productB, array_merge($zeroedScores, [$metricA => 1.0, $metricB => 0.0]));
            $this->refreshIndex();

            $idQueryScenario2 = $this->insertSyntheticQuery($searchTerm);
            $this->insertSyntheticRating($idQueryScenario2, $productA, SearchRankingOptimizerConfig::RATING_TYPE_HEART);
            $this->insertSyntheticRating($idQueryScenario2, $productB, SearchRankingOptimizerConfig::RATING_TYPE_X);

            $weightOfMetricAWhenDisfavored = $this->runRealOptimizationRepeatedMedian(
                fn (SearchRankingOptimizerRunTransfer $runTransfer) => $this->extractMetricWeight($runTransfer, $metricA),
            );

            $this->deleteSyntheticQuery($idQueryScenario2);

            $this->assertGreaterThan(
                $weightOfMetricAWhenDisfavored,
                $weightOfMetricAWhenFavored,
                sprintf(
                    '"%s"\'s winning weight should be higher when the ground truth favors it (%.4f) than when it favors "%s" instead (%.4f).',
                    $metricA,
                    $weightOfMetricAWhenFavored,
                    $metricB,
                    $weightOfMetricAWhenDisfavored,
                ),
            );
        } finally {
            $this->overrideScores($productA, $originalScoresA);
            $this->overrideScores($productB, $originalScoresB);
            $this->refreshIndex();
        }
    }

    public function testRelevanceWeightConvergesTowardWhicheverSignalTheGroundTruthFavors(): void
    {
        [$searchTerm] = $this->discoverTwoRatedProductIdsAndSearchTerm();
        [$metricA] = $this->discoverTwoOptimizableMetricNames();
        [$textRelevanceWinner, $textRelevanceLoser] = $this->discoverAdjacentTextRelevancePair($searchTerm);
        $zeroedScores = $this->buildAllActiveMetricsZeroedOut();

        $originalScoresWinner = $this->readScores($textRelevanceWinner);
        $originalScoresLoser = $this->readScores($textRelevanceLoser);

        try {
            // Scenario 1: rate strictly by TEXT order, and make the ENTIRE business signal completely
            // uninformative (every active metric zeroed on both) -- only a HIGH relevanceWeight can rank
            // this correctly.
            $this->overrideScores($textRelevanceWinner, $zeroedScores);
            $this->overrideScores($textRelevanceLoser, $zeroedScores);
            $this->refreshIndex();

            $idQueryScenario1 = $this->insertSyntheticQuery($searchTerm);
            $this->insertSyntheticRating($idQueryScenario1, $textRelevanceWinner, SearchRankingOptimizerConfig::RATING_TYPE_HEART);
            $this->insertSyntheticRating($idQueryScenario1, $textRelevanceLoser, SearchRankingOptimizerConfig::RATING_TYPE_X);

            $relevanceWeightWhenTextAgrees = $this->runRealOptimizationRepeatedMedian(
                fn (SearchRankingOptimizerRunTransfer $runTransfer) => $runTransfer->getBestRelevanceWeightOrFail(),
            );

            $this->deleteSyntheticQuery($idQueryScenario1);

            // Scenario 2: rate AGAINST text order (the weaker text match is now "correct"), and give ONLY
            // that weaker match a strong signal on ONE metric (every other metric, on both products, still
            // zeroed) -- only a LOW relevanceWeight can rank this correctly, since a high one would keep
            // promoting the (now wrongly-rated) text-relevance winner.
            $this->overrideScores($textRelevanceWinner, $zeroedScores);
            $this->overrideScores($textRelevanceLoser, array_merge($zeroedScores, [$metricA => 1.0]));
            $this->refreshIndex();

            $idQueryScenario2 = $this->insertSyntheticQuery($searchTerm);
            $this->insertSyntheticRating($idQueryScenario2, $textRelevanceLoser, SearchRankingOptimizerConfig::RATING_TYPE_HEART);
            $this->insertSyntheticRating($idQueryScenario2, $textRelevanceWinner, SearchRankingOptimizerConfig::RATING_TYPE_X);

            $relevanceWeightWhenBusinessSignalDisagrees = $this->runRealOptimizationRepeatedMedian(
                fn (SearchRankingOptimizerRunTransfer $runTransfer) => $runTransfer->getBestRelevanceWeightOrFail(),
            );

            $this->deleteSyntheticQuery($idQueryScenario2);

            $this->assertGreaterThan(
                $relevanceWeightWhenBusinessSignalDisagrees,
                $relevanceWeightWhenTextAgrees,
                sprintf(
                    'relevanceWeight should be higher when text relevance alone is correct (%.4f) than when only the business signal is correct (%.4f).',
                    $relevanceWeightWhenTextAgrees,
                    $relevanceWeightWhenBusinessSignalDisagrees,
                ),
            );
        } finally {
            $this->overrideScores($textRelevanceWinner, $originalScoresWinner);
            $this->overrideScores($textRelevanceLoser, $originalScoresLoser);
            $this->refreshIndex();
        }
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer $runTransfer
     * @param string $metricName
     */
    protected function extractMetricWeight(SearchRankingOptimizerRunTransfer $runTransfer, string $metricName): float
    {
        foreach ($runTransfer->getBestMetricWeights() as $metricWeightTransfer) {
            if ($metricWeightTransfer->getName() === $metricName) {
                return $metricWeightTransfer->getWeightOrFail();
            }
        }

        $this->fail(sprintf('Metric "%s" is missing from the winning candidate\'s metric weights.', $metricName));
    }
}
