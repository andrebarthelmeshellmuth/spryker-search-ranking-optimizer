<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Persistence;

use DateTime;
use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfig;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibration;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationSearchTerm;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingEvaluation;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingOptimizerRun;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRating;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingWeightCheckpoint;
use Spryker\Zed\Kernel\Persistence\AbstractEntityManager;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerPersistenceFactory getFactory()
 */
class SearchRankingOptimizerEntityManager extends AbstractEntityManager implements SearchRankingOptimizerEntityManagerInterface
{
    /**
     * @param \Generated\Shared\Transfer\SearchRankingCalibrationTransfer $calibrationTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer
     */
    public function createCalibration(SearchRankingCalibrationTransfer $calibrationTransfer): SearchRankingCalibrationTransfer
    {
        $calibrationEntity = new SpySearchRankingCalibration();
        $calibrationEntity->setRelevantProductCount($calibrationTransfer->getRelevantProductCountOrFail());
        $calibrationEntity->setStoreName($calibrationTransfer->getStoreNameOrFail());
        $calibrationEntity->setLocaleName($calibrationTransfer->getLocaleNameOrFail());
        $calibrationEntity->setStatus($calibrationTransfer->getStatus() ?? SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);
        // Known up front (the caller already populated every search term before calling this) — the
        // denominator half of the live "X/Y processed" counter never needs to change again after this.
        $calibrationEntity->setTotalCount(count($calibrationTransfer->getSearchTerms()));
        $calibrationEntity->save();

        foreach ($calibrationTransfer->getSearchTerms() as $searchTermTransfer) {
            $searchTermEntity = new SpySearchRankingCalibrationSearchTerm();
            $searchTermEntity->setFkSearchRankingCalibration($calibrationEntity->getIdSearchRankingCalibration());
            $searchTermEntity->setSearchTerm($searchTermTransfer->getSearchTermOrFail());
            $searchTermEntity->save();

            $searchTermTransfer
                ->setIdSearchRankingCalibrationSearchTerm($searchTermEntity->getIdSearchRankingCalibrationSearchTerm())
                ->setFkSearchRankingCalibration($calibrationEntity->getIdSearchRankingCalibration());
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapCalibrationEntityToTransfer($calibrationEntity, $calibrationTransfer);
    }

    /**
     * @param int $idSearchRankingCalibration
     * @param string $status
     *
     * @return void
     */
    public function updateCalibrationStatus(int $idSearchRankingCalibration, string $status): void
    {
        $calibrationEntity = $this->getFactory()
            ->createSearchRankingCalibrationQuery()
            ->findOneByIdSearchRankingCalibration($idSearchRankingCalibration);

        if ($calibrationEntity === null) {
            return;
        }

        $calibrationEntity->setStatus($status);
        $calibrationEntity->save();
    }

    /**
     * @param int $idSearchRankingCalibrationSearchTerm
     * @param int $productsFound
     * @param array<float> $scores
     *
     * @return void
     */
    public function saveCalibrationSearchTermResult(int $idSearchRankingCalibrationSearchTerm, int $productsFound, array $scores): void
    {
        $searchTermEntity = $this->getFactory()
            ->createSearchRankingCalibrationSearchTermQuery()
            ->findOneByIdSearchRankingCalibrationSearchTerm($idSearchRankingCalibrationSearchTerm);

        if ($searchTermEntity === null) {
            return;
        }

        $searchTermEntity->setProductsFound($productsFound);
        $searchTermEntity->setScores($this->getFactory()->createSearchRankingOptimizerMapper()->implodeScores($scores));
        $searchTermEntity->save();
    }

    /**
     * Called once per search term inside {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\ScoreCalibrator::calculate()}'s
     * own loop — the calculation run is a single sequential process, so a plain read-modify-write (same
     * pattern every other method here uses) needs no extra locking.
     *
     * @param int $idSearchRankingCalibration
     *
     * @return void
     */
    public function incrementCalibrationProcessedCount(int $idSearchRankingCalibration): void
    {
        $calibrationEntity = $this->getFactory()
            ->createSearchRankingCalibrationQuery()
            ->findOneByIdSearchRankingCalibration($idSearchRankingCalibration);

        if ($calibrationEntity === null) {
            return;
        }

        $calibrationEntity->setProcessedCount($calibrationEntity->getProcessedCount() + 1);
        $calibrationEntity->save();
    }

    /**
     * @param int $idSearchRankingCalibration
     * @param \Generated\Shared\Transfer\SearchRankingCalibrationTransfer $statisticsTransfer
     *
     * @return void
     */
    public function saveCalibrationStatistics(int $idSearchRankingCalibration, SearchRankingCalibrationTransfer $statisticsTransfer): void
    {
        $calibrationEntity = $this->getFactory()
            ->createSearchRankingCalibrationQuery()
            ->findOneByIdSearchRankingCalibration($idSearchRankingCalibration);

        if ($calibrationEntity === null) {
            return;
        }

        $calibrationEntity->setComputedK($statisticsTransfer->getComputedK());
        $calibrationEntity->setScoreMin($statisticsTransfer->getScoreMin());
        $calibrationEntity->setScoreMax($statisticsTransfer->getScoreMax());
        $calibrationEntity->setScoreMean($statisticsTransfer->getScoreMean());
        $calibrationEntity->setScoreMedian($statisticsTransfer->getScoreMedian());
        $calibrationEntity->setScoreP25($statisticsTransfer->getScoreP25());
        $calibrationEntity->setScoreP75($statisticsTransfer->getScoreP75());
        $calibrationEntity->setSampleCount($statisticsTransfer->getSampleCount());
        $calibrationEntity->setCalculatedAt(date('c'));
        $calibrationEntity->setStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATED);
        $calibrationEntity->save();
    }

