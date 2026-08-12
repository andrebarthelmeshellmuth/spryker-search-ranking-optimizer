<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Communication\Console;

use ArrayObject;
use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingAutoTuneMetricResultTransfer;
use Generated\Shared\Transfer\SearchRankingAutoTuneResultTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacade;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerAutoTuneConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Mocks `SearchRankingOptimizerFacade::runAutoTune()` (via `Console::setFacade()`) so this proves the
 * console's own per-metric display branching — four distinct outcomes per metric — without running a
 * real monthly auto-tune pass. Each Business-layer call this console's Facade method delegates to is
 * already covered by its own dedicated unit test (`AutoTuneRunnerTest`,
 * `CurrentMetricFitEvaluatorTest`, `FormulaDeterminismCheckerTest`).
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Communication
 * @group Console
 * @group SearchRankingOptimizerAutoTuneConsoleTest
 * @group Portable
 */
class SearchRankingOptimizerAutoTuneConsoleTest extends Unit
{
    public function testReportsNoMetricHasAThresholdSetWhenTheResultIsEmpty(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester($this->createResultTransfer([], 0));

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerAutoTuneConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('No metric has an auto-tune threshold set', $commandTester->getDisplay());
    }

    public function testReportsAllFourPerMetricOutcomesAndTheNotifiedAdminCount(): void
    {
        // Arrange
        $metricResults = [
            (new SearchRankingAutoTuneMetricResultTransfer())
                ->setStoreNameOrFail('DE')
                ->setLocaleNameOrFail('de_DE')
                ->setMetricNameOrFail('failed_metric')
                ->setErrorMessageOrFail('formula evaluation threw'),
            (new SearchRankingAutoTuneMetricResultTransfer())
                ->setStoreNameOrFail('DE')
                ->setLocaleNameOrFail('de_DE')
                ->setMetricNameOrFail('still_fitting')
                ->setWasThresholdMetOrFail(true)
                ->setBeforeFitRSquaredOrFail(0.91),
            (new SearchRankingAutoTuneMetricResultTransfer())
                ->setStoreNameOrFail('AT')
                ->setLocaleNameOrFail('de_DE')
                ->setMetricNameOrFail('non_deterministic')
                ->setWasThresholdMetOrFail(false)
                ->setWasSkippedNonDeterministic(true)
                ->setBeforeFitRSquaredOrFail(0.4),
            (new SearchRankingAutoTuneMetricResultTransfer())
                ->setStoreNameOrFail('AT')
                ->setLocaleNameOrFail('de_DE')
                ->setMetricNameOrFail('refitted')
                ->setWasThresholdMetOrFail(false)
                ->setWasSkippedNonDeterministic(false)
                ->setBeforeFitRSquaredOrFail(0.3)
                ->setWasAppliedOrFail(true)
                ->setAfterFormula('atan(x/avg)')
                ->setAfterFitRSquared(0.95),
        ];

        $commandTester = $this->createCommandTester($this->createResultTransfer($metricResults, 2));

        // Act
        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        // Assert -- two different stores among the results, proving the store label distinguishes them.
        $this->assertSame(SearchRankingOptimizerAutoTuneConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('[DE/de_DE] failed_metric: FAILED to check — formula evaluation threw', $display);
        $this->assertStringContainsString('[DE/de_DE] still_fitting: fit still adequate (R² = 0.9100), no change.', $display);
        $this->assertStringContainsString('[AT/de_DE] non_deterministic: fit dropped to R² = 0.4000 (below threshold) — skipped, no refit: formula is non-deterministic.', $display);
        $this->assertStringContainsString('[AT/de_DE] refitted: fit dropped to R² = 0.3000 (below threshold) — applied atan(x/avg) (R² = 0.9500).', $display);
        $this->assertStringContainsString('Notified 2 admin(s) by email.', $display);
    }

    /**
     * A refit that could only be PROPOSED (not auto-applied) must say so, distinctly from "applied".
     */
    public function testReportsAProposedRefitDistinctlyFromAnAppliedOne(): void
    {
        // Arrange
        $metricResults = [
            (new SearchRankingAutoTuneMetricResultTransfer())
                ->setStoreNameOrFail('DE')
                ->setLocaleNameOrFail('de_DE')
                ->setMetricNameOrFail('proposed_only')
                ->setWasThresholdMetOrFail(false)
                ->setWasSkippedNonDeterministic(false)
                ->setBeforeFitRSquaredOrFail(0.2)
                ->setWasAppliedOrFail(false)
                ->setAfterFormula('sigmoid(x)')
                ->setAfterFitRSquared(0.88),
        ];

        $commandTester = $this->createCommandTester($this->createResultTransfer($metricResults, 0));

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerAutoTuneConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('[DE/de_DE] proposed_only: fit dropped to R² = 0.2000 (below threshold) — proposed sigmoid(x) (R² = 0.8800).', $commandTester->getDisplay());
    }

    /**
     * A locale-scoped metric gets one result row per real locale instead of one per store — the label
     * must distinguish them by locale too, not just by store, since the metric name and store are
     * otherwise identical across every one of those rows.
     */
    public function testDistinguishesMultipleLocaleRowsOfTheSameMetricAndStoreByTheirOwnLabel(): void
    {
        // Arrange
        $metricResults = [
            (new SearchRankingAutoTuneMetricResultTransfer())
                ->setStoreNameOrFail('DE')
                ->setLocaleNameOrFail('de_DE')
                ->setMetricNameOrFail('top_seller')
                ->setWasThresholdMetOrFail(true)
                ->setBeforeFitRSquaredOrFail(0.9),
            (new SearchRankingAutoTuneMetricResultTransfer())
                ->setStoreNameOrFail('DE')
                ->setLocaleNameOrFail('en_US')
                ->setMetricNameOrFail('top_seller')
                ->setWasThresholdMetOrFail(false)
                ->setWasSkippedNonDeterministic(false)
                ->setBeforeFitRSquaredOrFail(0.4)
                ->setWasAppliedOrFail(true)
                ->setAfterFormula('x / (x + 3)')
                ->setAfterFitRSquared(0.92),
        ];

        $commandTester = $this->createCommandTester($this->createResultTransfer($metricResults, 0));

        // Act
        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        // Assert
        $this->assertSame(SearchRankingOptimizerAutoTuneConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('[DE/de_DE] top_seller: fit still adequate (R² = 0.9000), no change.', $display);
        $this->assertStringContainsString('[DE/en_US] top_seller: fit dropped to R² = 0.4000 (below threshold) — applied x / (x + 3) (R² = 0.9200).', $display);
    }

    /**
     * A spread at or above SearchRankingOptimizerConfig::getLocaleFitDivergenceWarningThreshold() (0.1)
     * gets an extra warning line -- purely informational, formula/threshold/refit logic is unaffected.
     * Only ever populated on a store-wide metric's own result row (see AutoTuneRunner's own docblock).
     */
    public function testReportsALocaleFitDivergenceWarningWhenTheSpreadExceedsTheThreshold(): void
    {
        // Arrange
        $metricResults = [
            (new SearchRankingAutoTuneMetricResultTransfer())
                ->setStoreNameOrFail('DE')
                ->setLocaleNameOrFail('de_DE')
                ->setMetricNameOrFail('pdp_impressions')
                ->setWasThresholdMetOrFail(true)
                ->setBeforeFitRSquaredOrFail(0.91)
                ->setFitRSquaredByLocale(['de_DE' => 0.91, 'en_US' => 0.62]),
        ];

        $commandTester = $this->createCommandTester($this->createResultTransfer($metricResults, 0));

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerAutoTuneConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('[DE/de_DE] pdp_impressions:   ⚠ fit varies by locale (spread 0.2900): de_DE=0.9100, en_US=0.6200', $commandTester->getDisplay());
    }

    /**
     * A spread below the threshold (or fewer than two real locales with a digest) must stay silent --
     * proves this isn't printed unconditionally for every metric.
     */
    public function testStaysSilentAboutLocaleFitWhenTheSpreadIsBelowTheThreshold(): void
    {
        // Arrange
        $metricResults = [
            (new SearchRankingAutoTuneMetricResultTransfer())
                ->setStoreNameOrFail('DE')
                ->setLocaleNameOrFail('de_DE')
                ->setMetricNameOrFail('pdp_impressions')
                ->setWasThresholdMetOrFail(true)
                ->setBeforeFitRSquaredOrFail(0.91)
                ->setFitRSquaredByLocale(['de_DE' => 0.91, 'en_US' => 0.89]),
        ];

        $commandTester = $this->createCommandTester($this->createResultTransfer($metricResults, 0));

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerAutoTuneConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringNotContainsString('varies by locale', $commandTester->getDisplay());
    }

    /**
     * A genuinely locale-scoped metric's own per-locale result rows never populate fitRSquaredByLocale
     * (see AutoTuneRunner's own docblock) -- proves an unpopulated map stays silent too, the same as a
     * store-wide metric whose spread is simply below threshold.
     */
    public function testStaysSilentAboutLocaleFitWhenFitRSquaredByLocaleIsNotPopulated(): void
    {
        // Arrange
        $metricResults = [
            (new SearchRankingAutoTuneMetricResultTransfer())
                ->setStoreNameOrFail('DE')
                ->setLocaleNameOrFail('en_US')
                ->setMetricNameOrFail('top_seller')
                ->setWasThresholdMetOrFail(true)
                ->setBeforeFitRSquaredOrFail(0.4),
        ];

        $commandTester = $this->createCommandTester($this->createResultTransfer($metricResults, 0));

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchRankingOptimizerAutoTuneConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringNotContainsString('varies by locale', $commandTester->getDisplay());
    }

    /**
     * @param array<\Generated\Shared\Transfer\SearchRankingAutoTuneMetricResultTransfer> $metricResults
     * @param int $notifiedEmailCount
     */
    protected function createResultTransfer(array $metricResults, int $notifiedEmailCount): SearchRankingAutoTuneResultTransfer
    {
        return (new SearchRankingAutoTuneResultTransfer())
            ->setMetricResults(new ArrayObject($metricResults))
            ->setNotifiedEmailCountOrFail($notifiedEmailCount);
    }

    protected function createCommandTester(SearchRankingAutoTuneResultTransfer $resultTransfer): CommandTester
    {
        $facadeMock = $this->getMockBuilder(SearchRankingOptimizerFacade::class)
            ->onlyMethods(['runAutoTune'])
            ->getMock();
        $facadeMock->method('runAutoTune')->willReturn($resultTransfer);

        $console = new SearchRankingOptimizerAutoTuneConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        return new CommandTester($application->find(SearchRankingOptimizerAutoTuneConsole::COMMAND_NAME));
    }
}
