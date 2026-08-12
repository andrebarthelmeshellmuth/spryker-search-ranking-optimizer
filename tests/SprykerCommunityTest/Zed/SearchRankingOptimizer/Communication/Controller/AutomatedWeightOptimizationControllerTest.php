<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Communication\Controller;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller\AutomatedWeightOptimizationController;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManager;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepository;

/**
 * INTEGRATION TEST — {@see AutomatedWeightOptimizationController::progressAction()} is polled by the
 * Optimization page's own JS roughly once a second while a run is `running`, but neither this suite nor
 * `OptimizationCest` (which runs the console command synchronously and only inspects the page after the
 * run is already `done`) ever actually exercises that code path — closing that gap directly here rather
 * than trying to catch a real CMA-ES run mid-flight in a browser test, which would be slow and flaky.
 * `startOptimizerRun()`/`updateOptimizerRunProgress()` are used directly (the same writes
 * `OptimizationRunner` itself makes while a real run is in progress) to put a REAL `running` row in the
 * database, without needing an actual multi-second optimization run to produce one.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Communication
 * @group Controller
 * @group AutomatedWeightOptimizationControllerTest
 * @group NeedsDatabase
 */
class AutomatedWeightOptimizationControllerTest extends Unit
{
    public function testProgressReturnsNullStatusWhenNothingIsInProgress(): void
    {
        // Act
        $jsonResponse = (new AutomatedWeightOptimizationController())->progressAction();

        // Assert
        $this->assertNull(json_decode((string)$jsonResponse->getContent(), true)['status']);
    }

    public function testProgressReturnsTheRealRunningRunsStatusAndCounts(): void
    {
        // Arrange
        $idOptimizerRun = $this->createRunningOptimizerRun(totalCount: 40, processedCount: 17);

        try {
            // Act
            $jsonResponse = (new AutomatedWeightOptimizationController())->progressAction();

            // Assert
            $payload = json_decode((string)$jsonResponse->getContent(), true);
            $this->assertSame(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_RUNNING, $payload['status']);
            $this->assertSame(17, $payload['processedCount']);
            $this->assertSame(40, $payload['totalCount']);
        } finally {
            (new SearchRankingOptimizerEntityManager())->failOptimizerRun($idOptimizerRun, 'Cleaned up by AutomatedWeightOptimizationControllerTest.');
        }
    }

    protected function createRunningOptimizerRun(int $totalCount, int $processedCount): int
    {
        $entityManager = new SearchRankingOptimizerEntityManager();

        $queuedRunTransfer = $entityManager->createOptimizerRun(
            (new SearchRankingOptimizerRunTransfer())
                ->setStoreName('DE')
                ->setLocaleName('en_US')
                ->setAlgorithm(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES),
        );
        $idOptimizerRun = $queuedRunTransfer->getIdSearchRankingOptimizerRunOrFail();

        $entityManager->startOptimizerRun($idOptimizerRun, $totalCount, baselineScore: 0.5);
        $entityManager->updateOptimizerRunProgress($idOptimizerRun, $processedCount);

        $this->assertNotNull(
            (new SearchRankingOptimizerRepository())->findOptimizerRunInProgress(),
            'Setup: the row must be readable back as in-progress before progressAction() is exercised.',
        );

        return $idOptimizerRun;
    }
}
