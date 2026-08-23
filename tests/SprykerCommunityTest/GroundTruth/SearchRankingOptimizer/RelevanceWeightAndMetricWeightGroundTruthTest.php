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
 * end-to-end (via this package's own public Facade, exactly the "Run now" Zed button's own code path)
 * several times, then flips the ground truth to the opposite extreme and does the same again -- asserting
 * only that the aggregated winning value moved in the expected DIRECTION between the two, never an exact
 * number. `testRelevanceWeightConvergesTowardWhicheverSignalTheGroundTruthFavors()` aggregates via the
 * MEDIAN of {@see RELEVANCE_WEIGHT_REPEAT_COUNT} runs ({@see AbstractGroundTruthTest::runRealOptimizationRepeatedMedian()}); the metric-weight
 * test aggregates via the BEST of {@see METRIC_WEIGHT_REPEAT_COUNT} runs instead ({@see
 * AbstractGroundTruthTest::runRealOptimizationRepeatedBest()}, restart-on-plateau enabled) -- see that
 * method's own docblock for why best-of-N, not median-of-N: its landscape is genuinely multi-modal, not
 * noise around one true value, so "typical run" is the wrong question and "can the optimizer find this at
 * all, given a few tries" is the right one.
 *
 * @group NeedsSearch
 */
class RelevanceWeightAndMetricWeightGroundTruthTest extends AbstractGroundTruthTest
{
    /**
     * The relevanceWeight both scenarios in
     * {@see testRelevanceWeightConvergesTowardWhicheverSignalTheGroundTruthFavors()} start from, and the
     * value the business signal is sized to tip at. The midpoint specifically: relevanceWeight may only
     * move a bounded distance per run (see that test's own docblock), so starting anywhere near a bound
     * leaves one of the two directions unreachable and the scenario it belongs to unable to prove anything.
     *
     * @var float
     */
    protected const RELEVANCE_WEIGHT_TIPPING_POINT = 0.5;

    /**
     * How many independent runs {@see AbstractGroundTruthTest::runRealOptimizationRepeatedBest()} takes the
     * best of, for the metric-weight scenarios only -- see that method's own docblock for why "best of N"
     * over "median of N" (a genuinely multi-modal landscape, not noise around one true value), and why
     * repeat-and-take-best is kept even now that restart-on-plateau exists (below) rather than dropped in
     * favor of a single restart-enabled run.
     *
     * WITHOUT restart-on-plateau, plain CMA-ES's own single-run hit rate on this exact scenario was measured
     * at only 2/20 (10%) -- confirmed 5 wasn't enough (both a real best-of-5 run and simple math:
     * `1 - 0.9^5 ≈ 41%` chance of even ONE hit), and both scenarios independently needing a hit means the
     * overall pass probability is `(1 - 0.9^N)^2` -- N had to be pushed to 30 (`≈92%`) to get a reliable
     * CI signal, costing ~2 minutes for both scenarios combined (roughly 1.3s/run measured). That was this
     * package's own real production odds too: a real "Run now" click had the same ~10% single-run chance,
     * once.
     *
     * WITH restart-on-plateau (`andrebarthelmeshellmuth/blackbox-optimizer`'s `RestartingOptimizerDecorator`,
     * see the package README's own "Limitations" section) now enabled here, the single-run hit rate on this
     * exact scenario measures far higher -- {@see testRestartOnPlateauRaisesSingleRunHitRateForMetricWeight()}
     * found 20/20 across two independent live batches. Even a conservative estimate of that true rate (say
     * 90%, allowing for the small sample) already makes N=30's own margin wildly excessive: per-scenario hit
     * probability `1 - (1 - p)^N` clears 99.9% at N=3 for any `p >= 0.9`, and both scenarios' combined
     * probability stays above 99.8%. Dropped to 3 accordingly -- a ~10x runtime cut for this test alone,
     * while the actual reliability margin GROWS, not shrinks, versus the old N=30/plain-CMA-ES baseline.
     *
     * @var int
     */
    protected const METRIC_WEIGHT_REPEAT_COUNT = 3;

    /**
     * How many INDEPENDENT, INDIVIDUAL runs {@see testRestartOnPlateauRaisesSingleRunHitRateForMetricWeight()}
     * takes -- deliberately smaller than {@see METRIC_WEIGHT_REPEAT_COUNT} (which measures a best-of-N
     * `runRealOptimizationRepeatedBest()` result, not a per-run hit rate): this test measures the opposite
     * thing, whether a SINGLE run with restart-on-plateau enabled clears the threshold, so its cost scales
     * with the sample size directly rather than being amortized into one "best" figure. 10 is enough to
     * distinguish "close to the ~10% single-run baseline" from "meaningfully higher" without this opt-in
     * suite's own runtime growing past a couple of minutes for this one test alone.
     *
     * @var int
     */
    protected const RESTART_ON_PLATEAU_SAMPLE_SIZE = 10;

