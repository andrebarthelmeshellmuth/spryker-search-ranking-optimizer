<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration;

use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationSearchTermTransfer;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\NoSearchTermsAvailableException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

class SaturationPointCalibrationUploadHandler implements SaturationPointCalibrationUploadHandlerInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\CsvSearchTermParserInterface $csvSearchTermParser
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
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\NoSearchTermsAvailableException
     */
    public function createCalibration(
        string $calibrationType,
        int $relevantProductCount,
        string $storeName,
        string $localeName,
        ?string $csvContent = null,
    ): SearchRankingSaturationPointCalibrationTransfer {
        $searchTerms = $csvContent !== null
            ? $this->csvSearchTermParser->parse($csvContent)
            : $this->repository->findDistinctSearchTermsByStoreLocale($storeName, $localeName);

        if ($searchTerms === []) {
            throw new NoSearchTermsAvailableException(sprintf(
                'No rated search terms are available yet for store "%s", locale "%s" -- rate some products via the search-debug overlay first, or upload a CSV.',
                $storeName,
                $localeName,
            ));
        }

        $calibrationTransfer = (new SearchRankingSaturationPointCalibrationTransfer())
            ->setCalibrationType($calibrationType)
            ->setRelevantProductCount($relevantProductCount)
            ->setStoreName($storeName)
            ->setLocaleName($localeName)
            ->setStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);

        foreach ($searchTerms as $searchTerm) {
            $calibrationTransfer->addSearchTerm(
                (new SearchRankingSaturationPointCalibrationSearchTermTransfer())->setSearchTerm($searchTerm),
            );
        }

        return $this->entityManager->createCalibration($calibrationTransfer);
    }
}
