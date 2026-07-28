<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Persistence\Propel\Mapper;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Generated\Shared\Transfer\SearchRankingCalibrationSearchTermTransfer;
use Generated\Shared\Transfer\SearchRankingCalibrationTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfig;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibration;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingCalibrationSearchTerm;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingEvaluation;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\Propel\Mapper\SearchRankingOptimizerMapper;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Persistence
 * @group Propel
 * @group Mapper
 * @group SearchRankingOptimizerMapperTest
 * Add your own group annotations below this line
 */
class SearchRankingOptimizerMapperTest extends Unit
{
    /**
     * @return void
     */
    public function testMapsCalibrationEntityFieldsOntoTheTransfer(): void
    {
        // Arrange
        $calibrationEntity = new SpySearchRankingCalibration();
        $calibrationEntity->setIdSearchRankingCalibration(4);
        $calibrationEntity->setRelevantProductCount(20);
        $calibrationEntity->setStoreName('DE');
        $calibrationEntity->setLocaleName('de_DE');
        $calibrationEntity->setStatus('finished');
        $calibrationEntity->setComputedK(1.2);
        $calibrationEntity->setScoreMin(1.0);
        $calibrationEntity->setScoreMax(9.0);
        $calibrationEntity->setScoreMean(5.0);
        $calibrationEntity->setScoreMedian(5.0);
        $calibrationEntity->setScoreP25(3.0);
        $calibrationEntity->setScoreP75(7.0);
        $calibrationEntity->setSampleCount(20);
        $calibrationEntity->setCalculatedAt('2026-01-15 10:00:00');
        $calibrationEntity->setCreatedAt('2026-01-15 09:00:00');
        $calibrationEntity->setTotalCount(12);
        $calibrationEntity->setProcessedCount(7);

        // Act
        $calibrationTransfer = (new SearchRankingOptimizerMapper())->mapCalibrationEntityToTransfer(
            $calibrationEntity,
            new SearchRankingCalibrationTransfer(),
        );

        // Assert
        $this->assertSame(4, $calibrationTransfer->getIdSearchRankingCalibration());
        $this->assertSame(20, $calibrationTransfer->getRelevantProductCount());
        $this->assertSame('DE', $calibrationTransfer->getStoreName());
        $this->assertSame('de_DE', $calibrationTransfer->getLocaleName());
        $this->assertSame('finished', $calibrationTransfer->getStatus());
        $this->assertSame(1.2, $calibrationTransfer->getComputedK());
        $this->assertSame(1.0, $calibrationTransfer->getScoreMin());
        $this->assertSame(9.0, $calibrationTransfer->getScoreMax());
        $this->assertSame(5.0, $calibrationTransfer->getScoreMean());
        $this->assertSame(3.0, $calibrationTransfer->getScoreP25());
        $this->assertSame(7.0, $calibrationTransfer->getScoreP75());
        $this->assertSame(20, $calibrationTransfer->getSampleCount());
        $this->assertStringStartsWith('2026-01-15T10:00:00', (string)$calibrationTransfer->getCalculatedAt());
        $this->assertSame(12, $calibrationTransfer->getTotalCount());
        $this->assertSame(7, $calibrationTransfer->getProcessedCount());
    }

    /**
     * `calculatedAt`/`createdAt` are nullable (e.g. a calibration that hasn't finished running yet) — the
     * nullsafe `?->format()` call must not throw.
     *
     * @return void
     */
    public function testMapsCalibrationEntityWithNoTimestampsToNullDates(): void
    {
        // Arrange
        $calibrationEntity = new SpySearchRankingCalibration();
        $calibrationEntity->setStoreName('DE');
        $calibrationEntity->setLocaleName('de_DE');
        $calibrationEntity->setStatus('running');
        $calibrationEntity->setSampleCount(0);

        // Act
        $calibrationTransfer = (new SearchRankingOptimizerMapper())->mapCalibrationEntityToTransfer(
            $calibrationEntity,
            new SearchRankingCalibrationTransfer(),
        );

        // Assert
        $this->assertNull($calibrationTransfer->getCalculatedAt());
        $this->assertNull($calibrationTransfer->getCreatedAt());
    }