    /**
     * @param int $idSearchRankingCalibration
     * @param string $errorMessage
     *
     * @return void
     */
    public function markCalibrationFailed(int $idSearchRankingCalibration, string $errorMessage): void
    {
        $calibrationEntity = $this->getFactory()
            ->createSearchRankingCalibrationQuery()
            ->findOneByIdSearchRankingCalibration($idSearchRankingCalibration);

        if ($calibrationEntity === null) {
            return;
        }

        $calibrationEntity->setStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_FAILED);
        $calibrationEntity->setErrorMessage($errorMessage);
        $calibrationEntity->save();
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingQueryTransfer $queryTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryTransfer
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryRatingTransfer
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
     *
     * @return void
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
     *
     * @return \Generated\Shared\Transfer\SearchRankingEvaluationTransfer
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
     *
     * @return \Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer
     */
    public function createWeightCheckpoint(SearchRankingWeightCheckpointTransfer $weightCheckpointTransfer): SearchRankingWeightCheckpointTransfer
    {
        $mapper = $this->getFactory()->createSearchRankingOptimizerMapper();

        $weightCheckpointEntity = new SpySearchRankingWeightCheckpoint();
        $weightCheckpointEntity->setSource($weightCheckpointTransfer->getSourceOrFail());
        $weightCheckpointEntity->setRelevanceWeight($weightCheckpointTransfer->getRelevanceWeightOrFail());
        $weightCheckpointEntity->setEntropyProbeResultSize($weightCheckpointTransfer->getEntropyProbeResultSizeOrFail());
        $weightCheckpointEntity->setEntropyWeightExponent($weightCheckpointTransfer->getEntropyWeightExponentOrFail());
        $weightCheckpointEntity->setEntropyWeightShiftMagnitude($weightCheckpointTransfer->getEntropyWeightShiftMagnitudeOrFail());
        $weightCheckpointEntity->setIsEntropyWeightingEnabled($weightCheckpointTransfer->getIsEntropyWeightingEnabledOrFail());
        $weightCheckpointEntity->setMetricWeights($mapper->encodeMetricWeights(iterator_to_array($weightCheckpointTransfer->getMetricWeights())));
        $weightCheckpointEntity->save();

        // A fresh transfer, not $weightCheckpointTransfer itself — mapWeightCheckpointEntityToTransfer()
        // APPENDS decoded metric weights via addMetricWeight(), which would duplicate the ones already on
        // the transfer passed in above.
        return $mapper->mapWeightCheckpointEntityToTransfer($weightCheckpointEntity, new SearchRankingWeightCheckpointTransfer());
    }

    /**
     * Upserts by `idSearchRankingMetric` — at most one config row per metric, same "find existing or
     * create new, then overwrite the editable fields" shape as {@see upsertRating()}.
     *
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer
     */
    public function saveAutoTuneMetricConfig(SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer): SearchRankingAutoTuneMetricConfigTransfer
    {
        $idSearchRankingMetric = $autoTuneMetricConfigTransfer->getIdSearchRankingMetricOrFail();

        $autoTuneMetricConfigEntity = $this->getFactory()
            ->createSearchRankingAutoTuneMetricConfigQuery()
            ->filterByFkSearchRankingMetric($idSearchRankingMetric)
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
     *
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer
     */
    public function createOptimizerRun(SearchRankingOptimizerRunTransfer $optimizerRunTransfer): SearchRankingOptimizerRunTransfer
    {
        $optimizerRunEntity = new SpySearchRankingOptimizerRun();
        $optimizerRunEntity->setStoreName($optimizerRunTransfer->getStoreNameOrFail());
        $optimizerRunEntity->setLocaleName($optimizerRunTransfer->getLocaleNameOrFail());
        $optimizerRunEntity->setAlgorithm($optimizerRunTransfer->getAlgorithmOrFail());
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
     */
    public function completeOptimizerRun(
        int $idSearchRankingOptimizerRun,
        float $bestRelevanceWeight,
        array $bestMetricWeightTransfers,
        float $bestScore,
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
        $optimizerRunEntity->setCompletedAt(new DateTime());
        $optimizerRunEntity->save();
    }

    /**
     * @param int $idSearchRankingOptimizerRun
     * @param string $errorMessage
     *
     * @return void
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
     *
     * @return void
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
