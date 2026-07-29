<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\Propel\Mapper;

use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Generated\Shared\Transfer\SearchRankingCalibrationSearchTermTransfer;
use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfig;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibration;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationSearchTerm;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingEvaluation;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingOptimizerRun;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRating;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingWeightCheckpoint;

class SearchRankingOptimizerMapper
{
    /**
     * @param \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibration $calibrationEntity
     * @param \Generated\Shared\Transfer\SearchRankingCalibrationTransfer $calibrationTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer
     */
    public function mapCalibrationEntityToTransfer(
        SpySearchRankingCalibration $calibrationEntity,
        SearchRankingCalibrationTransfer $calibrationTransfer,
    ): SearchRankingCalibrationTransfer {
        return $calibrationTransfer
            ->setIdSearchRankingCalibration($calibrationEntity->getIdSearchRankingCalibration())
            ->setRelevantProductCount($calibrationEntity->getRelevantProductCount())
            ->setStoreName($calibrationEntity->getStoreName())
            ->setLocaleName($calibrationEntity->getLocaleName())
            ->setStatus($calibrationEntity->getStatus())
            ->setComputedK($calibrationEntity->getComputedK())
            ->setScoreMin($calibrationEntity->getScoreMin())
            ->setScoreMax($calibrationEntity->getScoreMax())
            ->setScoreMean($calibrationEntity->getScoreMean())
            ->setScoreMedian($calibrationEntity->getScoreMedian())
            ->setScoreP25($calibrationEntity->getScoreP25())
            ->setScoreP75($calibrationEntity->getScoreP75())
            ->setSampleCount($calibrationEntity->getSampleCount())
            ->setCalculatedAt($calibrationEntity->getCalculatedAt()?->format(DATE_ATOM))
            ->setErrorMessage($calibrationEntity->getErrorMessage())
            ->setCreatedAt($calibrationEntity->getCreatedAt()?->format(DATE_ATOM))
            ->setTotalCount($calibrationEntity->getTotalCount())
            ->setProcessedCount($calibrationEntity->getProcessedCount());
    }

    /**
     * @param \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationSearchTerm $searchTermEntity
     * @param \Generated\Shared\Transfer\SearchRankingCalibrationSearchTermTransfer $searchTermTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationSearchTermTransfer
     */
    public function mapCalibrationSearchTermEntityToTransfer(
        SpySearchRankingCalibrationSearchTerm $searchTermEntity,
        SearchRankingCalibrationSearchTermTransfer $searchTermTransfer,
    ): SearchRankingCalibrationSearchTermTransfer {
        return $searchTermTransfer
            ->setIdSearchRankingCalibrationSearchTerm($searchTermEntity->getIdSearchRankingCalibrationSearchTerm())
            ->setFkSearchRankingCalibration($searchTermEntity->getFkSearchRankingCalibration())
            ->setSearchTerm($searchTermEntity->getSearchTerm())
            ->setProductsFound($searchTermEntity->getProductsFound())
            ->setScores($this->explodeScores($searchTermEntity->getScores()));
    }

    /**
     * @param string|null $scores
     *
     * @return array<float>
     */
    protected function explodeScores(?string $scores): array
    {
        if ($scores === null || $scores === '') {
            return [];
        }

        return array_map('floatval', explode(',', $scores));
    }

    /**
     * @param array<float> $scores
     *
     * @return string|null
     */
    public function implodeScores(array $scores): ?string
    {
        return $scores === [] ? null : implode(',', $scores);
    }

    /**
     * @param \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQuery $queryEntity
     * @param \Generated\Shared\Transfer\SearchRankingQueryTransfer $queryTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryTransfer
     */
    public function mapQueryEntityToTransfer(
        SpySearchRankingQuery $queryEntity,
        SearchRankingQueryTransfer $queryTransfer,
    ): SearchRankingQueryTransfer {
        return $queryTransfer
            ->setIdSearchRankingQuery($queryEntity->getIdSearchRankingQuery())
            ->setSearchTerm($queryEntity->getSearchTerm())
            ->setStoreName($queryEntity->getStoreName())
            ->setLocaleName($queryEntity->getLocaleName())
            ->setImportanceWeight($queryEntity->getImportanceWeight())
            ->setCreatedAt($queryEntity->getCreatedAt()?->format(DATE_ATOM))
            ->setUpdatedAt($queryEntity->getUpdatedAt()?->format(DATE_ATOM));
    }