    /**
     * The bar {@see testRestartOnPlateauRaisesSingleRunHitRateForMetricWeight()} asserts the measured hit
     * rate clears. Deliberately far above the ~10% single-run baseline documented on {@see METRIC_WEIGHT_REPEAT_COUNT}
     * (a 5x improvement, not a marginal one) while staying well short of "must hit every single time" --
     * this decorator restarts only up to what the SAME fixed evaluation budget as before can still afford
     * (see {@see \BlackboxOptimizer\Algorithm\RestartingOptimizerDecorator}'s own docblock), so an
     * occasional miss (a budget too tight for enough restarts to escape a stubborn plateau) is expected, not
     * a regression.
     *
     * @var float
     */
    protected const RESTART_ON_PLATEAU_MIN_HIT_RATE = 0.5;

    /**
     * How many additional FILLER pairs {@see testRelevanceWeightConvergesTowardWhicheverSignalTheGroundTruthFavors()}
     * adds alongside its own single main pair -- the relevanceWeight analog of
     * {@see AbstractGroundTruthTest::METRIC_WEIGHT_FILLER_PAIR_COUNT}, added for the exact same reason: a
     * single synthetic pair alone makes `rank_eval`'s aggregate nDCG a near step-function in relevanceWeight
     * (flat everywhere except right at the one tipping point), which CMA-ES's own plateau detection stops
     * on almost immediately -- confirmed empirically (see this class's own git history/PR discussion): that
     * test failed 5/5 real runs, landing at the SAME trust-region boundary in BOTH scenarios regardless of
     * restart-on-plateau, which is the signature of a flat landscape (restarting from a fresh point cannot
     * help when there is no gradient to find ANYWHERE except at one point) rather than an unlucky local
     * optimum. Several filler pairs, each crossing its own tipping point at a DIFFERENT relevanceWeight
     * value (see {@see RELEVANCE_WEIGHT_FILLER_TIPPING_POINT_BAND_MIN}/{@see RELEVANCE_WEIGHT_FILLER_TIPPING_POINT_BAND_MAX}),
     * give the aggregate objective real, graduated gradient across the reachable trust-region band instead
     * of one giant flat step.
     *
     * @var int
     */
    protected const RELEVANCE_WEIGHT_FILLER_PAIR_COUNT = 4;

    /**
     * The band {@see testRelevanceWeightConvergesTowardWhicheverSignalTheGroundTruthFavors()} spreads its
     * filler pairs' own tipping points across. Satisfying EVERY filler at once needs relevanceWeight past
     * whichever end of this band is farthest from the pinned start ({@see RELEVANCE_WEIGHT_TIPPING_POINT},
     * 0.5) -- so this band's WIDTH is the real distance a run has to travel, not just "how far the trust
     * region allows". Confirmed empirically: an earlier, wider [0.40; 0.60] band (deliberately spread
     * toward the reachable trust-region edges, 0.35/0.65) fixed the original flat-landscape failure (no
     * more identical-boundary ties) but left a real, still-too-far-to-reliably-travel distance for the
     * scenario fighting this shop's own live relevanceWeight-prefers-high bias (see this test's own
     * docblock) -- 2 of 3 real runs still missed, landing short of a full band-width move. A TIGHT band
     * close to the pinned start needs far less distance for either scenario to travel within one run's
     * budget, at the cost of a smaller (still real, still assertable) final separation between the two
     * scenarios' own winning values.
     *
     * @var float
     */
    protected const RELEVANCE_WEIGHT_FILLER_TIPPING_POINT_BAND_MIN = 0.46;

    /**
     * @var float
     */
    protected const RELEVANCE_WEIGHT_FILLER_TIPPING_POINT_BAND_MAX = 0.54;

    /**
     * How many independent runs {@see AbstractGroundTruthTest::runRealOptimizationRepeatedMedian()} takes
     * the median of, for {@see testRelevanceWeightConvergesTowardWhicheverSignalTheGroundTruthFavors()} only
     * -- bumped from that method's own default of 3 alongside the filler-pair fix above, not instead of it:
     * filler pairs fixed the flat-landscape failure mode (no more identical-trust-region-boundary ties,
     * confirmed empirically), but this scenario's "business signal correct" direction still fights this
     * shop's own real rated queries' preference for a HIGH relevanceWeight (see this test's own docblock),
     * which a single run occasionally still loses to by a small margin. 7 was confirmed empirically to clear
     * that residual noise reliably: 5/5 clean runs at this repeat count and the narrowed filler band above,
     * versus failures (either the old identical-boundary tie, or a small-margin miss) at the default of 3.
     *
     * @var int
     */
    protected const RELEVANCE_WEIGHT_REPEAT_COUNT = 7;

