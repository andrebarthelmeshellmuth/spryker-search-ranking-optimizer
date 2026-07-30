<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Persistence;

use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;

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

    /**
     * The run currently in status=calculating, if any — at most one at a time by design. Backs the
     * Calibration page's live progress counter.
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer|null
     */
    public function findCalibrationInProgress(): ?SearchRankingCalibrationTransfer;

    /**
     * Looks up a query by its exact canonical (searchTerm, storeName, localeName) key — the same key
     * {@see SearchRankingOptimizerEntityManagerInterface::findOrCreateQuery()} upserts against.
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryTransfer|null
     */
    public function findQueryByTermStoreLocale(string $searchTerm, string $storeName, string $localeName): ?SearchRankingQueryTransfer;

    /**
     * Every rated query, newest-activity-first (`updated_at` DESC — bumped on every new rating, not just
     * on an importance-weight edit) — a triage aid for the Query Curator role.
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingQueryTransfer>
     */
    public function findAllQueriesOrderedByUpdatedAt(): array;

    /**
     * @param int $idSearchRankingQuery
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryTransfer|null
     */
    public function findQueryById(int $idSearchRankingQuery): ?SearchRankingQueryTransfer;

    /**
     * The distinct, already-canonical search terms organically collected via the SRP rating widget for a
     * given store/locale — the default calibration term source once real ratings exist (see
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CalibrationUploadHandlerInterface::createCalibration()}).
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<string>
     */
    public function findDistinctSearchTermsByStoreLocale(string $storeName, string $localeName): array;
}
