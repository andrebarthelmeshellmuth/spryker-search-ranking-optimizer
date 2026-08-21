<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Persistence;

use DateTime;
use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfig;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingEvaluation;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingOptimizerRun;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRating;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingSaturationPointCalibration;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingSaturationPointCalibrationSearchTerm;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingWeightCheckpoint;
use Spryker\Zed\Kernel\Persistence\AbstractEntityManager;
use Spryker\Zed\Kernel\Persistence\EntityManager\TransactionHandlerInterface;
use Spryker\Zed\Kernel\Persistence\EntityManager\TransactionTrait;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerPersistenceFactory getFactory()
 */
class SearchRankingOptimizerEntityManager extends AbstractEntityManager implements SearchRankingOptimizerEntityManagerInterface
{
    use TransactionTrait;

    /**
     * {@inheritDoc}
     */
    public function getTransactionHandler(): TransactionHandlerInterface
    {
        return $this->createTransactionHandlerFactory()->createHandler();
    }

    /**
     * A calibration upload can carry hundreds of search terms, each its own child row save -- wrapped in
     * one transaction so a failure partway through (e.g. a dropped DB connection) rolls back the parent
     * row too, rather than leaving a calibration whose `totalCount` (set up front, from the FULL search
     * term list) can never match its real child row count, permanently stuck looking "in progress" with no
     * way to ever finish or be flagged failed.
     *
     * @param \Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer $calibrationTransfer
     */
    public function createCalibration(SearchRankingSaturationPointCalibrationTransfer $calibrationTransfer): SearchRankingSaturationPointCalibrationTransfer
    {
        return $this->getTransactionHandler()->handleTransaction(
            fn () => $this->createCalibrationWithinTransaction($calibrationTransfer),
        );
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer $calibrationTransfer
     */
    protected function createCalibrationWithinTransaction(
        SearchRankingSaturationPointCalibrationTransfer $calibrationTransfer,
    ): SearchRankingSaturationPointCalibrationTransfer {
        $calibrationEntity = new SpySearchRankingSaturationPointCalibration();
        $calibrationEntity->setCalibrationType(
            $calibrationTransfer->getCalibrationType() ?? SearchRankingOptimizerConfig::CALIBRATION_TYPE_RELEVANCE_SCORE,
        );
        $calibrationEntity->setRelevantProductCount($calibrationTransfer->getRelevantProductCountOrFail());
        $calibrationEntity->setStoreName($calibrationTransfer->getStoreNameOrFail());
        $calibrationEntity->setLocaleName($calibrationTransfer->getLocaleNameOrFail());
        $calibrationEntity->setStatus($calibrationTransfer->getStatus() ?? SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);
        // Known up front (the caller already populated every search term before calling this) — the
        // denominator half of the live "X/Y processed" counter never needs to change again after this.
        $calibrationEntity->setTotalCount(count($calibrationTransfer->getSearchTerms()));
        $calibrationEntity->save();

        foreach ($calibrationTransfer->getSearchTerms() as $searchTermTransfer) {
            $searchTermEntity = new SpySearchRankingSaturationPointCalibrationSearchTerm();
            $searchTermEntity->setFkSearchRankingSaturationPointCalibration($calibrationEntity->getIdSearchRankingSaturationPointCalibration());
            $searchTermEntity->setSearchTerm($searchTermTransfer->getSearchTermOrFail());
            $searchTermEntity->save();

            $searchTermTransfer
                ->setIdSearchRankingSaturationPointCalibrationSearchTerm($searchTermEntity->getIdSearchRankingSaturationPointCalibrationSearchTerm())
                ->setFkSearchRankingSaturationPointCalibration($calibrationEntity->getIdSearchRankingSaturationPointCalibration());
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapCalibrationEntityToTransfer($calibrationEntity, $calibrationTransfer);
    }

    /**
     * @param int $idSearchRankingSaturationPointCalibration
     * @param string $status
     */
    public function updateCalibrationStatus(int $idSearchRankingSaturationPointCalibration, string $status): void
    {
        $calibrationEntity = $this->getFactory()
            ->createSearchRankingSaturationPointCalibrationQuery()
            ->findOneByIdSearchRankingSaturationPointCalibration($idSearchRankingSaturationPointCalibration);

        if ($calibrationEntity === null) {
            return;
        }

        $calibrationEntity->setStatus($status);
        $calibrationEntity->save();
    }

    /**
     * @param int $idSearchRankingSaturationPointCalibrationSearchTerm
     * @param int $productsFound
     * @param array<float> $values
     */
    public function saveCalibrationSearchTermResult(int $idSearchRankingSaturationPointCalibrationSearchTerm, int $productsFound, array $values): void
    {
        $searchTermEntity = $this->getFactory()
            ->createSearchRankingSaturationPointCalibrationSearchTermQuery()
            ->findOneByIdSearchRankingSaturationPointCalibrationSearchTerm($idSearchRankingSaturationPointCalibrationSearchTerm);

        if ($searchTermEntity === null) {
            return;
        }

        $searchTermEntity->setProductsFound($productsFound);
        $searchTermEntity->setValues($this->getFactory()->createSearchRankingOptimizerMapper()->implodeValues($values));
        $searchTermEntity->save();
    }

    /**
     * Called once per search term inside {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\ScoreCalibrator::calculate()}'s
     * own loop — the calculation run is a single sequential process, so a plain read-modify-write (same
     * pattern every other method here uses) needs no extra locking.
     *
     * @param int $idSearchRankingSaturationPointCalibration
     */
    public function incrementCalibrationProcessedCount(int $idSearchRankingSaturationPointCalibration): void
    {
        $calibrationEntity = $this->getFactory()
            ->createSearchRankingSaturationPointCalibrationQuery()
            ->findOneByIdSearchRankingSaturationPointCalibration($idSearchRankingSaturationPointCalibration);

        if ($calibrationEntity === null) {
            return;
        }

        $calibrationEntity->setProcessedCount($calibrationEntity->getProcessedCount() + 1);
        $calibrationEntity->save();
    }

    /**
     * @param int $idSearchRankingSaturationPointCalibration
     * @param \Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer $statisticsTransfer
     */
    public function saveCalibrationStatistics(
        int $idSearchRankingSaturationPointCalibration,
        SearchRankingSaturationPointCalibrationTransfer $statisticsTransfer,
    ): void {
        $calibrationEntity = $this->getFactory()
            ->createSearchRankingSaturationPointCalibrationQuery()
            ->findOneByIdSearchRankingSaturationPointCalibration($idSearchRankingSaturationPointCalibration);

        if ($calibrationEntity === null) {
            return;
        }

        $calibrationEntity->setComputedK($statisticsTransfer->getComputedK());
        $calibrationEntity->setValueMin($statisticsTransfer->getValueMin());
        $calibrationEntity->setValueMax($statisticsTransfer->getValueMax());
        $calibrationEntity->setValueMean($statisticsTransfer->getValueMean());
        $calibrationEntity->setValueMedian($statisticsTransfer->getValueMedian());
        $calibrationEntity->setValueP25($statisticsTransfer->getValueP25());
        $calibrationEntity->setValueP75($statisticsTransfer->getValueP75());
        $calibrationEntity->setSampleCount($statisticsTransfer->getSampleCount());
        $calibrationEntity->setCalculatedAt(date('c'));
        $calibrationEntity->setStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATED);
        $calibrationEntity->save();
    }

    /**
     * @param int $idSearchRankingSaturationPointCalibration
     * @param string $errorMessage
     */
    public function markCalibrationFailed(int $idSearchRankingSaturationPointCalibration, string $errorMessage): void
    {
        $calibrationEntity = $this->getFactory()
            ->createSearchRankingSaturationPointCalibrationQuery()
            ->findOneByIdSearchRankingSaturationPointCalibration($idSearchRankingSaturationPointCalibration);

        if ($calibrationEntity === null) {
            return;
        }

        $calibrationEntity->setStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_FAILED);
        $calibrationEntity->setErrorMessage($errorMessage);
        $calibrationEntity->save();
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingQueryTransfer $queryTransfer
     */
    public function createQuery(SearchRankingQueryTransfer $queryTransfer): SearchRankingQueryTransfer
    {
        $queryEntity = new SpySearchRankingQuery();
        $queryEntity->setSearchTerm($queryTransfer->getSearchTermOrFail());
        $queryEntity->setStoreName($queryTransfer->getStoreNameOrFail());
        $queryEntity->setLocaleName($queryTransfer->getLocaleNameOrFail());

        if ($queryTransfer->getImportanceWeight() !== null) {
            $queryEntity->setImportanceWeight($queryTransfer->getImportanceWeight());
        }

        $queryEntity->save();

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapQueryEntityToTransfer($queryEntity, $queryTransfer);
    }

    /**
     * @param int $idSearchRankingQuery
     * @param float $importanceWeight
     */
    public function updateQueryImportanceWeight(int $idSearchRankingQuery, float $importanceWeight): void
    {
        $queryEntity = $this->getFactory()
            ->createSearchRankingQueryQuery()
            ->findOneByIdSearchRankingQuery($idSearchRankingQuery);

        if ($queryEntity === null) {
            return;
        }

        $queryEntity->setImportanceWeight($importanceWeight);
        $queryEntity->save();
    }

    /**
     * @param int $idSearchRankingQuery
     */
    public function touchQuery(int $idSearchRankingQuery): void
    {
        $queryEntity = $this->getFactory()
            ->createSearchRankingQueryQuery()
            ->findOneByIdSearchRankingQuery($idSearchRankingQuery);

        if ($queryEntity === null) {
            return;
        }

        $queryEntity->setUpdatedAt(new DateTime());
        $queryEntity->save();
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingQueryRatingTransfer $ratingTransfer
     */
    public function upsertRating(SearchRankingQueryRatingTransfer $ratingTransfer): SearchRankingQueryRatingTransfer
    {
        $fkSearchRankingQuery = $ratingTransfer->getFkSearchRankingQueryOrFail();
        $customerReference = $ratingTransfer->getCustomerReferenceOrFail();
        $fkProductAbstract = $ratingTransfer->getFkProductAbstractOrFail();

        $ratingEntity = $this->getFactory()
            ->createSearchRankingQueryRatingQuery()
            ->filterByFkSearchRankingQuery($fkSearchRankingQuery)
            ->filterByCustomerReference($customerReference)
            ->filterByFkProductAbstract($fkProductAbstract)
            ->findOne();

        if ($ratingEntity === null) {
            $ratingEntity = new SpySearchRankingQueryRating();
            $ratingEntity->setFkSearchRankingQuery($fkSearchRankingQuery);
            $ratingEntity->setCustomerReference($customerReference);
            $ratingEntity->setFkProductAbstract($fkProductAbstract);
        }

        $ratingEntity->setRatingType($ratingTransfer->getRatingTypeOrFail());
        $ratingEntity->save();

        $this->touchQuery($fkSearchRankingQuery);

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapQueryRatingEntityToTransfer($ratingEntity, $ratingTransfer);
    }

    /**
     * Backs the widget's "click an already-pressed button to unselect" affordance — the same identifying
     * triple {@see upsertRating()} matches an existing row against. A safe no-op when there is nothing to
     * delete (e.g. a duplicate clear request arriving after the first one already succeeded).
     *
     * @param int $fkSearchRankingQuery
     * @param string $customerReference
     * @param int $fkProductAbstract
     */
    public function deleteRating(int $fkSearchRankingQuery, string $customerReference, int $fkProductAbstract): void
    {
        $this->getFactory()
            ->createSearchRankingQueryRatingQuery()
            ->filterByFkSearchRankingQuery($fkSearchRankingQuery)
            ->filterByCustomerReference($customerReference)
            ->filterByFkProductAbstract($fkProductAbstract)
            ->delete();
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationTransfer $evaluationTransfer
     */
    public function createEvaluation(SearchRankingEvaluationTransfer $evaluationTransfer): SearchRankingEvaluationTransfer
    {
        $evaluationEntity = new SpySearchRankingEvaluation();
        $evaluationEntity->setStoreName($evaluationTransfer->getStoreNameOrFail());
        $evaluationEntity->setLocaleName($evaluationTransfer->getLocaleNameOrFail());
        $evaluationEntity->setMetricScore($evaluationTransfer->getMetricScoreOrFail());
        $evaluationEntity->setQueryCount($evaluationTransfer->getQueryCountOrFail());
        $evaluationEntity->save();

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapEvaluationEntityToTransfer($evaluationEntity, $evaluationTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer $weightCheckpointTransfer
     */
    public function createWeightCheckpoint(SearchRankingWeightCheckpointTransfer $weightCheckpointTransfer): SearchRankingWeightCheckpointTransfer
    {
        $mapper = $this->getFactory()->createSearchRankingOptimizerMapper();

        $weightCheckpointEntity = new SpySearchRankingWeightCheckpoint();
        $weightCheckpointEntity->setSource($weightCheckpointTransfer->getSourceOrFail());
        $weightCheckpointEntity->setStoreName($weightCheckpointTransfer->getStoreNameOrFail());
        $weightCheckpointEntity->setLocaleName($weightCheckpointTransfer->getLocaleNameOrFail());
        $weightCheckpointEntity->setRelevanceWeight($weightCheckpointTransfer->getRelevanceWeightOrFail());
        $weightCheckpointEntity->setSpecificityBlendWeight($weightCheckpointTransfer->getSpecificityBlendWeightOrFail());
        $weightCheckpointEntity->setSpecificityCurveExponent($weightCheckpointTransfer->getSpecificityCurveExponentOrFail());
        $weightCheckpointEntity->setSpecificityWeightExponent($weightCheckpointTransfer->getSpecificityWeightExponentOrFail());
        $weightCheckpointEntity->setSpecificityWeightShiftMagnitude($weightCheckpointTransfer->getSpecificityWeightShiftMagnitudeOrFail());
        $weightCheckpointEntity->setIsSpecificityWeightingEnabled($weightCheckpointTransfer->getIsSpecificityWeightingEnabledOrFail());
        $weightCheckpointEntity->setMetricWeights($mapper->encodeMetricWeights(iterator_to_array($weightCheckpointTransfer->getMetricWeights())));
        $weightCheckpointEntity->save();

        // A fresh transfer, not $weightCheckpointTransfer itself — mapWeightCheckpointEntityToTransfer()
        // APPENDS decoded metric weights via addMetricWeight(), which would duplicate the ones already on
        // the transfer passed in above.
        return $mapper->mapWeightCheckpointEntityToTransfer($weightCheckpointEntity, new SearchRankingWeightCheckpointTransfer());
    }

    /**
     * Upserts by `(idSearchRankingMetric, storeName, localeName)` — at most one config row per
     * metric+store+locale, same "find existing or create new, then overwrite the editable fields" shape
     * as {@see upsertRating()}. Writes exactly the one (store, locale) row named on the transfer — fanning
     * the same config out to every real locale of a store-wide metric is
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneMetricConfigWriterInterface}'s
     * job, one call to this method per locale, not this method's own.
     *
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     */
    public function saveAutoTuneMetricConfig(SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer): SearchRankingAutoTuneMetricConfigTransfer
    {
        $idSearchRankingMetric = $autoTuneMetricConfigTransfer->getIdSearchRankingMetricOrFail();
        $storeName = $autoTuneMetricConfigTransfer->getStoreNameOrFail();
        $localeName = $autoTuneMetricConfigTransfer->getLocaleNameOrFail();

        $autoTuneMetricConfigEntity = $this->getFactory()
            ->createSearchRankingAutoTuneMetricConfigQuery()
            ->filterByFkSearchRankingMetric($idSearchRankingMetric)
            ->filterByStoreName($storeName)
            ->filterByLocaleName($localeName)
            ->findOne();

        if ($autoTuneMetricConfigEntity === null) {
            $autoTuneMetricConfigEntity = new SpySearchRankingAutoTuneMetricConfig();
        }

        $mapper = $this->getFactory()->createSearchRankingOptimizerMapper();
        $mapper->mapAutoTuneMetricConfigTransferToEntity($autoTuneMetricConfigTransfer, $autoTuneMetricConfigEntity);
        $autoTuneMetricConfigEntity->save();

        return $mapper->mapAutoTuneMetricConfigEntityToTransfer($autoTuneMetricConfigEntity, $autoTuneMetricConfigTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer $optimizerRunTransfer
     */
    public function createOptimizerRun(SearchRankingOptimizerRunTransfer $optimizerRunTransfer): SearchRankingOptimizerRunTransfer
    {
        $optimizerRunEntity = new SpySearchRankingOptimizerRun();
        $optimizerRunEntity->setStoreName($optimizerRunTransfer->getStoreNameOrFail());
        $optimizerRunEntity->setLocaleName($optimizerRunTransfer->getLocaleNameOrFail());
        $optimizerRunEntity->setAlgorithm($optimizerRunTransfer->getAlgorithmOrFail());
        $optimizerRunEntity->setTerminationMode($optimizerRunTransfer->getTerminationMode() ?? SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_FIXED_BUDGET);
        $optimizerRunEntity->setWarmStartFraction($optimizerRunTransfer->getWarmStartFraction() ?? 0.0);
        $optimizerRunEntity->setFixedRelevanceWeight($optimizerRunTransfer->getFixedRelevanceWeight());
        $optimizerRunEntity->setFixedSpecificityCurveExponent($optimizerRunTransfer->getFixedSpecificityCurveExponent());
        $optimizerRunEntity->setFixedSpecificityWeightExponent($optimizerRunTransfer->getFixedSpecificityWeightExponent());
        $optimizerRunEntity->setFixedSpecificityWeightShiftMagnitude($optimizerRunTransfer->getFixedSpecificityWeightShiftMagnitude());
        $optimizerRunEntity->setFixedSpecificityBlendWeight($optimizerRunTransfer->getFixedSpecificityBlendWeight());
        $optimizerRunEntity->setFixedMetricWeights(
            $optimizerRunTransfer->getFixedMetricWeights()->count() > 0
                ? $this->getFactory()->createSearchRankingOptimizerMapper()->encodeMetricWeights(iterator_to_array($optimizerRunTransfer->getFixedMetricWeights()))
                : null,
        );
        $optimizerRunEntity->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_QUEUED);
        $optimizerRunEntity->save();

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapOptimizerRunEntityToTransfer($optimizerRunEntity, $optimizerRunTransfer);
    }

    /**
     * @param int $idSearchRankingOptimizerRun
     * @param int $totalCount
     * @param float $baselineScore
     */
    public function startOptimizerRun(int $idSearchRankingOptimizerRun, int $totalCount, float $baselineScore): void
    {
        $optimizerRunEntity = $this->getFactory()
            ->createSearchRankingOptimizerRunQuery()
            ->findOneByIdSearchRankingOptimizerRun($idSearchRankingOptimizerRun);

        if ($optimizerRunEntity === null) {
            return;
        }

        $optimizerRunEntity->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_RUNNING);
        $optimizerRunEntity->setTotalCount($totalCount);
        $optimizerRunEntity->setBaselineScore($baselineScore);
        $optimizerRunEntity->save();
    }

    /**
     * @param int $idSearchRankingOptimizerRun
     * @param int $processedCount
     */
    public function updateOptimizerRunProgress(int $idSearchRankingOptimizerRun, int $processedCount): void
    {
        $optimizerRunEntity = $this->getFactory()
            ->createSearchRankingOptimizerRunQuery()
            ->findOneByIdSearchRankingOptimizerRun($idSearchRankingOptimizerRun);

        if ($optimizerRunEntity === null) {
            return;
        }

        $optimizerRunEntity->setProcessedCount($processedCount);
        $optimizerRunEntity->save();
    }

    /**
     * @param int $idSearchRankingOptimizerRun
     * @param float $bestRelevanceWeight
     * @param array<\Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer> $bestMetricWeightTransfers
     * @param float $bestScore
     * @param float $bestSpecificityBlendWeight
     * @param float $bestSpecificityCurveExponent
     * @param float $bestSpecificityWeightExponent
     * @param float $bestSpecificityWeightShiftMagnitude
     * @param int $generationsUsed
     * @param array<\BlackboxOptimizer\Algorithm\RestartHistoryEntry> $restartHistoryEntries Empty for a run
     *   that didn't have restart-on-plateau enabled -- the normal case.
     */
    public function completeOptimizerRun(
        int $idSearchRankingOptimizerRun,
        float $bestRelevanceWeight,
        array $bestMetricWeightTransfers,
        float $bestScore,
        float $bestSpecificityBlendWeight,
        float $bestSpecificityCurveExponent,
        float $bestSpecificityWeightExponent,
        float $bestSpecificityWeightShiftMagnitude,
        int $generationsUsed,
        array $restartHistoryEntries = [],
    ): void {
        $optimizerRunEntity = $this->getFactory()
            ->createSearchRankingOptimizerRunQuery()
            ->findOneByIdSearchRankingOptimizerRun($idSearchRankingOptimizerRun);

        if ($optimizerRunEntity === null) {
            return;
        }

        $optimizerRunEntity->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE);
        $optimizerRunEntity->setBestRelevanceWeight($bestRelevanceWeight);
        $optimizerRunEntity->setBestMetricWeights($this->getFactory()->createSearchRankingOptimizerMapper()->encodeMetricWeights($bestMetricWeightTransfers));
        $optimizerRunEntity->setBestScore($bestScore);
        $optimizerRunEntity->setBestSpecificityBlendWeight($bestSpecificityBlendWeight);
        $optimizerRunEntity->setBestSpecificityCurveExponent($bestSpecificityCurveExponent);
        $optimizerRunEntity->setBestSpecificityWeightExponent($bestSpecificityWeightExponent);
        $optimizerRunEntity->setBestSpecificityWeightShiftMagnitude($bestSpecificityWeightShiftMagnitude);
        $optimizerRunEntity->setGenerationsUsed($generationsUsed);
        $optimizerRunEntity->setRestartHistory($this->getFactory()->createSearchRankingOptimizerMapper()->encodeRestartHistory($restartHistoryEntries));
        $optimizerRunEntity->setCompletedAt(new DateTime());
        $optimizerRunEntity->save();
    }

    /**
     * @param int $idSearchRankingOptimizerRun
     * @param string $errorMessage
     */
    public function failOptimizerRun(int $idSearchRankingOptimizerRun, string $errorMessage): void
    {
        $optimizerRunEntity = $this->getFactory()
            ->createSearchRankingOptimizerRunQuery()
            ->findOneByIdSearchRankingOptimizerRun($idSearchRankingOptimizerRun);

        if ($optimizerRunEntity === null) {
            return;
        }

        $optimizerRunEntity->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_FAILED);
        $optimizerRunEntity->setErrorMessage($errorMessage);
        $optimizerRunEntity->save();
    }

    /**
     * @param int $idSearchRankingOptimizerRun
     */
    public function markOptimizerRunApplied(int $idSearchRankingOptimizerRun): void
    {
        $optimizerRunEntity = $this->getFactory()
            ->createSearchRankingOptimizerRunQuery()
            ->findOneByIdSearchRankingOptimizerRun($idSearchRankingOptimizerRun);

        if ($optimizerRunEntity === null) {
            return;
        }

        $optimizerRunEntity->setAppliedAt(new DateTime());
        $optimizerRunEntity->save();
    }
}