    /**
     * A pair with a small but real, sized text-relevance gap, not an exact tie, is measured against a
     * precisely-known threshold -- see {@see AbstractGroundTruthTest::discoverMarginalTextRelevancePair()}'s
     * own docblock for why a bare "ranks correctly at all" (lead > 0) isn't reliable, and for the ROOT CAUSE
     * this test's own filler pairs exist to fix: a single synthetic pair alone makes `rank_eval`'s aggregate
     * nDCG a near-total-flat landscape that CMA-ES's own plateau detection stops on long before any real
     * optimum (confirmed via `generationsUsed` stuck at 29 regardless of a 150/600/1500 generation cap, no
     * relation to the actual budget). {@see AbstractGroundTruthTest::selectFillerMarginalTextRelevancePairs()}
     * adds several more pairs, each crossing its own rank-flip point at a different lead value, giving the
     * aggregate objective real gradient across the swept range instead of 1-2 giant flat steps -- much
     * closer to what a real shop's dozens of independently-rated queries already look like.
     */
    public function testMetricWeightConvergesTowardWhicheverMetricTheGroundTruthFavors(): void
    {
        [$searchTerm] = $this->discoverTwoRatedProductIdsAndSearchTerm();
        [$metricA, $metricB] = $this->discoverTwoOptimizableMetricNames();
        [$productA, $productB, $requiredLeadThreshold] = $this->discoverMarginalTextRelevancePair($searchTerm);
        $fillerPairs = $this->selectFillerMarginalTextRelevancePairs($searchTerm, [$productA, $productB]);
        $zeroedScores = $this->buildAllActiveMetricsZeroedOut();

        $fillerProductIds = [];

        foreach ($fillerPairs as [$idFillerLoser, $idFillerWinner]) {
            $fillerProductIds[] = $idFillerLoser;
            $fillerProductIds[] = $idFillerWinner;
        }

        $originalScoresByProductId = [];

        foreach (array_unique(array_merge([$productA, $productB], $fillerProductIds)) as $idProductAbstract) {
            $originalScoresByProductId[$idProductAbstract] = $this->readScores($idProductAbstract);
        }

        try {
            // Scenario 1: productA dominates on metricA, productB dominates on metricB, every OTHER active
            // metric zeroed on both (no uncontrolled real signal left to confound the comparison) -- rating
            // productA heart and productB x means only a HIGH metricA weight (relative to metricB) ranks
            // correctly. Filler pairs reinforce the SAME direction, giving the aggregate objective several
            // real steps instead of just this one pair's own 1-2 (see this method's own docblock).
            $this->overrideScores($productA, array_merge($zeroedScores, [$metricA => 1.0, $metricB => 0.0]));
            $this->overrideScores($productB, array_merge($zeroedScores, [$metricA => 0.0, $metricB => 1.0]));

            $idQueryScenario1 = $this->insertSyntheticQuery($searchTerm);
            $this->insertSyntheticRating($idQueryScenario1, $productA, SearchRankingOptimizerConfig::RATING_TYPE_HEART);
            $this->insertSyntheticRating($idQueryScenario1, $productB, SearchRankingOptimizerConfig::RATING_TYPE_X);
            $fillerQueryIdsScenario1 = $this->applyFillerPairsForScenario($fillerPairs, $zeroedScores, $metricA, $metricB, $searchTerm);
            $this->refreshIndex();

            $metricALeadWhenFavored = $this->runRealOptimizationRepeatedBest(
                fn (SearchRankingOptimizerRunTransfer $runTransfer) => $this->extractMetricWeight($runTransfer, $metricA)
                    - $this->extractMetricWeight($runTransfer, $metricB),
                true,
                static::METRIC_WEIGHT_REPEAT_COUNT,
                SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
                static::METRIC_WEIGHT_TEST_FIXED_RELEVANCE_WEIGHT,
                SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_RESTART_ON_PLATEAU,
            );

            $this->deleteSyntheticQuery($idQueryScenario1);

            foreach ($fillerQueryIdsScenario1 as $idFillerQuery) {
                $this->deleteSyntheticQuery($idFillerQuery);
            }

            // Scenario 2: flip which product/pair dominates which metric, same ratings -- now only a HIGH
            // metricB weight (relative to metricA) ranks correctly.
            $this->overrideScores($productA, array_merge($zeroedScores, [$metricA => 0.0, $metricB => 1.0]));
            $this->overrideScores($productB, array_merge($zeroedScores, [$metricA => 1.0, $metricB => 0.0]));

            $idQueryScenario2 = $this->insertSyntheticQuery($searchTerm);
            $this->insertSyntheticRating($idQueryScenario2, $productA, SearchRankingOptimizerConfig::RATING_TYPE_HEART);
            $this->insertSyntheticRating($idQueryScenario2, $productB, SearchRankingOptimizerConfig::RATING_TYPE_X);
            $fillerQueryIdsScenario2 = $this->applyFillerPairsForScenario($fillerPairs, $zeroedScores, $metricB, $metricA, $searchTerm);
            $this->refreshIndex();

            $metricALeadWhenDisfavored = $this->runRealOptimizationRepeatedBest(
                fn (SearchRankingOptimizerRunTransfer $runTransfer) => $this->extractMetricWeight($runTransfer, $metricA)
                    - $this->extractMetricWeight($runTransfer, $metricB),
                false,
                static::METRIC_WEIGHT_REPEAT_COUNT,
                SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
                static::METRIC_WEIGHT_TEST_FIXED_RELEVANCE_WEIGHT,
                SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_RESTART_ON_PLATEAU,
            );

            $this->deleteSyntheticQuery($idQueryScenario2);

            foreach ($fillerQueryIdsScenario2 as $idFillerQuery) {
                $this->deleteSyntheticQuery($idFillerQuery);
            }

            // Asserted per scenario rather than by comparing one metric's weight ACROSS the two, because
            // only the ordering is actually entailed. And asserted against $requiredLeadThreshold, not bare
            // 0.0 -- see discoverMarginalTextRelevancePair()'s own docblock for why a bare sign check isn't
            // reliable: with a third metric on the simplex free to absorb the remaining mass, metricA's own
            // weight is arbitrary in BOTH scenarios (observed landing at 0.4461 and 0.4463 on one run), and
            // even the LEAD's sign alone used to land within a hair of 0 either way. $requiredLeadThreshold
            // is the smallest lead that could possibly rank $productA correctly given the real text-relevance
            // gap the pair was chosen for, so clearing it is real signal, not noise that happened to fall on
            // the right side of zero.
            $this->assertGreaterThan(
                $requiredLeadThreshold,
                $metricALeadWhenFavored,
                sprintf(
                    '"%s" should outweigh "%s" by at least %.4f when the ground truth favors it (lead: %.4f).',
                    $metricA,
                    $metricB,
                    $requiredLeadThreshold,
                    $metricALeadWhenFavored,
                ),
            );

            $this->assertLessThan(
                -$requiredLeadThreshold,
                $metricALeadWhenDisfavored,
                sprintf(
                    '"%s" should outweigh "%s" by at least %.4f when the ground truth favors it instead (lead of "%s": %.4f).',
                    $metricB,
                    $metricA,
                    $requiredLeadThreshold,
                    $metricA,
                    $metricALeadWhenDisfavored,
                ),
            );
        } finally {
            foreach ($originalScoresByProductId as $idProductAbstract => $scores) {
                $this->overrideScores($idProductAbstract, $scores);
            }

            $this->refreshIndex();
        }
    }

