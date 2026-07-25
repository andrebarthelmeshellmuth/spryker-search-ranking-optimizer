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

class CalibrationUploadHandler implements CalibrationUploadHandlerInterface
{
    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CsvSearchTermParserInterface
     */
    protected CsvSearchTermParserInterface $csvSearchTermParser;

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface
     */
    protected SearchRankingOptimizerEntityManagerInterface $entityManager;

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\CsvSearchTermParserInterface $csvSearchTermParser
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     */
    public function __construct(
        CsvSearchTermParserInterface $csvSearchTermParser,
        SearchRankingOptimizerEntityManagerInterface $entityManager,
    ) {
        $this->csvSearchTermParser = $csvSearchTermParser;
        $this->entityManager = $entityManager;
    }

    /**
     * {@inheritDoc}
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
        $calibrationTransfer = (new SearchRankingCalibrationTransfer())
            ->setRelevantProductCount($relevantProductCount)
            ->setStoreName($storeName)
            ->setLocaleName($localeName)
            ->setStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_UPLOADED);

        foreach ($this->csvSearchTermParser->parse($csvContent) as $searchTerm) {
            $calibrationTransfer->addSearchTerm(
                (new SearchRankingCalibrationSearchTermTransfer())->setSearchTerm($searchTerm),
            );
        }

        return $this->entityManager->createCalibration($calibrationTransfer);
    }
}