    /**
     * @param \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRating $ratingEntity
     * @param \Generated\Shared\Transfer\SearchRankingQueryRatingTransfer $ratingTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryRatingTransfer
     */
    public function mapQueryRatingEntityToTransfer(
        SpySearchRankingQueryRating $ratingEntity,
        SearchRankingQueryRatingTransfer $ratingTransfer,
    ): SearchRankingQueryRatingTransfer {
        return $ratingTransfer
            ->setIdSearchRankingQueryRating($ratingEntity->getIdSearchRankingQueryRating())
            ->setFkSearchRankingQuery($ratingEntity->getFkSearchRankingQuery())
            ->setCustomerReference($ratingEntity->getCustomerReference())
            ->setFkProductAbstract($ratingEntity->getFkProductAbstract())
            ->setRatingType($ratingEntity->getRatingType())
            ->setCreatedAt($ratingEntity->getCreatedAt()?->format(DATE_ATOM))
            ->setUpdatedAt($ratingEntity->getUpdatedAt()?->format(DATE_ATOM));
    }

    /**
     * @param \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingEvaluation $evaluationEntity
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationTransfer $evaluationTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingEvaluationTransfer
     */
    public function mapEvaluationEntityToTransfer(
        SpySearchRankingEvaluation $evaluationEntity,
        SearchRankingEvaluationTransfer $evaluationTransfer,
    ): SearchRankingEvaluationTransfer {
        return $evaluationTransfer
            ->setIdSearchRankingEvaluation($evaluationEntity->getIdSearchRankingEvaluation())
            ->setStoreName($evaluationEntity->getStoreName())
            ->setLocaleName($evaluationEntity->getLocaleName())
            ->setMetricScore($evaluationEntity->getMetricScore())
            ->setQueryCount($evaluationEntity->getQueryCount())
            ->setCreatedAt($evaluationEntity->getCreatedAt()?->format(DATE_ATOM));
    }

    /**
     * @param \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingWeightCheckpoint $weightCheckpointEntity
     * @param \Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer $weightCheckpointTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer
     */
    public function mapWeightCheckpointEntityToTransfer(
        SpySearchRankingWeightCheckpoint $weightCheckpointEntity,
        SearchRankingWeightCheckpointTransfer $weightCheckpointTransfer,
    ): SearchRankingWeightCheckpointTransfer {
        $weightCheckpointTransfer
            ->setIdSearchRankingWeightCheckpoint($weightCheckpointEntity->getIdSearchRankingWeightCheckpoint())
            ->setSource($weightCheckpointEntity->getSource())
            ->setRelevanceWeight($weightCheckpointEntity->getRelevanceWeight())
            ->setEntropyProbeResultSize($weightCheckpointEntity->getEntropyProbeResultSize())
            ->setEntropyWeightExponent($weightCheckpointEntity->getEntropyWeightExponent())
            ->setEntropyWeightShiftMagnitude($weightCheckpointEntity->getEntropyWeightShiftMagnitude())
            ->setIsEntropyWeightingEnabled($weightCheckpointEntity->getIsEntropyWeightingEnabled())
            ->setCreatedAt($weightCheckpointEntity->getCreatedAt()?->format(DATE_ATOM));

        foreach ($this->decodeMetricWeights($weightCheckpointEntity->getMetricWeights()) as $metricWeightTransfer) {
            $weightCheckpointTransfer->addMetricWeight($metricWeightTransfer);
        }

        return $weightCheckpointTransfer;
    }

    /**
     * @param array<\Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer> $metricWeightTransfers
     *
     * @return string
     */
    public function encodeMetricWeights(array $metricWeightTransfers): string
    {
        $metricWeights = [];

        foreach ($metricWeightTransfers as $metricWeightTransfer) {
            $metricWeights[] = [
                'idSearchRankingMetric' => $metricWeightTransfer->getIdSearchRankingMetricOrFail(),
                'name' => $metricWeightTransfer->getNameOrFail(),
                'weight' => $metricWeightTransfer->getWeightOrFail(),
            ];
        }

        return (string)json_encode($metricWeights);
    }

    /**
     * @param string $metricWeightsJson
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer>
     */
    protected function decodeMetricWeights(string $metricWeightsJson): array
    {
        $decoded = json_decode($metricWeightsJson, true);
        $metricWeightTransfers = [];

        foreach ((is_array($decoded) ? $decoded : []) as $metricWeight) {
            $metricWeightTransfers[] = (new SearchRankingWeightCheckpointMetricWeightTransfer())
                ->setIdSearchRankingMetric((int)$metricWeight['idSearchRankingMetric'])
                ->setName((string)$metricWeight['name'])
                ->setWeight((float)$metricWeight['weight']);
        }

        return $metricWeightTransfers;
    }