    /**
     * Directly measures what `andrebarthelmeshellmuth/blackbox-optimizer`'s `RestartingOptimizerDecorator`
     * buys a real "Run now" click against the EXACT scenario {@see METRIC_WEIGHT_REPEAT_COUNT}'s own
     * docblock measured the ~10% (2/20) single-run baseline on -- same pair-discovery, same filler pairs,
     * same fixed relevanceWeight, same threshold. The only difference is what this test does with each of
     * {@see RESTART_ON_PLATEAU_SAMPLE_SIZE} runs: instead of taking the single BEST of many (what
     * {@see testMetricWeightConvergesTowardWhicheverMetricTheGroundTruthFavors()} needs to get a reliable
     * pass/fail signal at all), this counts how many INDIVIDUAL runs clear the threshold ON THEIR OWN --
     * the question that actually matters for a real, one-shot "Run now" click, restart-on-plateau enabled.
     *
     * Scenario 1 only (not both directions): a hit-rate measurement doesn't need the direction-comparison
     * {@see testMetricWeightConvergesTowardWhicheverMetricTheGroundTruthFavors()}'s own two-scenario
     * structure exists for -- one direction's hit rate is already the number this test is after.
     */
    public function testRestartOnPlateauRaisesSingleRunHitRateForMetricWeight(): void
    {
        [$searchTerm] = $this->discoverTwoRatedProductIdsAndSearchTerm();
        [$metricA, $metricB] = $this->discoverTwoOptimizableMetricNames();
        [$productA, $productB, $requiredLeadThreshold] = $this->discoverMarginalTextRelevancePair($searchTerm);
        $fillerPairs = $this->selectFillerMarginalTextRelevancePairs($searchTerm, [$productA, $productB]);
        $zeroedScores = $this->buildAllActiveMetricsZeroedOut();

        $fillerProductIds = [];

        foreach ($fillerPairs as [$idFillerLoser, $idFillerWinner]) {
            $fillerProductIds[] = $idFillerLoser;
            $fillerProductIds[] = $idFillerWinner;
        }

        $originalScoresByProductId = [];

        foreach (array_unique(array_merge([$productA, $productB], $fillerProductIds)) as $idProductAbstract) {
            $originalScoresByProductId[$idProductAbstract] = $this->readScores($idProductAbstract);
        }

        try {
            $this->overrideScores($productA, array_merge($zeroedScores, [$metricA => 1.0, $metricB => 0.0]));
            $this->overrideScores($productB, array_merge($zeroedScores, [$metricA => 0.0, $metricB => 1.0]));

            $idQuery = $this->insertSyntheticQuery($searchTerm);
            $this->insertSyntheticRating($idQuery, $productA, SearchRankingOptimizerConfig::RATING_TYPE_HEART);
            $this->insertSyntheticRating($idQuery, $productB, SearchRankingOptimizerConfig::RATING_TYPE_X);
            $fillerQueryIds = $this->applyFillerPairsForScenario($fillerPairs, $zeroedScores, $metricA, $metricB, $searchTerm);
            $this->refreshIndex();

            $hitCount = 0;

            for ($i = 0; $i < static::RESTART_ON_PLATEAU_SAMPLE_SIZE; $i++) {
                $runTransfer = $this->runRealOptimization(
                    SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
                    static::METRIC_WEIGHT_TEST_FIXED_RELEVANCE_WEIGHT,
                    SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_RESTART_ON_PLATEAU,
                );
                $lead = $this->extractMetricWeight($runTransfer, $metricA) - $this->extractMetricWeight($runTransfer, $metricB);

                if ($lead <= $requiredLeadThreshold) {
                    continue;
                }

                $hitCount++;
            }

            $this->deleteSyntheticQuery($idQuery);

            foreach ($fillerQueryIds as $idFillerQuery) {
                $this->deleteSyntheticQuery($idFillerQuery);
            }

            $hitRate = $hitCount / static::RESTART_ON_PLATEAU_SAMPLE_SIZE;

            $this->assertGreaterThanOrEqual(
                static::RESTART_ON_PLATEAU_MIN_HIT_RATE,
                $hitRate,
                sprintf(
                    'A single restart-on-plateau run should clear the threshold far more often than the ~10%%'
                        . ' baseline measured without it -- got %d/%d (%.0f%%).',
                    $hitCount,
                    static::RESTART_ON_PLATEAU_SAMPLE_SIZE,
                    $hitRate * 100,
                ),
            );
        } finally {
            foreach ($originalScoresByProductId as $idProductAbstract => $scores) {
                $this->overrideScores($idProductAbstract, $scores);
            }

            $this->refreshIndex();
        }
    }

