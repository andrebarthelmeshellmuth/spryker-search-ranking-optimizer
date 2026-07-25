<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration;

use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;

class StatisticsCalculator implements StatisticsCalculatorInterface
{
    /**
     * {@inheritDoc}
     *
     * @param array<float> $scores
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer
     */
    public function calculate(array $scores): SearchRankingCalibrationTransfer
    {
        sort($scores);
        $count = count($scores);
        $mean = array_sum($scores) / $count;

        return (new SearchRankingCalibrationTransfer())
            ->setSampleCount($count)
            ->setComputedK($mean)
            ->setScoreMean($mean)
            ->setScoreMin($scores[0])
            ->setScoreMax($scores[$count - 1])
            ->setScoreMedian($this->percentile($scores, 0.5))
            ->setScoreP25($this->percentile($scores, 0.25))
            ->setScoreP75($this->percentile($scores, 0.75));
    }

    /**
     * Linear interpolation between closest ranks (the same method `numpy.percentile()` defaults to) —
     * $sortedScores must already be sorted ascending.
     *
     * @param array<int, float> $sortedScores
     * @param float $percentile
     *
     * @return float
     */
    protected function percentile(array $sortedScores, float $percentile): float
    {
        $lastIndex = count($sortedScores) - 1;

        if ($lastIndex === 0) {
            return $sortedScores[0];
        }

        $rank = $percentile * $lastIndex;
        $lowerIndex = (int)floor($rank);
        $upperIndex = (int)ceil($rank);

        if ($lowerIndex === $upperIndex) {
            return $sortedScores[$lowerIndex];
        }

        $fraction = $rank - $lowerIndex;

        return $sortedScores[$lowerIndex] + $fraction * ($sortedScores[$upperIndex] - $sortedScores[$lowerIndex]);
    }
}