    /**
     * @param \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfig $autoTuneMetricConfigEntity
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer
     */
    public function mapAutoTuneMetricConfigEntityToTransfer(
        SpySearchRankingAutoTuneMetricConfig $autoTuneMetricConfigEntity,
        SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer,
    ): SearchRankingAutoTuneMetricConfigTransfer {
        return $autoTuneMetricConfigTransfer
            ->setIdSearchRankingAutoTuneMetricConfig($autoTuneMetricConfigEntity->getIdSearchRankingAutoTuneMetricConfig())
            ->setIdSearchRankingMetric($autoTuneMetricConfigEntity->getFkSearchRankingMetric())
            ->setAutoTuneThreshold($autoTuneMetricConfigEntity->getAutoTuneThreshold())
            ->setIsAutoUpdateEnabled($autoTuneMetricConfigEntity->getIsAutoUpdateEnabled())
            ->setAutoUpdateScope($autoTuneMetricConfigEntity->getAutoUpdateScope())
            ->setIsNotifyEnabled($autoTuneMetricConfigEntity->getIsNotifyEnabled());
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     * @param \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfig $autoTuneMetricConfigEntity
     *
     * @return \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfig
     */
    public function mapAutoTuneMetricConfigTransferToEntity(
        SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer,
        SpySearchRankingAutoTuneMetricConfig $autoTuneMetricConfigEntity,
    ): SpySearchRankingAutoTuneMetricConfig {
        $autoTuneMetricConfigEntity->setFkSearchRankingMetric($autoTuneMetricConfigTransfer->getIdSearchRankingMetricOrFail());
        $autoTuneMetricConfigEntity->setAutoTuneThreshold($autoTuneMetricConfigTransfer->getAutoTuneThreshold());
        $autoTuneMetricConfigEntity->setIsAutoUpdateEnabled($autoTuneMetricConfigTransfer->getIsAutoUpdateEnabled() ?? false);
        $autoTuneMetricConfigEntity->setAutoUpdateScope($autoTuneMetricConfigTransfer->getAutoUpdateScopeOrFail());
        $autoTuneMetricConfigEntity->setIsNotifyEnabled($autoTuneMetricConfigTransfer->getIsNotifyEnabled() ?? false);

        return $autoTuneMetricConfigEntity;
    }

    /**
     * @param \Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingOptimizerRun $optimizerRunEntity
     * @param \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer $optimizerRunTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer
     */
    public function mapOptimizerRunEntityToTransfer(
        SpySearchRankingOptimizerRun $optimizerRunEntity,
        SearchRankingOptimizerRunTransfer $optimizerRunTransfer,
    ): SearchRankingOptimizerRunTransfer {
        $optimizerRunTransfer
            ->setIdSearchRankingOptimizerRun($optimizerRunEntity->getIdSearchRankingOptimizerRun())
            ->setStoreName($optimizerRunEntity->getStoreName())
            ->setLocaleName($optimizerRunEntity->getLocaleName())
            ->setAlgorithm($optimizerRunEntity->getAlgorithm())
            ->setStatus($optimizerRunEntity->getStatus())
            ->setTotalCount($optimizerRunEntity->getTotalCount())
            ->setProcessedCount($optimizerRunEntity->getProcessedCount())
            ->setBaselineScore($optimizerRunEntity->getBaselineScore())
            ->setBestRelevanceWeight($optimizerRunEntity->getBestRelevanceWeight())
            ->setBestScore($optimizerRunEntity->getBestScore())
            ->setCompletedAt($optimizerRunEntity->getCompletedAt()?->format(DATE_ATOM))
            ->setErrorMessage($optimizerRunEntity->getErrorMessage())
            ->setAppliedAt($optimizerRunEntity->getAppliedAt()?->format(DATE_ATOM))
            ->setCreatedAt($optimizerRunEntity->getCreatedAt()?->format(DATE_ATOM))
            ->setUpdatedAt($optimizerRunEntity->getUpdatedAt()?->format(DATE_ATOM));

        $bestMetricWeightsJson = $optimizerRunEntity->getBestMetricWeights();

        if ($bestMetricWeightsJson !== null) {
            foreach ($this->decodeMetricWeights($bestMetricWeightsJson) as $metricWeightTransfer) {
                $optimizerRunTransfer->addBestMetricWeight($metricWeightTransfer);
            }
        }

        return $optimizerRunTransfer;
    }
}