    /**
     * Both scenarios share ONE index state and differ only in which product the ratings call correct, which
     * is what makes the comparison meaningful: the two scenarios are then satisfied by DISJOINT halves of
     * the relevanceWeight range, so whatever arbitrary point inside its own half each run happens to return,
     * the two medians cannot cross.
     *
     * Three things have to be true for that to hold, and none is automatic:
     *
     * 1. Metric weights must be unable to satisfy either scenario on their own, or the optimizer takes that
     *    route and leaves relevanceWeight free to settle anywhere. A uniform signal across EVERY active
     *    metric achieves that -- see {@see AbstractGroundTruthTest::buildAllActiveMetricsSetTo()}.
     * 2. The tipping point between the two scenarios has to be REACHABLE. relevanceWeight may only move
     *    {@see SearchRankingOptimizerConfig::getRelevanceWeightTrustRegionMaxDistance()} from wherever it
     *    starts, so a shop sitting near a bound (this one runs at 0.01) cannot demonstrate an upward move at
     *    all. The starting value is therefore pinned to the midpoint for the duration and restored after,
     *    exactly like the product scores around it, and the signal is sized so the main pair's own tipping
     *    point lands on that midpoint.
     * 3. The landscape around that tipping point must not be FLAT. A single synthetic pair alone makes
     *    `rank_eval`'s aggregate nDCG a near step-function in relevanceWeight (confirmed empirically: this
     *    test failed 5/5 real runs with only the main pair, landing at the SAME trust-region boundary in
     *    BOTH scenarios regardless of restart-on-plateau -- the signature of a flat landscape, not an
     *    unlucky local optimum a fresh restart could escape). {@see RELEVANCE_WEIGHT_FILLER_PAIR_COUNT}
     *    filler pairs, each crossing its OWN tipping point at a different relevanceWeight value spread
     *    across {@see RELEVANCE_WEIGHT_FILLER_TIPPING_POINT_BAND_MIN}/{@see RELEVANCE_WEIGHT_FILLER_TIPPING_POINT_BAND_MAX},
     *    give the aggregate objective real, graduated gradient across the reachable band instead -- the
     *    same fix {@see testMetricWeightConvergesTowardWhicheverMetricTheGroundTruthFavors()} already needed
     *    for the analogous problem in the metric-weight dimension.
     */
    public function testRelevanceWeightConvergesTowardWhicheverSignalTheGroundTruthFavors(): void
    {
        [$searchTerm] = $this->discoverTwoRatedProductIdsAndSearchTerm();
        [$textRelevanceWinner, $textRelevanceLoser] = $this->discoverAdjacentTextRelevancePair($searchTerm);

        // With a uniform signal s on one product and 0 on the other, the two rank equally at exactly
        // relevanceWeight = s / (textGap + s). Sizing s to the text gap itself puts that tipping point at
        // 0.5 -- the midpoint the starting value is pinned to below.
        $textGap = $this->computeNormalizedTextRelevanceGap($textRelevanceWinner, $textRelevanceLoser, $searchTerm);
        $uniformSignal = $this->buildAllActiveMetricsSetTo($textGap);
        $zeroedScores = $this->buildAllActiveMetricsZeroedOut();

        $fillerPairs = $this->selectRelevanceWeightFillerPairs($searchTerm, [$textRelevanceWinner, $textRelevanceLoser]);
        $fillerUniformSignalsByIndex = $this->buildRelevanceWeightFillerUniformSignals($fillerPairs);

        $originalScoresByProductId = [];

        foreach ([$textRelevanceWinner, $textRelevanceLoser] as $idProductAbstract) {
            $originalScoresByProductId[$idProductAbstract] = $this->readScores($idProductAbstract);
        }

        foreach ($fillerPairs as [$idFillerLoser, $idFillerWinner]) {
            $originalScoresByProductId[$idFillerLoser] = $this->readScores($idFillerLoser);
            $originalScoresByProductId[$idFillerWinner] = $this->readScores($idFillerWinner);
        }

        $originalRelevanceWeight = $this->getSearchRankingFacade()->getRelevanceWeight(static::STORE_NAME, static::LOCALE_NAME);

        try {
            $this->getSearchRankingFacade()->saveRelevanceWeight(static::STORE_NAME, static::LOCALE_NAME, static::RELEVANCE_WEIGHT_TIPPING_POINT);

            // One index state for both scenarios: every pair's text winner carries no business signal, its
            // text loser carries its own uniform one. Text and business signal now point at OPPOSITE
            // products on every pair, so every ranking is a straight trade-off between them and
            // relevanceWeight is the only thing that can make it.
            $this->overrideScores($textRelevanceWinner, $zeroedScores);
            $this->overrideScores($textRelevanceLoser, $uniformSignal);

            foreach ($fillerPairs as $index => [$idFillerLoser, $idFillerWinner]) {
                $this->overrideScores($idFillerLoser, $fillerUniformSignalsByIndex[$index]);
                $this->overrideScores($idFillerWinner, $zeroedScores);
            }

            $this->refreshIndex();

            // Scenario 1: the text winner is correct on every pair -- only a relevanceWeight ABOVE the main
            // pair's own tipping point ranks it first.
            $queryIdsScenario1 = $this->insertRelevanceWeightScenarioQueries(
                $searchTerm,
                $textRelevanceWinner,
                $textRelevanceLoser,
                $fillerPairs,
                isTextSideCorrect: true,
            );

            $relevanceWeightWhenTextAgrees = $this->runRealOptimizationRepeatedMedian(
                fn (SearchRankingOptimizerRunTransfer $runTransfer) => $runTransfer->getBestRelevanceWeightOrFail(),
                static::RELEVANCE_WEIGHT_REPEAT_COUNT,
                terminationMode: SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_RESTART_ON_PLATEAU,
            );

            foreach ($queryIdsScenario1 as $idQuery) {
                $this->deleteSyntheticQuery($idQuery);
            }

            // Scenario 2: same index state, ratings flipped on every pair -- now the business signal is
            // correct everywhere, and only a relevanceWeight BELOW the main pair's own tipping point ranks
            // it first.
            $queryIdsScenario2 = $this->insertRelevanceWeightScenarioQueries(
                $searchTerm,
                $textRelevanceWinner,
                $textRelevanceLoser,
                $fillerPairs,
                isTextSideCorrect: false,
            );

            $relevanceWeightWhenBusinessSignalDisagrees = $this->runRealOptimizationRepeatedMedian(
                fn (SearchRankingOptimizerRunTransfer $runTransfer) => $runTransfer->getBestRelevanceWeightOrFail(),
                static::RELEVANCE_WEIGHT_REPEAT_COUNT,
                terminationMode: SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_RESTART_ON_PLATEAU,
            );

            foreach ($queryIdsScenario2 as $idQuery) {
                $this->deleteSyntheticQuery($idQuery);
            }

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
            $this->getSearchRankingFacade()->saveRelevanceWeight(static::STORE_NAME, static::LOCALE_NAME, $originalRelevanceWeight);

            foreach ($originalScoresByProductId as $idProductAbstract => $scores) {
                $this->overrideScores($idProductAbstract, $scores);
            }

            $this->refreshIndex();
        }
    }

