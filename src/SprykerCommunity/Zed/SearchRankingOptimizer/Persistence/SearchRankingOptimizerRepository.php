<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Persistence;

use Generated\Shared\Transfer\SearchRankingCalibrationSearchTermTransfer;
use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use Propel\Runtime\ActiveQuery\Criteria;
use Spryker\Zed\Kernel\Persistence\AbstractRepository;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerPersistenceFactory getFactory()
 */
class SearchRankingOptimizerRepository extends AbstractRepository implements SearchRankingOptimizerRepositoryInterface
{
    /**
     * @return array<\Generated\Shared\Transfer\SearchRankingCalibrationTransfer>
     */
    public function getUploadedCalibrations(): array
    {
        $calibrationEntities = $this->getFactory()
            ->createSearchRankingCalibrationQuery()
            ->filterByStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED)
            ->orderByIdSearchRankingCalibration(Criteria::DESC)
            ->find();

        $mapper = $this->getFactory()->createSearchRankingOptimizerMapper();
        $calibrationTransfers = [];

        foreach ($calibrationEntities as $calibrationEntity) {
            $calibrationTransfers[] = $mapper->mapCalibrationEntityToTransfer($calibrationEntity, new SearchRankingCalibrationTransfer());
        }

        return $calibrationTransfers;
    }

    /**
     * @param int $idSearchRankingCalibration
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer|null
     */
    public function findCalibrationWithSearchTerms(int $idSearchRankingCalibration): ?SearchRankingCalibrationTransfer
    {
        $calibrationEntity = $this->getFactory()
            ->createSearchRankingCalibrationQuery()
            ->findOneByIdSearchRankingCalibration($idSearchRankingCalibration);

        if ($calibrationEntity === null) {
            return null;
        }

        $mapper = $this->getFactory()->createSearchRankingOptimizerMapper();
        $calibrationTransfer = $mapper->mapCalibrationEntityToTransfer($calibrationEntity, new SearchRankingCalibrationTransfer());

        $searchTermEntities = $this->getFactory()
            ->createSearchRankingCalibrationSearchTermQuery()
            ->filterByFkSearchRankingCalibration($idSearchRankingCalibration)
            ->orderByIdSearchRankingCalibrationSearchTerm()
            ->find();

        foreach ($searchTermEntities as $searchTermEntity) {
            $calibrationTransfer->addSearchTerm(
                $mapper->mapCalibrationSearchTermEntityToTransfer($searchTermEntity, new SearchRankingCalibrationSearchTermTransfer()),
            );
        }

        return $calibrationTransfer;
    }

    /**
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer|null
     */
    public function findLatestCalculatedCalibration(): ?SearchRankingCalibrationTransfer
    {
        $calibrationEntity = $this->getFactory()
            ->createSearchRankingCalibrationQuery()
            ->filterByStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATED)
            ->orderByCalculatedAt(Criteria::DESC)
            ->findOne();

        if ($calibrationEntity === null) {
            return null;
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapCalibrationEntityToTransfer($calibrationEntity, new SearchRankingCalibrationTransfer());
    }

    /**
     * The run `ScoreCalibrator::calculate()` is currently working through, if any — at most one at a
     * time by design (see {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\ScoreCalibrator::runNextCalibration()}'s
     * own skip-older-uploads step). Backs the Calibration page's live progress counter; polled, so
     * deliberately cheap — no search terms loaded.
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer|null
     */
    public function findCalibrationInProgress(): ?SearchRankingCalibrationTransfer
    {
        $calibrationEntity = $this->getFactory()
            ->createSearchRankingCalibrationQuery()
            ->filterByStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATING)
            ->findOne();

        if ($calibrationEntity === null) {
            return null;
        }

        return $this->getFactory()
            ->createSearchRankingOptimizerMapper()
            ->mapCalibrationEntityToTransfer($calibrationEntity, new SearchRankingCalibrationTransfer());
    }

    /**
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryTransfer|null
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
     *
     * @return \Generated\Shared\Transfer\SearchRankingQueryTransfer|null
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
}