    /**
     * @return void
     */
    public function testMapsCalibrationSearchTermEntityFieldsOntoTheTransferIncludingExplodedScores(): void
    {
        // Arrange
        $searchTermEntity = new SpySearchRankingCalibrationSearchTerm();
        $searchTermEntity->setIdSearchRankingCalibrationSearchTerm(9);
        $searchTermEntity->setFkSearchRankingCalibration(4);
        $searchTermEntity->setSearchTerm('cable tie');
        $searchTermEntity->setProductsFound(12);
        $searchTermEntity->setScores('1.5,2.5,3.5');

        // Act
        $searchTermTransfer = (new SearchRankingOptimizerMapper())->mapCalibrationSearchTermEntityToTransfer(
            $searchTermEntity,
            new SearchRankingCalibrationSearchTermTransfer(),
        );

        // Assert
        $this->assertSame(9, $searchTermTransfer->getIdSearchRankingCalibrationSearchTerm());
        $this->assertSame(4, $searchTermTransfer->getFkSearchRankingCalibration());
        $this->assertSame('cable tie', $searchTermTransfer->getSearchTerm());
        $this->assertSame(12, $searchTermTransfer->getProductsFound());
        $this->assertSame([1.5, 2.5, 3.5], $searchTermTransfer->getScores());
    }

    /**
     * A search term with no scores yet must map to an empty array rather than `[0.0]` (which is what a
     * naive `explode(',', '')` would produce).
     *
     * @return void
     */
    public function testMapsACalibrationSearchTermWithNoScoresToAnEmptyArray(): void
    {
        // Arrange
        $searchTermEntity = new SpySearchRankingCalibrationSearchTerm();
        $searchTermEntity->setSearchTerm('no results yet');
        $searchTermEntity->setProductsFound(0);
        $searchTermEntity->setScores(null);

        // Act
        $searchTermTransfer = (new SearchRankingOptimizerMapper())->mapCalibrationSearchTermEntityToTransfer(
            $searchTermEntity,
            new SearchRankingCalibrationSearchTermTransfer(),
        );

        // Assert
        $this->assertSame([], $searchTermTransfer->getScores());
    }

    /**
     * @return void
     */
    public function testImplodeScoresJoinsScoresWithACommaSeparator(): void
    {
        // Act
        $scores = (new SearchRankingOptimizerMapper())->implodeScores([1.5, 2.5, 3.5]);

        // Assert
        $this->assertSame('1.5,2.5,3.5', $scores);
    }

    /**
     * An empty scores array must become a genuine NULL, not an empty string, so "no scores recorded"
     * stays distinguishable from a calibration search term that scored everything at zero.
     *
     * @return void
     */
    public function testImplodeScoresReturnsNullForAnEmptyArray(): void
    {
        // Act
        $scores = (new SearchRankingOptimizerMapper())->implodeScores([]);

        // Assert
        $this->assertNull($scores);
    }

    /**
     * @return void
     */
    public function testMapsEvaluationEntityFieldsOntoTheTransfer(): void
    {
        // Arrange
        $evaluationEntity = new SpySearchRankingEvaluation();
        $evaluationEntity->setIdSearchRankingEvaluation(7);
        $evaluationEntity->setStoreName('DE');
        $evaluationEntity->setLocaleName('en_US');
        $evaluationEntity->setMetricScore(0.7123);
        $evaluationEntity->setQueryCount(12);

        // Act
        $evaluationTransfer = (new SearchRankingOptimizerMapper())->mapEvaluationEntityToTransfer(
            $evaluationEntity,
            new SearchRankingEvaluationTransfer(),
        );

        // Assert
        $this->assertSame(7, $evaluationTransfer->getIdSearchRankingEvaluation());
        $this->assertSame('DE', $evaluationTransfer->getStoreName());
        $this->assertSame('en_US', $evaluationTransfer->getLocaleName());
        $this->assertSame(0.7123, $evaluationTransfer->getMetricScore());
        $this->assertSame(12, $evaluationTransfer->getQueryCount());
    }

