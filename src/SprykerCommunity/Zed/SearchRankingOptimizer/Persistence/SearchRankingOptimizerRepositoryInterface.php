<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Persistence;

use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;

interface SearchRankingOptimizerRepositoryInterface
{
    /**
     * Returns every calibration run with status=uploaded, newest first (by id) — search terms are NOT
     * loaded (use {@see findCalibrationWithSearchTerms()} for that).
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingCalibrationTransfer>
     */
    public function getUploadedCalibrations(): array;

    /**
     * @param int $idSearchRankingCalibration
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer|null
     */
    public function findCalibrationWithSearchTerms(int $idSearchRankingCalibration): ?SearchRankingCalibrationTransfer;

    /**
     * The most recent calibration run with status=calculated, or null when none has ever finished.
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer|null
     */
    public function findLatestCalculatedCalibration(): ?SearchRankingCalibrationTransfer;
}
