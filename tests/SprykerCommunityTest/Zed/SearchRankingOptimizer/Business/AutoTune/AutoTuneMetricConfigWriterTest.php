<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\AutoTune;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneMetricConfigWriter;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group AutoTune
 * @group AutoTuneMetricConfigWriterTest
 * Add your own group annotations below this line
 */
class AutoTuneMetricConfigWriterTest extends Unit
{
    /**
     * A store-wide metric (isLocaleScoped=false) fans the SAME config out to every real locale
     * resolveEffectiveWeightLocales() returns — one entityManager save per locale, none of them carrying
     * over a stale id from the incoming transfer.
     */
    public function testFansOutToEveryEffectiveLocaleForAStoreWideMetric(): void
    {
        // Arrange
        $autoTuneMetricConfigTransfer = (new SearchRankingAutoTuneMetricConfigTransfer())
            ->setIdSearchRankingAutoTuneMetricConfig(999)
            ->setIdSearchRankingMetric(7)
            ->setStoreName('DE')
            ->setLocaleName('de_DE')
            ->setAutoTuneThreshold(0.8)
            ->setIsAutoUpdateEnabled(true)
            ->setIsNotifyEnabled(false);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('resolveEffectiveWeightLocales')
            ->with(7, 'DE', 'de_DE')
            ->willReturn(['de_DE', 'en_US']);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->exactly(2))
            ->method('saveAutoTuneMetricConfig')
            ->with($this->callback(fn (SearchRankingAutoTuneMetricConfigTransfer $transfer): bool => $transfer->getIdSearchRankingAutoTuneMetricConfig() === null
                && $transfer->getAutoTuneThreshold() === 0.8))
            ->willReturnCallback(fn (SearchRankingAutoTuneMetricConfigTransfer $transfer) => $transfer);

        $writer = new AutoTuneMetricConfigWriter($entityManagerMock, $searchRankingFacadeMock);

        // Act
        $savedTransfersByLocale = $writer->save($autoTuneMetricConfigTransfer);

        // Assert
        $this->assertSame(['de_DE', 'en_US'], array_keys($savedTransfersByLocale));
        $this->assertSame('de_DE', $savedTransfersByLocale['de_DE']->getLocaleName());
        $this->assertSame('en_US', $savedTransfersByLocale['en_US']->getLocaleName());
    }

    /**
     * A genuinely locale-scoped metric (isLocaleScoped=true) only ever writes the one locale it was given —
     * resolveEffectiveWeightLocales() returning just [$localeName] means a sibling locale of the same store
     * is never touched by this save.
     */
    public function testWritesOnlyTheGivenLocaleForALocaleScopedMetric(): void
    {
        // Arrange
        $autoTuneMetricConfigTransfer = (new SearchRankingAutoTuneMetricConfigTransfer())
            ->setIdSearchRankingMetric(7)
            ->setStoreName('DE')
            ->setLocaleName('en_US')
            ->setAutoTuneThreshold(0.5)
            ->setIsAutoUpdateEnabled(false)
            ->setIsNotifyEnabled(false);

        $searchRankingFacadeMock = $this->createMock(SearchRankingOptimizerToSearchRankingFacadeInterface::class);
        $searchRankingFacadeMock->method('resolveEffectiveWeightLocales')
            ->with(7, 'DE', 'en_US')
            ->willReturn(['en_US']);

        $entityManagerMock = $this->createMock(SearchRankingOptimizerEntityManagerInterface::class);
        $entityManagerMock->expects($this->once())
            ->method('saveAutoTuneMetricConfig')
            ->willReturnCallback(fn (SearchRankingAutoTuneMetricConfigTransfer $transfer) => $transfer);

        $writer = new AutoTuneMetricConfigWriter($entityManagerMock, $searchRankingFacadeMock);

        // Act
        $savedTransfersByLocale = $writer->save($autoTuneMetricConfigTransfer);

        // Assert
        $this->assertSame(['en_US'], array_keys($savedTransfersByLocale));
    }
}
