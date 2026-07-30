<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business;

use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use Spryker\Zed\Kernel\Business\AbstractFacade;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerBusinessFactory getFactory()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface getRepository()
 */
class SearchRankingOptimizerFacade extends AbstractFacade implements SearchRankingOptimizerFacadeInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param int $relevantProductCount
     * @param string $storeName
     * @param string $localeName
     * @param string $csvContent
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer
     */
    public function createCalibration(int $relevantProductCount, string $storeName, string $localeName, string $csvContent): SearchRankingCalibrationTransfer
    {
        return $this->getFactory()->createCalibrationUploadHandler()->createCalibration($relevantProductCount, $storeName, $localeName, $csvContent);
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
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\InvalidRatingTypeException
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
}
