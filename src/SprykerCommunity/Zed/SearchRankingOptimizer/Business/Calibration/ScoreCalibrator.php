<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration;

use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\SearchRankingCalibrationNotFoundException;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;
use Throwable;

/**
 * Fires the calibration query for every uploaded search term directly against Elasticsearch — via
 * `SearchRankingOptimizerToSearchRankingClientInterface::getCalibrationScores()` (calibrationType
 * `relevance_score`) or `::getCalibrationSpecificity()` (calibrationType `specificity`, no real catalog
 * query at all), which bypass `Client\Catalog`/`Client\Search` entirely (both throw when called from Zed
 * in this shop; see `SprykerCommunity\Client\SearchRankingOptimizer\Search\CalibrationSearcher` for the
 * full story) — so the sampled values are faithful to production regardless of whether a `function_score`
 * wrapper happens to be wired in at the time; the raw text-relevance score is recovered either way.
 */
class ScoreCalibrator implements ScoreCalibratorInterface
{
    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface
     */
    protected SearchRankingOptimizerRepositoryInterface $repository;

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface
     */
    protected SearchRankingOptimizerEntityManagerInterface $entityManager;

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface
     */
    protected SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient;

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\StatisticsCalculatorInterface
     */
    protected StatisticsCalculatorInterface $statisticsCalculator;

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface
     */
    protected SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade;

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration\StatisticsCalculatorInterface $statisticsCalculator
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     */
    public function __construct(
        SearchRankingOptimizerRepositoryInterface $repository,
        SearchRankingOptimizerEntityManagerInterface $entityManager,
        SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient,
        StatisticsCalculatorInterface $statisticsCalculator,
        SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
    ) {
        $this->repository = $repository;
        $this->entityManager = $entityManager;
        $this->searchRankingClient = $searchRankingClient;
        $this->statisticsCalculator = $statisticsCalculator;
        $this->searchRankingFacade = $searchRankingFacade;
    }

    /**
     * {@inheritDoc}
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer|null
     */
    public function runNextCalibration(): ?SearchRankingCalibrationTransfer
    {
        $uploadedCalibrations = $this->repository->getUploadedCalibrations();

        if ($uploadedCalibrations === []) {
            return null;
        }

        $newestCalibration = array_shift($uploadedCalibrations);

        foreach ($uploadedCalibrations as $staleCalibrationTransfer) {
            $this->entityManager->updateCalibrationStatus(
                $staleCalibrationTransfer->getIdSearchRankingCalibrationOrFail(),
                SearchRankingOptimizerConfig::CALIBRATION_STATUS_SKIPPED,
            );
        }

        return $this->calculate($newestCalibration->getIdSearchRankingCalibrationOrFail());
    }

    /**
     * @param int $idSearchRankingCalibration
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer
     */
    protected function calculate(int $idSearchRankingCalibration): SearchRankingCalibrationTransfer
    {
        $this->entityManager->updateCalibrationStatus($idSearchRankingCalibration, SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATING);

        $calibrationTransfer = $this->requireCalibration($idSearchRankingCalibration);
        $calibrationType = $calibrationTransfer->getCalibrationType() ?? SearchRankingOptimizerConfig::CALIBRATION_TYPE_RELEVANCE_SCORE;
        $limit = $calibrationTransfer->getRelevantProductCountOrFail();
        $storeName = $calibrationTransfer->getStoreNameOrFail();
        $localeName = $calibrationTransfer->getLocaleNameOrFail();
        $pooledValues = [];

        foreach ($calibrationTransfer->getSearchTerms() as $searchTermTransfer) {
            $searchTerm = $searchTermTransfer->getSearchTermOrFail();
            $values = $calibrationType === SearchRankingOptimizerConfig::CALIBRATION_TYPE_SPECIFICITY
                ? $this->fetchSpecificityForSearchTerm($searchTerm, $storeName, $localeName)
                : $this->fetchScoresForSearchTerm($searchTerm, $storeName, $localeName, $limit);

            $this->entityManager->saveCalibrationSearchTermResult(
                $searchTermTransfer->getIdSearchRankingCalibrationSearchTermOrFail(),
                count($values),
                $values,
            );
            $this->entityManager->incrementCalibrationProcessedCount($idSearchRankingCalibration);

            array_push($pooledValues, ...$values);
        }

        if ($pooledValues === []) {
            $this->entityManager->markCalibrationFailed(
                $idSearchRankingCalibration,
                'No values were found for any uploaded search term.',
            );
        } else {
            $this->entityManager->saveCalibrationStatistics(
                $idSearchRankingCalibration,
                $this->statisticsCalculator->calculate($pooledValues),
            );
        }

        return $this->requireCalibration($idSearchRankingCalibration);
    }

    /**
     * @param int $idSearchRankingCalibration
     *
     * @throws \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception\SearchRankingCalibrationNotFoundException
     *
     * @return \Generated\Shared\Transfer\SearchRankingCalibrationTransfer
     */
    protected function requireCalibration(int $idSearchRankingCalibration): SearchRankingCalibrationTransfer
    {
        $calibrationTransfer = $this->repository->findCalibrationWithSearchTerms($idSearchRankingCalibration);

        if ($calibrationTransfer === null) {
            throw new SearchRankingCalibrationNotFoundException(sprintf(
                'Search ranking calibration with id "%d" was not found.',
                $idSearchRankingCalibration,
            ));
        }

        return $calibrationTransfer;
    }

    /**
     * A single search term's Elasticsearch call failing is caught here and treated as 0 products found
     * for that term — it must not abort the rest of the calibration run.
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $limit
     *
     * @return array<float>
     */
    protected function fetchScoresForSearchTerm(string $searchTerm, string $storeName, string $localeName, int $limit): array
    {
        try {
            return $this->searchRankingClient->getCalibrationScores($searchTerm, $storeName, $localeName, $limit);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * A single search term's `_termvectors` probe failing is caught here and treated as no value found
     * for that term — it must not abort the rest of the calibration run. Always at most ONE value (a
     * search term contributes exactly one raw specificity number, not one per product), wrapped in an
     * array so it pools into {@see StatisticsCalculatorInterface::calculate()} the same way relevance-score
     * calibration's per-product values do.
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     *
     * @return array<float>
     */
    protected function fetchSpecificityForSearchTerm(string $searchTerm, string $storeName, string $localeName): array
    {
        try {
            $rawSpecificity = $this->searchRankingClient->getCalibrationSpecificity(
                $searchTerm,
                $storeName,
                $this->searchRankingFacade->getSpecificityBlendWeight($storeName, $localeName),
            );
        } catch (Throwable) {
            return [];
        }

        return $rawSpecificity > 0.0 ? [$rawSpecificity] : [];
    }
}
