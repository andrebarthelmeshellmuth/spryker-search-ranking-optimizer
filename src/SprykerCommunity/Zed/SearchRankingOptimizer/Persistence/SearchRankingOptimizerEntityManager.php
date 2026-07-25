<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Persistence;

use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibration;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationSearchTerm;
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
}
