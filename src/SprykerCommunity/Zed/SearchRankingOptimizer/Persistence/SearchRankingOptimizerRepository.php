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
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationSearchTermTransfer;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use Propel\Runtime\ActiveQuery\Criteria;
use Spryker\Zed\Kernel\Persistence\AbstractRepository;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerPersistenceFactory getFactory()
 */
class SearchRankingOptimizerRepository extends AbstractRepository implements SearchRankingOptimizerRepositoryInterface
{
    /**
     * @return array<\Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer>
     */
    public function getUploadedCalibrations(): array
    {
        $calibrationEntities = $this->getFactory()
            ->createSearchRankingSaturationPointCalibrationQuery()
            ->filterByStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED)
            ->orderByIdSearchRankingSaturationPointCalibration(Criteria::DESC)
            ->find();

        $mapper = $this->getFactory()->createSearchRankingOptimizerMapper();
        $calibrationTransfers = [];

        foreach ($calibrationEntities as $calibrationEntity) {
            $calibrationTransfers[] = $mapper->mapCalibrationEntityToTransfer($calibrationEntity, new SearchRankingSaturationPointCalibrationTransfer());
        }

        return $calibrationTransfers;
    }

    /**
     * @param int $idSearchRankingSaturationPointCalibration
     */
    public function findCalibrationWithSearchTerms(int $idSearchRankingSaturationPointCalibration): ?SearchRankingSaturationPointCalibrationTransfer
    {
        $calibrationEntity = $this->getFactory()
            ->createSearchRankingSaturationPointCalibrationQuery()
            ->findOneByIdSearchRankingSaturationPointCalibration($idSearchRankingSaturationPointCalibration);

        if ($calibrationEntity === null) {
            return null;
        }

        $mapper = $this->getFactory()->createSearchRankingOptimizerMapper();
        $calibrationTransfer = $mapper->mapCalibrationEntityToTransfer($calibrationEntity, new SearchRankingSaturationPointCalibrationTransfer());

        $searchTermEntities = $this->getFactory()
            ->createSearchRankingSaturationPointCalibrationSearchTermQuery()
            ->filterByFkSearchRankingSaturationPointCalibration($idSearchRankingSaturationPointCalibration)
            ->orderByIdSearchRankingSaturationPointCalibrationSearchTerm()
            ->find();

        foreach ($searchTermEntities as $searchTermEntity) {
            $calibrationTransfer->addSearchTerm(
                $mapper->mapCalibrationSearchTermEntityToTransfer($searchTermEntity, new SearchRankingSaturationPointCalibrationSearchTermTransfer()),
            );
        }

        return $calibrationTransfer;
    }

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function findLatestCalculatedCalibration(string $storeName, string $localeName): ?SearchRankingSaturationPointCalibrationTransfer
    {
        $calibrationEntity = $this->getFactory()
            ->createSearchRankingSaturationPointCalibrationQuery()
            ->filterByStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATED)
            ->filterByStoreName($storeName)
            ->filterByLocaleName($localeName)
            ->orderByCalculatedAt(Criteria::DESC)
            ->findOne();

        if ($calibrationEntity === null) {
            return null;
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapCalibrationEntityToTransfer($calibrationEntity, new SearchRankingSaturationPointCalibrationTransfer());
    }

    /**
     * The run `ScoreCalibrator::calculate()` is currently working through FOR THIS (store, locale), if
     * any — at most one at a time SYSTEM-WIDE by design (see {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\ScoreCalibrator::runNextCalibration()}'s
     * own skip-older-uploads step), but that system-wide run may be for a DIFFERENT scope than the one
     * being viewed here — filtered so the Calibration page's progress widget never shows another store's
     * run as if it were the viewed scope's own. Backs the Calibration page's live progress counter;
     * polled, so deliberately cheap — no search terms loaded.
     *
     * @param string $storeName
     * @param string $localeName
     */
    public function findCalibrationInProgress(string $storeName, string $localeName): ?SearchRankingSaturationPointCalibrationTransfer
    {
        $calibrationEntity = $this->getFactory()
            ->createSearchRankingSaturationPointCalibrationQuery()
            ->filterByStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATING)
            ->filterByStoreName($storeName)
            ->filterByLocaleName($localeName)
            ->findOne();

        if ($calibrationEntity === null) {
            return null;
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapCalibrationEntityToTransfer($calibrationEntity, new SearchRankingSaturationPointCalibrationTransfer());
    }

    /**
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     */
    public function findQueryByTermStoreLocale(string $searchTerm, string $storeName, string $localeName): ?SearchRankingQueryTransfer
    {
        $queryEntity = $this->getFactory()
            ->createSearchRankingQueryQuery()
            ->filterBySearchTerm($searchTerm)
            ->filterByStoreName($storeName)
            ->filterByLocaleName($localeName)
            ->findOne();

        if ($queryEntity === null) {
            return null;
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapQueryEntityToTransfer($queryEntity, new SearchRankingQueryTransfer());
    }

    /**
     * @param int $idSearchRankingQuery
     */
    public function findQueryById(int $idSearchRankingQuery): ?SearchRankingQueryTransfer
    {
        $queryEntity = $this->getFactory()
            ->createSearchRankingQueryQuery()
            ->findOneByIdSearchRankingQuery($idSearchRankingQuery);

        if ($queryEntity === null) {
            return null;
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapQueryEntityToTransfer($queryEntity, new SearchRankingQueryTransfer());
    }

    /**
     * @return array<\Generated\Shared\Transfer\SearchRankingQueryTransfer>
     */
    public function findAllQueriesOrderedByUpdatedAt(): array
    {
        $queryEntities = $this->getFactory()
            ->createSearchRankingQueryQuery()
            ->orderByUpdatedAt(Criteria::DESC)
            ->find();

        $mapper = $this->getFactory()->createSearchRankingOptimizerMapper();
        $queryTransfers = [];

        foreach ($queryEntities as $queryEntity) {
            $queryTransfers[] = $mapper->mapQueryEntityToTransfer($queryEntity, new SearchRankingQueryTransfer());
        }

        return $queryTransfers;
    }

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<string>
     */
    public function findDistinctSearchTermsByStoreLocale(string $storeName, string $localeName): array
    {
        return $this->getFactory()
            ->createSearchRankingQueryQuery()
            ->filterByStoreName($storeName)
            ->filterByLocaleName($localeName)
            ->select('SearchTerm')
            ->distinct()
            ->find()
            ->getArrayCopy();
    }

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingQueryTransfer>
     */
    public function findQueriesByStoreLocale(string $storeName, string $localeName): array
    {
        $queryEntities = $this->getFactory()
            ->createSearchRankingQueryQuery()
            ->filterByStoreName($storeName)
            ->filterByLocaleName($localeName)
            ->find();

        $mapper = $this->getFactory()->createSearchRankingOptimizerMapper();
        $queryTransfers = [];

        foreach ($queryEntities as $queryEntity) {
            $queryTransfers[] = $mapper->mapQueryEntityToTransfer($queryEntity, new SearchRankingQueryTransfer());
        }

        return $queryTransfers;
    }

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingQueryRatingTransfer>
     */
    public function findRatingsByStoreLocale(string $storeName, string $localeName): array
    {
        $ratingEntities = $this->getFactory()
            ->createSearchRankingQueryRatingQuery()
            ->useSearchRankingQueryQuery()
                ->filterByStoreName($storeName)
                ->filterByLocaleName($localeName)
            ->endUse()
            ->find();

        $mapper = $this->getFactory()->createSearchRankingOptimizerMapper();
        $ratingTransfers = [];

        foreach ($ratingEntities as $ratingEntity) {
            $ratingTransfers[] = $mapper->mapQueryRatingEntityToTransfer($ratingEntity, new SearchRankingQueryRatingTransfer());
        }

        return $ratingTransfers;
    }

    /**
     * @param int $idSearchRankingQuery
     * @param string $customerReference
     * @param array<int> $idProductAbstracts
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingQueryRatingTransfer>
     */
    public function findRatingsByQueryCustomerAndProducts(int $idSearchRankingQuery, string $customerReference, array $idProductAbstracts): array
    {
        if ($idProductAbstracts === []) {
            return [];
        }

        $ratingEntities = $this->getFactory()
            ->createSearchRankingQueryRatingQuery()
            ->filterByFkSearchRankingQuery($idSearchRankingQuery)
            ->filterByCustomerReference($customerReference)
            ->filterByFkProductAbstract_In($idProductAbstracts)
            ->find();

        $mapper = $this->getFactory()->createSearchRankingOptimizerMapper();
        $ratingTransfers = [];

        foreach ($ratingEntities as $ratingEntity) {
            $ratingTransfers[] = $mapper->mapQueryRatingEntityToTransfer($ratingEntity, new SearchRankingQueryRatingTransfer());
        }

        return $ratingTransfers;
    }

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function findLatestEvaluation(string $storeName, string $localeName): ?SearchRankingEvaluationTransfer
    {
        $evaluationEntity = $this->getFactory()
            ->createSearchRankingEvaluationQuery()
            ->filterByStoreName($storeName)
            ->filterByLocaleName($localeName)
            ->orderByCreatedAt(Criteria::DESC)
            ->findOne();

        if ($evaluationEntity === null) {
            return null;
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapEvaluationEntityToTransfer($evaluationEntity, new SearchRankingEvaluationTransfer());
    }

    /**
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingEvaluationTransfer>
     */
    public function findEvaluationHistoryByStoreLocale(string $storeName, string $localeName): array
    {
        $evaluationEntities = $this->getFactory()
            ->createSearchRankingEvaluationQuery()
            ->filterByStoreName($storeName)
            ->filterByLocaleName($localeName)
            ->orderByCreatedAt(Criteria::DESC)
            ->find();

        $mapper = $this->getFactory()->createSearchRankingOptimizerMapper();
        $evaluationTransfers = [];

        foreach ($evaluationEntities as $evaluationEntity) {
            $evaluationTransfers[] = $mapper->mapEvaluationEntityToTransfer($evaluationEntity, new SearchRankingEvaluationTransfer());
        }

        return $evaluationTransfers;
    }

    /**
     * @return array<\Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer>
     */
    public function findWeightCheckpointHistory(): array
    {
        $weightCheckpointEntities = $this->getFactory()
            ->createSearchRankingWeightCheckpointQuery()
            ->orderByCreatedAt(Criteria::DESC)
            ->find();

        $mapper = $this->getFactory()->createSearchRankingOptimizerMapper();
        $weightCheckpointTransfers = [];

        foreach ($weightCheckpointEntities as $weightCheckpointEntity) {
            $weightCheckpointTransfers[] = $mapper->mapWeightCheckpointEntityToTransfer($weightCheckpointEntity, new SearchRankingWeightCheckpointTransfer());
        }

        return $weightCheckpointTransfers;
    }

    /**
     * @param int $idSearchRankingWeightCheckpoint
     */
    public function findWeightCheckpointById(int $idSearchRankingWeightCheckpoint): ?SearchRankingWeightCheckpointTransfer
    {
        $weightCheckpointEntity = $this->getFactory()
            ->createSearchRankingWeightCheckpointQuery()
            ->findOneByIdSearchRankingWeightCheckpoint($idSearchRankingWeightCheckpoint);

        if ($weightCheckpointEntity === null) {
            return null;
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapWeightCheckpointEntityToTransfer($weightCheckpointEntity, new SearchRankingWeightCheckpointTransfer());
    }

    /**
     * @param int $idSearchRankingMetric
     * @param string $storeName
     * @param string $localeName
     */
    public function findAutoTuneMetricConfigByMetricId(
        int $idSearchRankingMetric,
        string $storeName,
        string $localeName,
    ): ?SearchRankingAutoTuneMetricConfigTransfer {
        $autoTuneMetricConfigEntity = $this->getFactory()
            ->createSearchRankingAutoTuneMetricConfigQuery()
            ->filterByFkSearchRankingMetric($idSearchRankingMetric)
            ->filterByStoreName($storeName)
            ->filterByLocaleName($localeName)
            ->findOne();

        if ($autoTuneMetricConfigEntity === null) {
            return null;
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapAutoTuneMetricConfigEntityToTransfer($autoTuneMetricConfigEntity, new SearchRankingAutoTuneMetricConfigTransfer());
    }

    /**
     * Only configs with a real threshold set for THIS store — a metric with no config row for a given
     * (store, locale), or an explicit NULL threshold, has opted out of auto-tune entirely for that scope
     * and is simply absent here, per this feature's own design (see the package README). Store-scoped
     * only, deliberately NOT filtered by locale: for an `isLocaleScoped=false` metric (the common case)
     * saving fans the same config out to every real locale of the store, so ANY of its locale rows proves
     * it's opted in; for an `isLocaleScoped=true` metric this can return several independent rows for the
     * SAME metric, one per locale that's been individually configured — see
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunnerInterface} for how
     * the caller groups these back up by metric.
     *
     * @param string $storeName
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer>
     */
    public function findAutoTuneMetricConfigsWithThresholdSet(string $storeName): array
    {
        $autoTuneMetricConfigEntities = $this->getFactory()
            ->createSearchRankingAutoTuneMetricConfigQuery()
            ->filterByStoreName($storeName)
            ->filterByAutoTuneThreshold(null, Criteria::ISNOTNULL)
            ->find();

        $mapper = $this->getFactory()->createSearchRankingOptimizerMapper();
        $autoTuneMetricConfigTransfers = [];

        foreach ($autoTuneMetricConfigEntities as $autoTuneMetricConfigEntity) {
            $autoTuneMetricConfigTransfers[] = $mapper->mapAutoTuneMetricConfigEntityToTransfer($autoTuneMetricConfigEntity, new SearchRankingAutoTuneMetricConfigTransfer());
        }

        return $autoTuneMetricConfigTransfers;
    }

    /**
     * @param int $idSearchRankingOptimizerRun
     */
    public function findOptimizerRunById(int $idSearchRankingOptimizerRun): ?SearchRankingOptimizerRunTransfer
    {
        $optimizerRunEntity = $this->getFactory()
            ->createSearchRankingOptimizerRunQuery()
            ->findOneByIdSearchRankingOptimizerRun($idSearchRankingOptimizerRun);

        if ($optimizerRunEntity === null) {
            return null;
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapOptimizerRunEntityToTransfer($optimizerRunEntity, new SearchRankingOptimizerRunTransfer());
    }

    public function findOldestQueuedOptimizerRun(): ?SearchRankingOptimizerRunTransfer
    {
        $optimizerRunEntity = $this->getFactory()
            ->createSearchRankingOptimizerRunQuery()
            ->filterByStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_QUEUED)
            ->orderByIdSearchRankingOptimizerRun(Criteria::ASC)
            ->findOne();

        if ($optimizerRunEntity === null) {
            return null;
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapOptimizerRunEntityToTransfer($optimizerRunEntity, new SearchRankingOptimizerRunTransfer());
    }

    public function findOptimizerRunInProgress(): ?SearchRankingOptimizerRunTransfer
    {
        $optimizerRunEntity = $this->getFactory()
            ->createSearchRankingOptimizerRunQuery()
            ->filterByStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_RUNNING)
            ->findOne();

        if ($optimizerRunEntity === null) {
            return null;
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapOptimizerRunEntityToTransfer($optimizerRunEntity, new SearchRankingOptimizerRunTransfer());
    }

    /**
     * @param string $storeName
     * @param string $localeName
     */
    public function findLatestOptimizerRunByStoreLocale(string $storeName, string $localeName): ?SearchRankingOptimizerRunTransfer
    {
        $optimizerRunEntity = $this->getFactory()
            ->createSearchRankingOptimizerRunQuery()
            ->filterByStoreName($storeName)
            ->filterByLocaleName($localeName)
            ->orderByCreatedAt(Criteria::DESC)
            ->findOne();

        if ($optimizerRunEntity === null) {
            return null;
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapOptimizerRunEntityToTransfer($optimizerRunEntity, new SearchRankingOptimizerRunTransfer());
    }
}
