<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\AutoTune;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolverInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunner;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismChecker;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
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
 */
class AutoTuneRunnerTest extends Unit
{
    /**
     * @return void
     */
    public function testSkipsAMetricThatNoLongerExists(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->with(7)->willReturn(null);
        $searchRankingFacadeMock->expects($this->never())->method('evaluateCurrentMetricFit');

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock);

        // Act
        $result = $runner->run();

        // Assert
        $this->assertCount(0, $result->getMetricResults());
        $this->assertSame(0, $result->getNotifiedEmailCount());
    }

    /**
     * @return void
     */
    public function testSkipsAMetricWithNoDigestYet(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFit')->with(7)->willReturn(null);

        $runner = $this->createRunner($repositoryMock, $searchRankingFacadeMock);

        // Act
        $result = $runner->run();

        // Assert
        $this->assertCount(0, $result->getMetricResults());
    }

    /**
     * @return void
     */
    public function testRecordsACheckOnlyRowAndDoesNotRefitWhenTheFitIsAtOrAboveThreshold(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, true, true),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFit')->willReturn(0.9);
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
        $this->assertTrue($metricResults[0]->getWasThresholdMet());
        $this->assertFalse($metricResults[0]->getWasApplied());
        $this->assertSame(0, $result->getNotifiedEmailCount());
    }

    /**
     * @return void
     */
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
        ]);
        $searchRankingFacadeMock->method('evaluateCurrentMetricFit')->willReturn(-1.08);
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

    /**
     * @return void
     */
    public function testProposesARefitWithoutApplyingWhenAutoUpdateIsDisabled(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, false, false),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7, 'atan'));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFit')->willReturn(0.5);
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

    /**
     * @return void
     */
    public function testAppliesTheRefitThroughTheBridgeWhenAutoUpdateIsEnabled(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, true, false),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7, 'atan'));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFit')->willReturn(0.5);
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
     *
     * @return void
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
        $searchRankingFacadeMock->method('evaluateCurrentMetricFit')->willReturn(0.5);
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
     *
     * @return void
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
        $searchRankingFacadeMock->method('evaluateCurrentMetricFit')->willReturn(0.5);
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

    /**
     * @return void
     */
    public function testSendsNoEmailAndReportsZeroWhenNoMetricNeedsNotifying(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, false, false),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFit')->willReturn(0.5);
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

    /**
     * @return void
     */
    public function testSendsExactlyOneCombinedEmailWhenAMetricNeedsNotifyingAndRecipientsExist(): void
    {
        // Arrange
        $repositoryMock = $this->createMock(SearchRankingOptimizerRepositoryInterface::class);
        $repositoryMock->method('findAutoTuneMetricConfigsWithThresholdSet')->willReturn([
            $this->createConfigTransfer(7, 0.8, false, true),
        ]);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('findMetricDetail')->willReturn($this->createMetricDetail(7));
        $searchRankingFacadeMock->method('evaluateCurrentMetricFit')->willReturn(0.5);
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

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolverInterface|null $recipientResolver
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSymfonyMailerFacadeInterface|null $mailerFacade
     *
     * @return \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunner
     */
    protected function createRunner(
        SearchRankingOptimizerRepositoryInterface $repository,
        SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
        ?AutoTuneNotificationRecipientResolverInterface $recipientResolver = null,
        ?SearchRankingOptimizerToSymfonyMailerFacadeInterface $mailerFacade = null,
    ): AutoTuneRunner {
        $recipientResolver ??= $this->createMock(AutoTuneNotificationRecipientResolverInterface::class);
        $mailerFacade ??= $this->createMock(SearchRankingOptimizerToSymfonyMailerFacadeInterface::class);

        return new AutoTuneRunner($repository, $searchRankingFacade, $recipientResolver, $mailerFacade, new FormulaDeterminismChecker());
    }

    /**
     * @param int $idSearchRankingMetric
     * @param float $autoTuneThreshold
     * @param bool $isAutoUpdateEnabled
     * @param bool $isNotifyEnabled
     * @param string $autoUpdateScope
     *
     * @return \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer
     */
    protected function createConfigTransfer(
        int $idSearchRankingMetric,
        float $autoTuneThreshold,
        bool $isAutoUpdateEnabled = false,
        bool $isNotifyEnabled = false,
        string $autoUpdateScope = SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE,
    ): SearchRankingAutoTuneMetricConfigTransfer {
        return (new SearchRankingAutoTuneMetricConfigTransfer())
            ->setIdSearchRankingMetric($idSearchRankingMetric)
            ->setAutoTuneThreshold($autoTuneThreshold)
            ->setIsAutoUpdateEnabled($isAutoUpdateEnabled)
            ->setIsNotifyEnabled($isNotifyEnabled)
            ->setAutoUpdateScope($autoUpdateScope);
    }

    /**
     * @param int $idSearchRankingMetric
     * @param string|null $shape
     *
     * @return array{idSearchRankingMetric: int, name: string, formula: string, isHigherBetter: bool, shape: string|null}
     */
    protected function createMetricDetail(int $idSearchRankingMetric, ?string $shape = 'hyperbolic'): array
    {
        return [
            'idSearchRankingMetric' => $idSearchRankingMetric,
            'name' => 'top_seller',
            'formula' => 'x / (x + 6.42)',
            'isHigherBetter' => true,
            'shape' => $shape,
        ];
    }
}
