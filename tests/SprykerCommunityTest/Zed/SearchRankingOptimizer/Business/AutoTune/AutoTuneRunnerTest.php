<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\AutoTune;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Generated\Shared\Transfer\StoreTransfer;
use RuntimeException;
use SprykerCommunity\Shared\SearchRanking\SearchRankingConfig as SharedSearchRankingConfig;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolverInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunner;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismChecker;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToStoreFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSymfonyMailerFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group AutoTune
 * @group AutoTuneRunnerTest
 * Add your own group annotations below this line
 * @group Portable
 */
class AutoTuneRunnerTest extends Unit
{
    public function testSkipsAMetricThatNoLongerExists(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->with(7)->willReturn(null);
        $searchRankingFacadeMock->expects($this->never())->method('evaluateCurrentMetricFitAcrossLocales');

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock);

        // Act
        $result = $runner->run();

        // Assert
        $this->assertCount(0, $result->getMetricResults());
        $this->assertSame(0, $result->getNotifiedEmailCount());
    }

    public function testSkipsAMetricWithNoDigestYetAtTheDefaultLocale(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->with(7, SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME)->willReturn(['de_DE' => null]);

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock);

        // Act
        $result = $runner->run();

        // Assert
        $this->assertCount(0, $result->getMetricResults());
    }

    public function testRecordsACheckOnlyRowAndDoesNotRefitWhenTheFitIsAtOrAboveThreshold(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, true, true),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->willReturn(['de_DE' => 0.9]);
        $searchRankingFacadeMock->expects($this->once())->method('recordMetricCheckOnly')->with(7);
        $searchRankingFacadeMock->expects($this->never())->method('getFitCandidates');
        $searchRankingFacadeMock->expects($this->never())->method('saveMetricFormula');

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock);

        // Act
        $result = $runner->run();

        // Assert — threshold met, so this metric is never included in the notify batch even though
        // isNotifyEnabled is on.
        $metricResults = $result->getMetricResults();
        $this->assertCount(1, $metricResults);
        $this->assertSame('de_DE', $metricResults[0]->getLocaleName());
        $this->assertTrue($metricResults[0]->getWasThresholdMet());
        $this->assertFalse($metricResults[0]->getWasApplied());
        $this->assertSame(0, $result->getNotifiedEmailCount());
    }

    /**
     * For a store-wide metric, evaluateCurrentMetricFitAcrossLocales() is the SINGLE source of
     * beforeFitRSquared too (the store's own default locale's entry in that same map) — this proves the
     * result transfer's beforeFitRSquared and fitRSquaredByLocale both genuinely come from that one call,
     * not from a separate now-removed evaluateCurrentMetricFit() call.
     */
    public function testPopulatesFitRSquaredByLocaleAndSourcesBeforeFitFromTheSameAcrossLocalesCall(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, true, true),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7));
        $searchRankingFacadeMock->expects($this->once())
            ->method('evaluateCurrentMetricFitAcrossLocales')
            ->with(7, 'DE')
            ->willReturn(['de_DE' => 0.91, 'en_US' => 0.62]);

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock);

        // Act
        $result = $runner->run();

        // Assert
        $metricResults = $result->getMetricResults();
        $this->assertSame(['de_DE' => 0.91, 'en_US' => 0.62], $metricResults[0]->getFitRSquaredByLocale());
        $this->assertSame(0.91, $metricResults[0]->getBeforeFitRSquared());
    }

    public function testRecordsACheckOnlyRowAndNeverRefitsWhenTheFormulaIsNonDeterministic(): void
    {
        // Arrange -- a placeholder/noise metric (formula calls random()) with a genuinely bad fit, well
        // below threshold. Fitting a "better" curve to noise would just overfit to whatever randomness
        // happened to be in this digest snapshot, so it must never reach getFitCandidates()/
        // saveMetricFormula() -- even though auto-update is enabled here, proving this isn't merely that
        // toggle happening to be off.
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, true, true),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn([
            'idSearchRankingMetric' => 7,
            'name' => 'random',
            'formula' => 'random()',
            'isHigherBetter' => true,
            'shape' => null,
            'isLocaleScoped' => false,
        ]);
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->willReturn(['de_DE' => -1.08]);
        $searchRankingFacadeMock->expects($this->once())->method('recordMetricCheckOnly')->with(7);
        $searchRankingFacadeMock->expects($this->never())->method('getFitCandidates');
        $searchRankingFacadeMock->expects($this->never())->method('saveMetricFormula');

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock);

        // Act
        $result = $runner->run();

        // Assert -- still checked and visible (unlike "no digest yet", which is dropped entirely), just
        // never refit.
        $metricResults = $result->getMetricResults();
        $this->assertCount(1, $metricResults);
        $this->assertFalse($metricResults[0]->getWasThresholdMet());
        $this->assertFalse($metricResults[0]->getWasApplied());
    }

    public function testProposesARefitWithoutApplyingWhenAutoUpdateIsDisabled(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, false, false),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7, 'atan'));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->willReturn(['de_DE' => 0.5]);
        $searchRankingFacadeMock->method('getFitCandidates')->willReturn([
            ['shape' => 'atan', 'formula' => 'atan(x / 5)', 'rSquared' => 0.7, 'isWinner' => false],
            ['shape' => 'hyperbolic', 'formula' => 'x / (x + 3)', 'rSquared' => 0.9, 'isWinner' => true],
        ]);
        $searchRankingFacadeMock->expects($this->never())->method('saveMetricFormula');
        $searchRankingFacadeMock->expects($this->once())->method('recordMetricCheckOnly')->with(7);

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock);

        // Act
        $result = $runner->run();

        // Assert
        $metricResults = $result->getMetricResults();
        $this->assertFalse($metricResults[0]->getWasThresholdMet());
        $this->assertFalse($metricResults[0]->getWasApplied());
        $this->assertSame('x / (x + 3)', $metricResults[0]->getAfterFormula());
    }

    public function testAppliesTheRefitThroughTheBridgeWhenAutoUpdateIsEnabled(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, true, false),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7, 'atan'));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->willReturn(['de_DE' => 0.5]);
        $searchRankingFacadeMock->method('getFitCandidates')->willReturn([
            ['shape' => 'hyperbolic', 'formula' => 'x / (x + 3)', 'rSquared' => 0.9, 'isWinner' => true],
        ]);
        $searchRankingFacadeMock->expects($this->once())
            ->method('saveMetricFormula')
            ->with(7, 'x / (x + 3)')
            ->willReturn(true);
        $searchRankingFacadeMock->expects($this->never())->method('recordMetricCheckOnly');

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock);

        // Act
        $result = $runner->run();

        // Assert
        $this->assertTrue($result->getMetricResults()[0]->getWasApplied());
    }

    /**
     * Scope=parameters-only must stick to the SAME shape the metric already uses, even when a different
     * shape would fit better — that's the whole point of the "parameters only" scope.
     */
    public function testParametersOnlyScopePicksTheCandidateMatchingTheCurrentShapeOverTheOverallWinner(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, false, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7, 'atan'));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->willReturn(['de_DE' => 0.5]);
        $searchRankingFacadeMock->method('getFitCandidates')->willReturn([
            ['shape' => 'atan', 'formula' => 'atan(x / 9)', 'rSquared' => 0.7, 'isWinner' => false],
            ['shape' => 'hyperbolic', 'formula' => 'x / (x + 3)', 'rSquared' => 0.9, 'isWinner' => true],
        ]);

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock);

        // Act
        $result = $runner->run();

        // Assert
        $this->assertSame('atan(x / 9)', $result->getMetricResults()[0]->getAfterFormula());
    }

    /**
     * A metric with no known shape (a freeform/custom formula) has nothing to "stay within", so
     * parameters-only falls back to the overall best-fitting candidate instead of doing nothing.
     */
    public function testParametersOnlyScopeFallsBackToTheOverallWinnerWhenTheMetricHasNoKnownShape(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, false, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7, null));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->willReturn(['de_DE' => 0.5]);
        $searchRankingFacadeMock->method('getFitCandidates')->willReturn([
            ['shape' => 'atan', 'formula' => 'atan(x / 9)', 'rSquared' => 0.7, 'isWinner' => false],
            ['shape' => 'hyperbolic', 'formula' => 'x / (x + 3)', 'rSquared' => 0.9, 'isWinner' => true],
        ]);

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock);

        // Act
        $result = $runner->run();

        // Assert
        $this->assertSame('x / (x + 3)', $result->getMetricResults()[0]->getAfterFormula());
    }

    public function testSendsNoEmailAndReportsZeroWhenNoMetricNeedsNotifying(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, false, false),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->willReturn(['de_DE' => 0.5]);
        $searchRankingFacadeMock->method('getFitCandidates')->willReturn([
            ['shape' => 'hyperbolic', 'formula' => 'x / (x + 3)', 'rSquared' => 0.9, 'isWinner' => true],
        ]);

        $recipientResolverMock = $this->createMock(AutoTuneNotificationRecipientResolverInterface::class);
        $recipientResolverMock->expects($this->never())->method('resolve');

        $mailerFacadeMock = $this->createMock(SearchRankingOptimizerToSymfonyMailerFacadeInterface::class);
        $mailerFacadeMock->expects($this->never())->method('send');

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock, $recipientResolverMock, $mailerFacadeMock);

        // Act
        $result = $runner->run();

        // Assert
        $this->assertSame(0, $result->getNotifiedEmailCount());
    }

    public function testSendsExactlyOneCombinedEmailWhenAMetricNeedsNotifyingAndRecipientsExist(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, false, true),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->willReturn(['de_DE' => 0.5]);
        $searchRankingFacadeMock->method('getFitCandidates')->willReturn([
            ['shape' => 'hyperbolic', 'formula' => 'x / (x + 3)', 'rSquared' => 0.9, 'isWinner' => true],
        ]);

        $recipientResolverMock = $this->createMock(AutoTuneNotificationRecipientResolverInterface::class);
        $recipientResolverMock->expects($this->once())->method('resolve')->willReturn(['alice@example.com', 'bob@example.com']);

        $mailerFacadeMock = $this->createMock(SearchRankingOptimizerToSymfonyMailerFacadeInterface::class);
        $mailerFacadeMock->expects($this->once())->method('send');

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock, $recipientResolverMock, $mailerFacadeMock);

        // Act
        $result = $runner->run();

        // Assert
        $this->assertSame(2, $result->getNotifiedEmailCount());
    }

    public function testAnExceptionWhileProcessingOneMetricDoesNotAbortTheOthers(): void
    {
        // Arrange -- metric 7 blows up (e.g. a transient ES failure inside
        // evaluateCurrentMetricFitAcrossLocales()), metric 8 must still be checked normally in the same run.
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8),
            $this->createConfigTransfer(8, 0.8, true, true),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturnMap([
            [7, SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME, SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME, $this->createMetricDetail(7)],
            [8, SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME, SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME, $this->createMetricDetail(8)],
        ]);
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->willReturnCallback(
            function (int $idSearchRankingMetric) {
                if ($idSearchRankingMetric === 7) {
                    throw new RuntimeException('Elasticsearch unreachable.');
                }

                return ['de_DE' => 0.9];
            },
        );
        $searchRankingFacadeMock->expects($this->once())->method('recordMetricCheckOnly')->with(8);

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock);

        // Act
        $result = $runner->run();

        // Assert -- both metrics show up: 7 as a failure, 8 processed normally.
        $metricResults = $result->getMetricResults();
        $this->assertCount(2, $metricResults);

        $this->assertSame(7, $metricResults[0]->getIdSearchRankingMetric());
        $this->assertSame('Elasticsearch unreachable.', $metricResults[0]->getErrorMessage());
        $this->assertSame('de_DE', $metricResults[0]->getLocaleName());

        $this->assertSame(8, $metricResults[1]->getIdSearchRankingMetric());
        $this->assertNull($metricResults[1]->getErrorMessage());
        $this->assertTrue($metricResults[1]->getWasThresholdMet());
    }

    public function testAFailedMetricIsIncludedInTheNotifyEmailWhenNotifyIsEnabled(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, false, true),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willThrowException(new RuntimeException('DB connection lost.'));

        $recipientResolverMock = $this->createMock(AutoTuneNotificationRecipientResolverInterface::class);
        $recipientResolverMock->expects($this->once())->method('resolve')->willReturn(['alice@example.com']);

        $mailerFacadeMock = $this->createMock(SearchRankingOptimizerToSymfonyMailerFacadeInterface::class);
        $mailerFacadeMock->expects($this->once())->method('send');

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock, $recipientResolverMock, $mailerFacadeMock);

        // Act
        $result = $runner->run();

        // Assert
        $this->assertSame(1, $result->getNotifiedEmailCount());
        $this->assertSame('DB connection lost.', $result->getMetricResults()[0]->getErrorMessage());
    }

    /**
     * Two real stores, DE and AT, each with their own metric #7 config and their own current fit — proves
     * a run genuinely processes every store independently: DE's fit is above threshold (no refit), AT's
     * is below (refit proposed), and both results carry their own storeName, not a single shared scope.
     */
    public function testChecksEveryRealStoreIndependently(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturnMap([
            ['DE', [$this->createConfigTransfer(7, 0.8, false, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, 'DE')]],
            ['AT', [$this->createConfigTransfer(7, 0.8, false, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, 'AT')]],
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturnMap([
            [7, 'DE', SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME, $this->createMetricDetail(7)],
            [7, 'AT', SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME, $this->createMetricDetail(7)],
        ]);
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->willReturnMap([
            [7, 'DE', ['de_DE' => 0.9]],
            [7, 'AT', ['de_DE' => 0.5]],
        ]);
        $searchRankingFacadeMock->method('getFitCandidates')->willReturnMap([
            [
                7,
                'AT',
                SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME,
                [
                    ['shape' => 'hyperbolic', 'formula' => 'x / (x + 3)', 'rSquared' => 0.9, 'isWinner' => true],
                ],
            ],
        ]);

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock, storeFacade: $this->createStoreFacadeMock(['DE', 'AT']));

        // Act
        $result = $runner->run();

        // Assert
        $metricResults = $result->getMetricResults();
        $this->assertCount(2, $metricResults);

        $this->assertSame('DE', $metricResults[0]->getStoreName());
        $this->assertTrue($metricResults[0]->getWasThresholdMet());

        $this->assertSame('AT', $metricResults[1]->getStoreName());
        $this->assertFalse($metricResults[1]->getWasThresholdMet());
        $this->assertSame('x / (x + 3)', $metricResults[1]->getAfterFormula());
    }

    /**
     * A store that has never had `search-ranking` set up for it (no metric store-config rows at all) is
     * skipped BEFORE its auto-tune configs are even queried — never evaluated against empty/default state.
     */
    public function testSkipsAStoreWithNoSearchRankingConfiguration(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->expects($this->once())
            ->method('findAutoTuneMetricConfigsWithThresholdSet')
            ->with('DE')
            ->willReturn([$this->createConfigTransfer(7, 0.8, false, false, SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE, 'DE')]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('hasStoreConfiguration')->willReturnMap([
            ['DE', true],
            ['AT', false],
        ]);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->willReturn(['de_DE' => 0.9]);

        $runner = $this->createRunner(
            $repositoryMock,
            $searchRankingFacadeMock,
            storeFacade: $this->createStoreFacadeMock(['DE', 'AT']),
            stubHasStoreConfiguration: false,
        );

        // Act
        $result = $runner->run();

        // Assert
        $metricResults = $result->getMetricResults();
        $this->assertCount(1, $metricResults);
        $this->assertSame('DE', $metricResults[0]->getStoreName());
    }

    /**
     * The core new behavior: a genuinely locale-scoped metric (`isLocaleScoped=true`) gets one fully
     * independent result per real locale of the store, each with its own formula/fit/refit outcome — not
     * one result for the whole store. de_DE's fit is already adequate (no refit); en_US's has dropped and
     * gets refit and applied — proving the two locales are processed completely independently, not sharing
     * a single check the way a store-wide metric would.
     */
    public function testFansOutOverEveryRealLocaleWhenTheMetricIsLocaleScoped(): void
    {
        // Arrange — a locale-scoped metric's config is independent per locale now; both real locales are
        // explicitly configured here so both get checked (see testSkipsALocaleScopedMetricsLocaleThatHasNoConfigOfItsOwn
        // for the "one locale never configured" case).
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, true, false, localeName: 'de_DE'),
            $this->createConfigTransfer(7, 0.8, true, false, localeName: 'en_US'),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturnMap([
            [7, 'DE', 'de_DE', $this->createMetricDetail(7, 'atan', true)],
            [7, 'DE', 'en_US', $this->createMetricDetail(7, 'atan', true)],
        ]);
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->with(7, 'DE')->willReturn([
            'de_DE' => 0.9,
            'en_US' => 0.4,
        ]);
        $searchRankingFacadeMock->method('getFitCandidates')->with(7, 'DE', 'en_US')->willReturn([
            ['shape' => 'hyperbolic', 'formula' => 'x / (x + 3)', 'rSquared' => 0.92, 'isWinner' => true],
        ]);
        $searchRankingFacadeMock->expects($this->once())
            ->method('saveMetricFormula')
            ->with(7, 'x / (x + 3)', 'DE', 'en_US')
            ->willReturn(true);
        $searchRankingFacadeMock->expects($this->once())->method('recordMetricCheckOnly')->with(7, 'DE', 'de_DE');

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock, storeFacade: $this->createStoreFacadeMock(['DE']));

        // Act
        $result = $runner->run();

        // Assert
        $metricResults = $result->getMetricResults();
        $this->assertCount(2, $metricResults);

        $this->assertSame('de_DE', $metricResults[0]->getLocaleName());
        $this->assertTrue($metricResults[0]->getWasThresholdMet());
        $this->assertFalse($metricResults[0]->getWasApplied());

        $this->assertSame('en_US', $metricResults[1]->getLocaleName());
        $this->assertFalse($metricResults[1]->getWasThresholdMet());
        $this->assertTrue($metricResults[1]->getWasApplied());
        $this->assertSame('x / (x + 3)', $metricResults[1]->getAfterFormula());
    }

    /**
     * A locale with no digest yet (e.g. a market added after this metric became locale-scoped) is skipped
     * for that locale only — same "safe absence" contract the store-wide path already has — without that
     * missing digest dropping the metric's OTHER, real locale.
     */
    public function testSkipsOnlyTheLocaleWithNoDigestYetForALocaleScopedMetric(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7, 'atan', true));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->willReturn([
            'de_DE' => 0.9,
            'en_US' => null,
        ]);

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock, storeFacade: $this->createStoreFacadeMock(['DE']));

        // Act
        $result = $runner->run();

        // Assert
        $metricResults = $result->getMetricResults();
        $this->assertCount(1, $metricResults);
        $this->assertSame('de_DE', $metricResults[0]->getLocaleName());
    }

    /**
     * The real new behavior this whole extension is for: a locale-scoped metric's auto-tune config is now
     * per-locale, not inherited from the store's default locale. en_US has a real digest (unlike the "no
     * digest yet" test above) but was simply never individually configured — it must still be skipped,
     * proving there is no silent fallback to de_DE's threshold/auto-update/notify settings.
     */
    public function testSkipsALocaleScopedMetricsLocaleThatHasNoConfigOfItsOwn(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, localeName: 'de_DE'),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7, 'atan', true));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFitAcrossLocales')->willReturn([
            'de_DE' => 0.9,
            'en_US' => 0.4,
        ]);
        $searchRankingFacadeMock->expects($this->never())->method('getFitCandidates');
        $searchRankingFacadeMock->expects($this->never())->method('saveMetricFormula');

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock, storeFacade: $this->createStoreFacadeMock(['DE']));

        // Act
        $result = $runner->run();

        // Assert
        $metricResults = $result->getMetricResults();
        $this->assertCount(1, $metricResults);
        $this->assertSame('de_DE', $metricResults[0]->getLocaleName());
    }

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \PHPUnit\Framework\MockObject\MockObject&\SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolverInterface|null $recipientResolver
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSymfonyMailerFacadeInterface|null $mailerFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToStoreFacadeInterface|null $storeFacade
     * @param bool $stubHasStoreConfiguration Every pre-existing test in this file assumes search-ranking is
     *   already configured for whichever store it's checking — pass false when a test needs to configure
     *   `hasStoreConfiguration` itself (e.g. to exercise the skip-an-unconfigured-store path), since
     *   PHPUnit mocks only honor the FIRST stub registered for a given method with no `->with()` constraint.
     */
    protected function createRunner(
        SearchRankingOptimizerRepositoryInterface $repository,
        SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
        ?AutoTuneNotificationRecipientResolverInterface $recipientResolver = null,
        ?SearchRankingOptimizerToSymfonyMailerFacadeInterface $mailerFacade = null,
        ?SearchRankingOptimizerToStoreFacadeInterface $storeFacade = null,
        bool $stubHasStoreConfiguration = true,
    ): AutoTuneRunner {
        $recipientResolver ??= $this->createMock(AutoTuneNotificationRecipientResolverInterface::class);
        $mailerFacade ??= $this->createMock(SearchRankingOptimizerToSymfonyMailerFacadeInterface::class);
        $storeFacade ??= $this->createStoreFacadeMock([SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME]);

        if ($stubHasStoreConfiguration) {
            $searchRankingFacade->method('hasStoreConfiguration')->willReturn(true);
        }

        return new AutoTuneRunner($repository, $searchRankingFacade, $storeFacade, $recipientResolver, $mailerFacade, new FormulaDeterminismChecker());
    }

    /**
     * @param array<string> $storeNames
     */
    protected function createStoreFacadeMock(array $storeNames): SearchRankingOptimizerToStoreFacadeInterface
    {
        $storeFacadeMock = $this->createMock(SearchRankingOptimizerToStoreFacadeInterface::class);
        $storeFacadeMock->method('getAllStores')->willReturn(array_map(
            fn (string $storeName) => (new StoreTransfer())
                ->setName($storeName)
                ->setDefaultLocaleIsoCode(SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME),
            $storeNames,
        ));

        return $storeFacadeMock;
    }

    /**
     * @param int $idSearchRankingMetric
     * @param float $autoTuneThreshold
     * @param bool $isAutoUpdateEnabled
     * @param bool $isNotifyEnabled
     * @param string $autoUpdateScope
     * @param string $storeName
     * @param string $localeName
     */
    protected function createConfigTransfer(
        int $idSearchRankingMetric,
        float $autoTuneThreshold,
        bool $isAutoUpdateEnabled = false,
        bool $isNotifyEnabled = false,
        string $autoUpdateScope = SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE,
        string $storeName = SharedSearchRankingConfig::DEFAULT_SCOPE_STORE_NAME,
        string $localeName = SharedSearchRankingConfig::DEFAULT_SCOPE_LOCALE_NAME,
    ): SearchRankingAutoTuneMetricConfigTransfer {
        return (new SearchRankingAutoTuneMetricConfigTransfer())
            ->setIdSearchRankingMetric($idSearchRankingMetric)
            ->setStoreName($storeName)
            ->setLocaleName($localeName)
            ->setAutoTuneThreshold($autoTuneThreshold)
            ->setIsAutoUpdateEnabled($isAutoUpdateEnabled)
            ->setIsNotifyEnabled($isNotifyEnabled)
            ->setAutoUpdateScope($autoUpdateScope);
    }

    /**
     * @param int $idSearchRankingMetric
     * @param string|null $shape
     * @param bool $isLocaleScoped
     *
     * @return array{idSearchRankingMetric: int, name: string, formula: string, isHigherBetter: bool, shape: string|null, isLocaleScoped: bool}
     */
    protected function createMetricDetail(int $idSearchRankingMetric, ?string $shape = 'hyperbolic', bool $isLocaleScoped = false): array
    {
        return [
            'idSearchRankingMetric' => $idSearchRankingMetric,
            'name' => 'top_seller',
            'formula' => 'x / (x + 6.42)',
            'isHigherBetter' => true,
            'shape' => $shape,
            'isLocaleScoped' => $isLocaleScoped,
        ];
    }
}
