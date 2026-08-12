<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Persistence;

use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;

interface SearchRankingOptimizerRepositoryInterface
{
    /**
     * Returns every calibration run with status=uploaded, newest first (by id) — search terms are NOT
     * loaded (use {@see findCalibrationWithSearchTerms()} for that).
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer>
     */
    public function getUploadedCalibrations(): array;

    /**
     * @param int $idSearchRankingSaturationPointCalibration
     */
    public function findCalibrationWithSearchTerms(int $idSearchRankingSaturationPointCalibration): ?SearchRankingSaturationPointCalibrationTransfer;

    /**
     * The most recent calibration run with status=calculated for this (store, locale), or null when none
     * has ever finished there.
     *
     * @param string $storeName
     * @param string $localeName
     */
    public function findLatestCalculatedCalibration(string $storeName, string $localeName): ?SearchRankingSaturationPointCalibrationTransfer;

    /**
     * The run for this (store, locale) currently in status=calculating, if any — at most one SYSTEM-WIDE
     * at a time by design, but that one run may be for a different scope than the one asked about here.
     * Backs the Calibration page's live progress counter.
     *
     * @param string $storeName
     * @param string $localeName
     */
    public function findCalibrationInProgress(string $storeName, string $localeName): ?SearchRankingSaturationPointCalibrationTransfer;

    /**
     * Looks up a query by its exact canonical (searchTerm, storeName, localeName) key — the same key
     * {@see SearchRankingOptimizerEntityManagerInterface::findOrCreateQuery()} upserts against.
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
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
     */
    public function findQueryById(int $idSearchRankingQuery): ?SearchRankingQueryTransfer;

    /**
     * The distinct, already-canonical search terms organically collected via the SRP rating widget for a
     * given store/locale — the default calibration term source once real ratings exist (see
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\SaturationPointCalibrationUploadHandlerInterface::createCalibration()}).
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<string>
     */
    public function findDistinctSearchTermsByStoreLocale(string $storeName, string $localeName): array;

    /**
     * Every query for a given store/locale, rated or not — a rank_eval evaluation run only ever acts on
     * the ones that turn out to have ratings (see {@see findRatingsByStoreLocale()}), so this
     * deliberately does not pre-filter; the caller decides what "rated" means for its own purpose.
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingQueryTransfer>
     */
    public function findQueriesByStoreLocale(string $storeName, string $localeName): array;

    /**
     * Every individual rating (one row per admin/query/product) belonging to a query in the given
     * store/locale — the raw input a rank_eval evaluation run groups-and-averages into per-(query,
     * product) gains itself (kept as individual rows here, not pre-aggregated, since the numeric gain a
     * rating_type maps to is a Business-layer/Config concern, not a Persistence one).
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingQueryRatingTransfer>
     */
    public function findRatingsByStoreLocale(string $storeName, string $localeName): array;

    /**
     * One customer's own ratings for a given query, restricted to the given product-abstract ids — the
     * batched read backing the SRP widget's "show me what I already rated" pre-fill, one call per page
     * render covering every product on it rather than one call per tile.
     *
     * @param int $idSearchRankingQuery
     * @param string $customerReference
     * @param array<int> $idProductAbstracts
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingQueryRatingTransfer>
     */
    public function findRatingsByQueryCustomerAndProducts(int $idSearchRankingQuery, string $customerReference, array $idProductAbstracts): array;

    /**
     * The most recently persisted rank_eval evaluation run for a given store/locale, or null when none has
     * ever run.
     *
     * @param string $storeName
     * @param string $localeName
     */
    public function findLatestEvaluation(string $storeName, string $localeName): ?SearchRankingEvaluationTransfer;

    /**
     * Every persisted evaluation run, newest first — backs the Zed Evaluation page's history list. Null
     * $storeName/$localeName means "no filter" (show every store/locale); a non-null value narrows to
     * that scope only.
     *
     * @param string|null $storeName
     * @param string|null $localeName
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingEvaluationTransfer>
     */
    public function findEvaluationHistory(?string $storeName = null, ?string $localeName = null): array;

    /**
     * Every persisted weight checkpoint, newest first — backs the Zed Checkpoint page's history list. Null
     * $storeName/$localeName means "no filter" (show every store/locale); a non-null value narrows to
     * that scope only.
     *
     * @param string|null $storeName
     * @param string|null $localeName
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer>
     */
    public function findWeightCheckpointHistory(?string $storeName = null, ?string $localeName = null): array;

    /**
     * @param int $idSearchRankingWeightCheckpoint
     */
    public function findWeightCheckpointById(int $idSearchRankingWeightCheckpoint): ?SearchRankingWeightCheckpointTransfer;

    /**
     * Returns null when the metric has no auto-tune config yet for this (store, locale) — a safe,
     * expected state for most metric+store+locale combinations, not an error.
     *
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     */
    public function findAutoTuneMetricConfigByMetricId(
        int $idSearchRankingMetric,
        string $storeName,
        string $localeName,
    ): ?SearchRankingAutoTuneMetricConfigTransfer;

    /**
     * Only configs with a real threshold set for THIS store — a metric with no config row for a given
     * (store, locale), or an explicit NULL threshold, has opted out of auto-tune entirely for that scope
     * and is simply absent here. Store-scoped only, NOT locale-filtered — can return several rows for the
     * same metric (one per locale it's been configured at).
     *
     * @param string $storeName
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer>
     */
    public function findAutoTuneMetricConfigsWithThresholdSet(string $storeName): array;

    /**
     * Whether ANY metric, in any store/locale, has "notify by email" turned on — the one thing that makes
     * the auto-tune notification ACL role actually required. Deliberately unscoped and threshold-agnostic:
     * this answers "could this shop ever need to email an admin", not "will it email one tonight", so a
     * config that has notify enabled but no threshold yet still counts (a threshold is one form save away,
     * and a diagnostic that stayed quiet until then would go quiet exactly when it was about to matter).
     */
    public function hasAutoTuneMetricConfigWithNotifyEnabled(): bool;

    /**
     * @param int $idSearchRankingOptimizerRun
     */
    public function findOptimizerRunById(int $idSearchRankingOptimizerRun): ?SearchRankingOptimizerRunTransfer;

    /**
     * The oldest still-queued run, if any — FIFO processing, one run per
     * `search-ranking-optimizer:optimize` invocation, same "at most one at a time" discipline as
     * Calibration (though Calibration instead always picks the NEWEST upload, since there only the latest
     * search-term list matters; here every queued run is a distinct, equally-valid request).
     */
    public function findOldestQueuedOptimizerRun(): ?SearchRankingOptimizerRunTransfer;

    /**
     * The run currently being worked, if any — backs the Zed page's live progress counter. Deliberately
     * cheap (a single indexed lookup by status), safe to poll.
     */
    public function findOptimizerRunInProgress(): ?SearchRankingOptimizerRunTransfer;

    /**
     * The most recently created run for a given store/locale, regardless of status — backs the Zed page's
     * "last run" display (a queued/running/done/failed run all matter equally for this purpose).
     *
     * @param string $storeName
     * @param string $localeName
     */
    public function findLatestOptimizerRunByStoreLocale(string $storeName, string $localeName): ?SearchRankingOptimizerRunTransfer;
}