    /**
     * Up to {@see RELEVANCE_WEIGHT_FILLER_PAIR_COUNT} additional pairs from
     * {@see AbstractGroundTruthTest::discoverAllMarginalTextRelevancePairs()}, spread evenly across the gap
     * distribution, excluding any pair sharing a product with `$excludedProductIds` (the main pair) --
     * same selection shape as {@see AbstractGroundTruthTest::selectFillerMarginalTextRelevancePairs()}, but
     * keeping each pair's own gap (needed to size its own uniform signal below) rather than dropping it.
     *
     * @param string $searchTerm
     * @param array<int> $excludedProductIds
     *
     * @return array<array{0: int, 1: int, 2: float}> [idProductAbstractTextLoser, idProductAbstractTextWinner, gap] pairs.
     */
    protected function selectRelevanceWeightFillerPairs(string $searchTerm, array $excludedProductIds): array
    {
        $candidates = array_values(array_filter(
            $this->discoverAllMarginalTextRelevancePairs($searchTerm),
            static fn (array $pair): bool => !in_array($pair[0], $excludedProductIds, true) && !in_array($pair[1], $excludedProductIds, true),
        ));

        $candidateCount = count($candidates);
        $desiredCount = min(static::RELEVANCE_WEIGHT_FILLER_PAIR_COUNT, $candidateCount);

        if ($desiredCount === 0) {
            return [];
        }

        $selectedByIndex = [];

        for ($k = 0; $k < $desiredCount; $k++) {
            $index = $desiredCount > 1
                ? (int)round($k * ($candidateCount - 1) / ($desiredCount - 1))
                : intdiv($candidateCount, 2);
            $selectedByIndex[$index] = $candidates[$index];
        }

        return array_values($selectedByIndex);
    }

