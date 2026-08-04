<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration;

use Generated\Shared\Transfer\SearchRankingCalibrationSearchTermTransfer;
use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

class CalibrationUploadHandler implements CalibrationUploadHandlerInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CsvSearchTermParserInterface $csvSearchTermParser
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     */
    public function __construct(
        protected CsvSearchTermParserInterface $csvSearchTermParser,
        protected SearchRankingOptimizerRepositoryInterface $repository,
        protected SearchRankingOptimizerEntityManagerInterface $entityManager,
    ) {
    }

    /**
     * {@inheritDoc}
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
        $calibrationTransfer = (new SearchRankingCalibrationTransfer())
            ->setCalibrationType($calibrationType)
            ->setRelevantProductCount($relevantProductCount)
            ->setStoreName($storeName)
            ->setLocaleName($localeName)
            ->setStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);

        $searchTerms = $csvContent !== null
            ? $this->csvSearchTermParser->parse($csvContent)
            : $this->repository->findDistinctSearchTermsByStoreLocale($storeName, $localeName);

        foreach ($searchTerms as $searchTerm) {
            $calibrationTransfer->addSearchTerm(
                (new SearchRankingCalibrationSearchTermTransfer())->setSearchTerm($searchTerm),
            );
        }

        return $this->entityManager->createCalibration($calibrationTransfer);
    }
}
