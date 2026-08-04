<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business;

use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Generated\Shared\Transfer\SearchRankingAutoTuneResultTransfer;
use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer;
use Spryker\Zed\Kernel\Business\AbstractFacade;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerBusinessFactory getFactory()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface getRepository()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface getEntityManager()
 */
class SearchRankingOptimizerFacade extends AbstractFacade implements SearchRankingOptimizerFacadeInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param string $calibrationType
     * @param int $relevantProductCount
     * @param string $storeName
     * @param string $localeName
     * @param string|null $csvContent
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer
     */
    public function createCalibration(
        string $calibrationType,
        int $relevantProductCount,
        string $storeName,
        string $localeName,
        ?string $csvContent = null,
    ): SearchRankingCalibrationTransfer {
        return $this->getFactory()->createCalibrationUploadHandler()->createCalibration($calibrationType, $relevantProductCount, $storeName, $localeName, $csvContent);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer|null
     */
    public function runNextCalibration(): ?SearchRankingCalibrationTransfer
    {
        return $this->getFactory()->createScoreCalibrator()->runNextCalibration();
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer|null
     */
    public function findLatestCalculatedCalibration(): ?SearchRankingCalibrationTransfer
    {
        return $this->getRepository()->findLatestCalculatedCalibration();
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer|null
     */
    public function findCalibrationInProgress(): ?SearchRankingCalibrationTransfer
    {
        return $this->getRepository()->findCalibrationInProgress();
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\InvalidRatingTypeException
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\ProductNotInSearchResultsException
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryRatingTransfer
     */
    public function submitProductRelevanceJudgment(SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer): SearchRankingQueryRatingTransfer
    {
        return $this->getFactory()->createProductRelevanceJudgmentWriter()->submitJudgment($requestTransfer);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @return void
     */
    public function clearProductRelevanceJudgment(SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer): void
    {
        $this->getFactory()->createProductRelevanceJudgmentWriter()->clearJudgment($requestTransfer);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingQueryTransfer>
     */
    public function getQueries(): array
    {
        return $this->getRepository()->findAllQueriesOrderedByUpdatedAt();
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param int $idSearchRankingQuery
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryTransfer|null
     */
    public function findQueryById(int $idSearchRankingQuery): ?SearchRankingQueryTransfer
    {
        return $this->getRepository()->findQueryById($idSearchRankingQuery);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param int $idSearchRankingQuery
     * @param float $importanceWeight
     *
     * @return void
     */
    public function updateQueryImportanceWeight(int $idSearchRankingQuery, float $importanceWeight): void
    {
        $this->getFactory()->createQueryImportanceWeightUpdater()->update($idSearchRankingQuery, $importanceWeight);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return \Generated\Shared\Transfer\SearchRankingEvaluationTransfer|null
     */
    public function runRankEvaluation(string $storeName, string $localeName): ?SearchRankingEvaluationTransfer
    {
        return $this->getFactory()->createRankEvaluationRunner()->evaluate($storeName, $localeName);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return \Generated\Shared\Transfer\SearchRankingEvaluationTransfer|null
     */
    public function findLatestEvaluation(string $storeName, string $localeName): ?SearchRankingEvaluationTransfer
    {
        return $this->getRepository()->findLatestEvaluation($storeName, $localeName);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingEvaluationTransfer>
     */
    public function findEvaluationHistory(string $storeName, string $localeName): array
    {
        return $this->getRepository()->findEvaluationHistoryByStoreLocale($storeName, $localeName);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param string $source
     *
     * @return \Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer
     */
    public function recordWeightCheckpoint(string $source, string $storeName, string $localeName): SearchRankingWeightCheckpointTransfer
    {
        return $this->getFactory()->createWeightCheckpointRecorder()->record($source, $storeName, $localeName);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param int $idSearchRankingWeightCheckpoint
     *
     * @return \Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer|null
     */
    public function restoreWeightCheckpoint(int $idSearchRankingWeightCheckpoint, string $storeName, string $localeName): ?SearchRankingWeightCheckpointTransfer
    {
        return $this->getFactory()->createWeightCheckpointRestorer()->restore($idSearchRankingWeightCheckpoint, $storeName, $localeName);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingWeightCheckpointTransfer>
     */
    public function findWeightCheckpointHistory(): array
    {
        return $this->getRepository()->findWeightCheckpointHistory();
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param int $idSearchRankingMetric
     *
     * @return \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer|null
     */
    public function findAutoTuneMetricConfigByMetricId(int $idSearchRankingMetric): ?SearchRankingAutoTuneMetricConfigTransfer
    {
        return $this->getRepository()->findAutoTuneMetricConfigByMetricId($idSearchRankingMetric);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer>
     */
    public function findAutoTuneMetricConfigsWithThresholdSet(): array
    {
        return $this->getRepository()->findAutoTuneMetricConfigsWithThresholdSet();
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer
     */
    public function saveAutoTuneMetricConfig(
        SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer,
    ): SearchRankingAutoTuneMetricConfigTransfer {
        return $this->getEntityManager()->saveAutoTuneMetricConfig($autoTuneMetricConfigTransfer);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return \Generated\Shared\Transfer\SearchRankingAutoTuneResultTransfer
     */
    public function runAutoTune(): SearchRankingAutoTuneResultTransfer
    {
        return $this->getFactory()->createAutoTuneRunner()->run();
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     * @param string $algorithm
     *
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer
     */
    public function queueOptimizationRun(string $storeName, string $localeName, string $algorithm): SearchRankingOptimizerRunTransfer
    {
        return $this->getEntityManager()->createOptimizerRun(
            (new SearchRankingOptimizerRunTransfer())
                ->setStoreName($storeName)
                ->setLocaleName($localeName)
                ->setAlgorithm($algorithm),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer|null
     */
    public function runNextOptimization(): ?SearchRankingOptimizerRunTransfer
    {
        return $this->getFactory()->createOptimizationRunner()->runNext();
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer|null
     */
    public function findOptimizerRunInProgress(): ?SearchRankingOptimizerRunTransfer
    {
        return $this->getRepository()->findOptimizerRunInProgress();
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param string $storeName
     * @param string $localeName
     *
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer|null
     */
    public function findLatestOptimizerRunByStoreLocale(string $storeName, string $localeName): ?SearchRankingOptimizerRunTransfer
    {
        return $this->getRepository()->findLatestOptimizerRunByStoreLocale($storeName, $localeName);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param int $idSearchRankingOptimizerRun
     *
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer|null
     */
    public function applyOptimizationRun(int $idSearchRankingOptimizerRun): ?SearchRankingOptimizerRunTransfer
    {
        return $this->getFactory()->createOptimizationApplier()->apply($idSearchRankingOptimizerRun);
    }
}