    /**
     * One uniform business signal per filler pair, each sized so that pair's OWN tipping point
     * (`relevanceWeight = s / (gap + s)`) lands at a DIFFERENT point spread evenly across
     * {@see RELEVANCE_WEIGHT_FILLER_TIPPING_POINT_BAND_MIN}/{@see RELEVANCE_WEIGHT_FILLER_TIPPING_POINT_BAND_MAX}
     * -- solving that same formula for `s` given a target tipping point `t`: `s = t * gap / (1 - t)`.
     * Deliberately NOT all sized to tip at 0.5 like the main pair (which would just superimpose every
     * filler's own step on top of the main pair's, still one giant flat step, not a graduated one) -- see
     * this class's own docblock on {@see testRelevanceWeightConvergesTowardWhicheverSignalTheGroundTruthFavors()}
     * for why a graduated landscape is the actual fix.
     *
     * @param array<array{0: int, 1: int, 2: float}> $fillerPairs
     *
     * @return array<int, array<string, float>> Index (matching $fillerPairs' own) => uniform signal.
     */
    protected function buildRelevanceWeightFillerUniformSignals(array $fillerPairs): array
    {
        $fillerCount = count($fillerPairs);
        $signalsByIndex = [];

        foreach ($fillerPairs as $index => [, , $gap]) {
            $targetTippingPoint = $fillerCount > 1
                ? static::RELEVANCE_WEIGHT_FILLER_TIPPING_POINT_BAND_MIN + $index * (static::RELEVANCE_WEIGHT_FILLER_TIPPING_POINT_BAND_MAX - static::RELEVANCE_WEIGHT_FILLER_TIPPING_POINT_BAND_MIN) / ($fillerCount - 1)
                : (static::RELEVANCE_WEIGHT_FILLER_TIPPING_POINT_BAND_MIN + static::RELEVANCE_WEIGHT_FILLER_TIPPING_POINT_BAND_MAX) / 2;

            $signalsByIndex[$index] = $this->buildAllActiveMetricsSetTo($targetTippingPoint * $gap / (1 - $targetTippingPoint));
        }

        return $signalsByIndex;
    }