    /**
     * @return void
     */
    public function testMapsAutoTuneMetricConfigEntityFieldsOntoTheTransfer(): void
    {
        // Arrange
        $autoTuneMetricConfigEntity = new SpySearchRankingAutoTuneMetricConfig();
        $autoTuneMetricConfigEntity->setIdSearchRankingAutoTuneMetricConfig(3);
        $autoTuneMetricConfigEntity->setFkSearchRankingMetric(7);
        $autoTuneMetricConfigEntity->setAutoTuneThreshold(0.8);
        $autoTuneMetricConfigEntity->setIsAutoUpdateEnabled(true);
        $autoTuneMetricConfigEntity->setAutoUpdateScope(SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY);
        $autoTuneMetricConfigEntity->setIsNotifyEnabled(false);

        // Act
        $autoTuneMetricConfigTransfer = (new SearchRankingOptimizerMapper())->mapAutoTuneMetricConfigEntityToTransfer(
            $autoTuneMetricConfigEntity,
            new SearchRankingAutoTuneMetricConfigTransfer(),
        );

        // Assert
        $this->assertSame(3, $autoTuneMetricConfigTransfer->getIdSearchRankingAutoTuneMetricConfig());
        $this->assertSame(7, $autoTuneMetricConfigTransfer->getIdSearchRankingMetric());
        $this->assertSame(0.8, $autoTuneMetricConfigTransfer->getAutoTuneThreshold());
        $this->assertTrue($autoTuneMetricConfigTransfer->getIsAutoUpdateEnabled());
        $this->assertSame(SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY, $autoTuneMetricConfigTransfer->getAutoUpdateScope());
        $this->assertFalse($autoTuneMetricConfigTransfer->getIsNotifyEnabled());
    }

    /**
     * @return void
     */
    public function testMapsAutoTuneMetricConfigTransferFieldsOntoTheEntity(): void
    {
        // Arrange
        $autoTuneMetricConfigTransfer = (new SearchRankingAutoTuneMetricConfigTransfer())
            ->setIdSearchRankingMetric(7)
            ->setAutoTuneThreshold(0.8)
            ->setIsAutoUpdateEnabled(true)
            ->setAutoUpdateScope(SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY)
            ->setIsNotifyEnabled(false);

        // Act
        $autoTuneMetricConfigEntity = (new SearchRankingOptimizerMapper())->mapAutoTuneMetricConfigTransferToEntity(
            $autoTuneMetricConfigTransfer,
            new SpySearchRankingAutoTuneMetricConfig(),
        );

        // Assert
        $this->assertSame(7, $autoTuneMetricConfigEntity->getFkSearchRankingMetric());
        $this->assertSame(0.8, $autoTuneMetricConfigEntity->getAutoTuneThreshold());
        $this->assertTrue($autoTuneMetricConfigEntity->getIsAutoUpdateEnabled());
        $this->assertSame(SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY, $autoTuneMetricConfigEntity->getAutoUpdateScope());
        $this->assertFalse($autoTuneMetricConfigEntity->getIsNotifyEnabled());
    }

    /**
     * A NULL threshold (opted-out metric) must map through as NULL, not silently coerced to 0.0.
     *
     * @return void
     */
    public function testMapsANullAutoTuneThresholdAsNullNotZero(): void
    {
        // Arrange
        $autoTuneMetricConfigTransfer = (new SearchRankingAutoTuneMetricConfigTransfer())
            ->setIdSearchRankingMetric(7)
            ->setAutoTuneThreshold(null)
            ->setIsAutoUpdateEnabled(false)
            ->setAutoUpdateScope(SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE)
            ->setIsNotifyEnabled(false);

        // Act
        $autoTuneMetricConfigEntity = (new SearchRankingOptimizerMapper())->mapAutoTuneMetricConfigTransferToEntity(
            $autoTuneMetricConfigTransfer,
            new SpySearchRankingAutoTuneMetricConfig(),
        );

        // Assert
        $this->assertNull($autoTuneMetricConfigEntity->getAutoTuneThreshold());
    }
}
