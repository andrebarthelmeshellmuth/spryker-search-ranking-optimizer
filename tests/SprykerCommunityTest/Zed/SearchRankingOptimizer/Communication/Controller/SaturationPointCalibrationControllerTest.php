<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Communication\Controller;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationSearchTermTransfer;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller\SaturationPointCalibrationController;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManager;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * INTEGRATION TEST — {@see SaturationPointCalibrationController::progressAction()} is polled by the
 * Saturation Point Calibration page's own JS roughly once a second while a run is `calculating`, but
 * neither this suite nor `CalibrationCest` (which runs the console command synchronously and only
 * inspects the page after the run is already `calculated`) ever actually exercises that code path —
 * mirrors {@see AutomatedWeightOptimizationControllerTest} for the same reason: a real multi-search-term
 * calibration run is slow and the "calculating" window is too narrow to reliably catch from a browser
 * test, so the real `calculating` row is built directly via the same EntityManager writes
 * `ScoreCalibrator` itself makes mid-run.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Communication
 * @group Controller
 * @group SaturationPointCalibrationControllerTest
 */
class SaturationPointCalibrationControllerTest extends Unit
{
    public function testProgressReturnsNullStatusWhenNothingIsInProgress(): void
    {
        // Act
        $jsonResponse = (new SaturationPointCalibrationController())->progressAction(new Request());

        // Assert
        $this->assertNull(json_decode((string)$jsonResponse->getContent(), true)['status']);
    }

    public function testProgressReturnsTheRealCalculatingRunsStatusAndCounts(): void
    {
        // Arrange
        $idCalibration = $this->createCalculatingCalibration(searchTermCount: 12, processedCount: 5);

        try {
            // Act — scope must match createCalculatingCalibration()'s own DE/en_US, same as
            // indexAction()/the page's own JS would send for the scope actually being viewed.
            $jsonResponse = (new SaturationPointCalibrationController())->progressAction(
                new Request(['storeName' => 'DE', 'localeName' => 'en_US']),
            );

            // Assert
            $payload = json_decode((string)$jsonResponse->getContent(), true);
            $this->assertSame(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATING, $payload['status']);
            $this->assertSame(5, $payload['processedCount']);
            $this->assertSame(12, $payload['totalCount']);
        } finally {
            (new SearchRankingOptimizerEntityManager())->markCalibrationFailed($idCalibration, 'Cleaned up by SaturationPointCalibrationControllerTest.');
        }
    }

    /**
     * A calculating run for a DIFFERENT scope than the one the page's JS is polling for must not be
     * reported — proves progressAction() genuinely filters by the request's own storeName/localeName,
     * not just happens to find the one calculating row system-wide.
     */
    public function testProgressReturnsNullStatusWhenTheCalculatingRunIsForADifferentScope(): void
    {
        // Arrange — createCalculatingCalibration() always creates DE/en_US.
        $idCalibration = $this->createCalculatingCalibration(searchTermCount: 4, processedCount: 1);

        try {
            // Act
            $jsonResponse = (new SaturationPointCalibrationController())->progressAction(
                new Request(['storeName' => 'AT', 'localeName' => 'de_AT']),
            );

            // Assert
            $this->assertNull(json_decode((string)$jsonResponse->getContent(), true)['status']);
        } finally {
            (new SearchRankingOptimizerEntityManager())->markCalibrationFailed($idCalibration, 'Cleaned up by SaturationPointCalibrationControllerTest.');
        }
    }

    protected function createCalculatingCalibration(int $searchTermCount, int $processedCount): int
    {
        $calibrationTransfer = new SearchRankingSaturationPointCalibrationTransfer();
        $calibrationTransfer
            ->setCalibrationType(SearchRankingOptimizerConfig::CALIBRATION_TYPE_RELEVANCE_SCORE)
            ->setRelevantProductCount(3)
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setStatus(SearchRankingOptimizerConfig::CALIBRATION_STATUS_CALCULATING);

        for ($i = 0; $i < $searchTermCount; $i++) {
            $calibrationTransfer->addSearchTerm(
                (new SearchRankingSaturationPointCalibrationSearchTermTransfer())->setSearchTerm('progress-test-term-' . $i),
            );
        }

        $entityManager = new SearchRankingOptimizerEntityManager();
        $createdCalibrationTransfer = $entityManager->createCalibration($calibrationTransfer);
        $idCalibration = $createdCalibrationTransfer->getIdSearchRankingSaturationPointCalibrationOrFail();

        for ($i = 0; $i < $processedCount; $i++) {
            $entityManager->incrementCalibrationProcessedCount($idCalibration);
        }

        $this->assertNotNull(
            (new SearchRankingOptimizerRepository())->findCalibrationInProgress('DE', 'en_US'),
            'Setup: the row must be readable back as in-progress before progressAction() is exercised.',
        );

        return $idCalibration;
    }
}
