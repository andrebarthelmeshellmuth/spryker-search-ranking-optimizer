<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration;

use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer;

class StatisticsCalculator implements StatisticsCalculatorInterface
{
    /**
     * {@inheritDoc}
     *
     * @param array<float> $scores
     */
    public function calculate(array $scores): SearchRankingSaturationPointCalibrationTransfer
    {
        sort($scores);
        $count = count($scores);
        $mean = array_sum($scores) / $count;

        return (new SearchRankingSaturationPointCalibrationTransfer())
            ->setSampleCount($count)
            ->setComputedK($mean)
            ->setValueMean($mean)
            ->setValueMin($scores[0])
            ->setValueMax($scores[$count - 1])
            ->setValueMedian($this->percentile($scores, 0.5))
            ->setValueP25($this->percentile($scores, 0.25))
            ->setValueP75($this->percentile($scores, 0.75));
    }

    /**
     * Linear interpolation between closest ranks (the same method `numpy.percentile()` defaults to) —
     * $sortedScores must already be sorted ascending.
     *
     * @param array<int, float> $sortedScores
     * @param float $percentile
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