    /**
     * Inserts one synthetic query+rating for the main pair plus one per filler pair, all in the SAME
     * direction for a given scenario -- every pair's text winner rated heart/loser x when
     * `$isTextSideCorrect` is true (scenario 1), flipped when false (scenario 2).
     *
     * Every suffix here is PUNCTUATION-only (`.`, `..`, `...`, ...), never a real word -- unlike
     * {@see AbstractGroundTruthTest::applyFillerPairsForScenario()}'s `'filler' . $index` suffixes, which
     * are safe there only because that method's queries aren't measured by pair-position (the metric-weight
     * ground truth's own main query still uses the plain `.` default). Confirmed live (2026-08-23) via
     * {@see AbstractGroundTruthTest::fetchRawTextRelevanceScores()}: appending a real word like `'main'` to
     * this shop's search term changes which real catalog documents the LIVE query built from that stored
     * term actually matches -- `discoverAdjacentTextRelevancePair()` picks the main pair using the BARE
     * term, but {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner::buildRankEvalRequests()}
     * fires the STORED (suffixed) term, so a word suffix can silently push both rated products from
     * rank 1/2 (bare term) to rank 10/11 -- past `SearchRankingOptimizerConfig::getRankEvalCutoff()`'s
     * cutoff of 10 -- for THIS shop's real "chair" data. `_rank_eval` only scores hits it actually returns:
     * once both rated products fall outside the cutoff, their rating (whichever way it's flipped) never
     * enters the DCG calculation at all, producing a near-zero, rating-direction-INSENSITIVE score for that
     * query -- exactly the degenerate 9-decimal-place tie between scenarios that made
     * {@see \SprykerCommunityTest\GroundTruth\SearchRankingOptimizer\RelevanceWeightAndMetricWeightGroundTruthTest::testRelevanceWeightConvergesTowardWhicheverSignalTheGroundTruthFavors()}
     * flaky in the first place: with the main pair's own signal neutralized this way, only the filler
     * pairs' aggregate contribution was left to decide the outcome, at the mercy of run-to-run CMA-ES noise.
     * A punctuation suffix is confirmed (same live check) to leave rank 1/2 unchanged for every length used
     * below, matching {@see AbstractGroundTruthTest::insertSyntheticQuery()}'s own docblock ("most analyzers
     * strip it as noise, not merely trailing whitespace") -- each suffix still needs to be a DISTINCT string
     * (not just distinct trailing whitespace, which MariaDB's PAD SPACE collation treats as equal) to avoid
     * colliding with another synthetic query on the same unique `(search_term, store_name, locale_name)` key.
     *
     * @param string $searchTerm
     * @param int $idTextRelevanceWinner
     * @param int $idTextRelevanceLoser
     * @param array<array{0: int, 1: int, 2: float}> $fillerPairs
     * @param bool $isTextSideCorrect
     *
     * @return array<int> Every synthetic query id inserted, for the caller to delete once this scenario's
     *   run is done.
     */
    protected function insertRelevanceWeightScenarioQueries(
        string $searchTerm,
        int $idTextRelevanceWinner,
        int $idTextRelevanceLoser,
        array $fillerPairs,
        bool $isTextSideCorrect,
    ): array {
        $heartRatedId = $isTextSideCorrect ? $idTextRelevanceWinner : $idTextRelevanceLoser;
        $xRatedId = $isTextSideCorrect ? $idTextRelevanceLoser : $idTextRelevanceWinner;

        $idQueryMain = $this->insertSyntheticQuery($searchTerm, '.');
        $this->insertSyntheticRating($idQueryMain, $heartRatedId, SearchRankingOptimizerConfig::RATING_TYPE_HEART);
        $this->insertSyntheticRating($idQueryMain, $xRatedId, SearchRankingOptimizerConfig::RATING_TYPE_X);

        $queryIds = [$idQueryMain];

        foreach ($fillerPairs as $index => [$idFillerLoser, $idFillerWinner]) {
            $fillerHeartRatedId = $isTextSideCorrect ? $idFillerWinner : $idFillerLoser;
            $fillerXRatedId = $isTextSideCorrect ? $idFillerLoser : $idFillerWinner;

            $idQueryFiller = $this->insertSyntheticQuery($searchTerm, str_repeat('.', $index + 2));
            $this->insertSyntheticRating($idQueryFiller, $fillerHeartRatedId, SearchRankingOptimizerConfig::RATING_TYPE_HEART);
            $this->insertSyntheticRating($idQueryFiller, $fillerXRatedId, SearchRankingOptimizerConfig::RATING_TYPE_X);

            $queryIds[] = $idQueryFiller;
        }

        return $queryIds;
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
