<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Persistence\Propel\Mapper;

use BlackboxOptimizer\Algorithm\Internal\TerminationReason;
use BlackboxOptimizer\Algorithm\RestartHistoryEntry;
use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationSearchTermTransfer;
use Generated\Shared\Transfer\SearchRankingSaturationPointCalibrationTransfer;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfig;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingEvaluation;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingOptimizerRun;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingSaturationPointCalibration;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingSaturationPointCalibrationSearchTerm;
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
 * @group NeedsDatabase
 */
class SearchRankingOptimizerMapperTest extends Unit
{
    public function testMapsCalibrationEntityFieldsOntoTheTransfer(): void
    {
        // Arrange
        $calibrationEntity = new SpySearchRankingSaturationPointCalibration();
        $calibrationEntity->setIdSearchRankingSaturationPointCalibration(4);
        $calibrationEntity->setRelevantProductCount(20);
        $calibrationEntity->setStoreName('DE');
        $calibrationEntity->setLocaleName('de_DE');
        $calibrationEntity->setStatus('finished');
        $calibrationEntity->setCalibrationType('relevance_score');
        $calibrationEntity->setComputedK(1.2);
        $calibrationEntity->setValueMin(1.0);
        $calibrationEntity->setValueMax(9.0);
        $calibrationEntity->setValueMean(5.0);
        $calibrationEntity->setValueMedian(5.0);
        $calibrationEntity->setValueP25(3.0);
        $calibrationEntity->setValueP75(7.0);
        $calibrationEntity->setSampleCount(20);
        $calibrationEntity->setCalculatedAt('2026-01-15 10:00:00');
        $calibrationEntity->setCreatedAt('2026-01-15 09:00:00');
        $calibrationEntity->setTotalCount(12);
        $calibrationEntity->setProcessedCount(7);

        // Act
        $calibrationTransfer = (new SearchRankingOptimizerMapper())->mapCalibrationEntityToTransfer(
            $calibrationEntity,
            new SearchRankingSaturationPointCalibrationTransfer(),
        );

        // Assert
        $this->assertSame(4, $calibrationTransfer->getIdSearchRankingSaturationPointCalibration());
        $this->assertSame(20, $calibrationTransfer->getRelevantProductCount());
        $this->assertSame('DE', $calibrationTransfer->getStoreName());
        $this->assertSame('de_DE', $calibrationTransfer->getLocaleName());
        $this->assertSame('finished', $calibrationTransfer->getStatus());
        $this->assertSame('relevance_score', $calibrationTransfer->getCalibrationType());
        $this->assertSame(1.2, $calibrationTransfer->getComputedK());
        $this->assertSame(1.0, $calibrationTransfer->getValueMin());
        $this->assertSame(9.0, $calibrationTransfer->getValueMax());
        $this->assertSame(5.0, $calibrationTransfer->getValueMean());
        $this->assertSame(3.0, $calibrationTransfer->getValueP25());
        $this->assertSame(7.0, $calibrationTransfer->getValueP75());
        $this->assertSame(20, $calibrationTransfer->getSampleCount());
        $this->assertStringStartsWith('2026-01-15T10:00:00', (string)$calibrationTransfer->getCalculatedAt());
        $this->assertSame(12, $calibrationTransfer->getTotalCount());
        $this->assertSame(7, $calibrationTransfer->getProcessedCount());
    }

    /**
     * `calculatedAt`/`createdAt` are nullable (e.g. a calibration that hasn't finished running yet) — the
     * nullsafe `?->format()` call must not throw.
     */
    public function testMapsCalibrationEntityWithNoTimestampsToNullDates(): void
    {
        // Arrange
        $calibrationEntity = new SpySearchRankingSaturationPointCalibration();
        $calibrationEntity->setStoreName('DE');
        $calibrationEntity->setLocaleName('de_DE');
        $calibrationEntity->setStatus('running');
        $calibrationEntity->setSampleCount(0);

        // Act
        $calibrationTransfer = (new SearchRankingOptimizerMapper())->mapCalibrationEntityToTransfer(
            $calibrationEntity,
            new SearchRankingSaturationPointCalibrationTransfer(),
        );

        // Assert
        $this->assertNull($calibrationTransfer->getCalculatedAt());
        $this->assertNull($calibrationTransfer->getCreatedAt());
    }

    public function testMapsCalibrationSearchTermEntityFieldsOntoTheTransferIncludingExplodedValues(): void
    {
        // Arrange
        $searchTermEntity = new SpySearchRankingSaturationPointCalibrationSearchTerm();
        $searchTermEntity->setIdSearchRankingSaturationPointCalibrationSearchTerm(9);
        $searchTermEntity->setFkSearchRankingSaturationPointCalibration(4);
        $searchTermEntity->setSearchTerm('cable tie');
        $searchTermEntity->setProductsFound(12);
        $searchTermEntity->setValues('1.5,2.5,3.5');

        // Act
        $searchTermTransfer = (new SearchRankingOptimizerMapper())->mapCalibrationSearchTermEntityToTransfer(
            $searchTermEntity,
            new SearchRankingSaturationPointCalibrationSearchTermTransfer(),
        );

        // Assert
        $this->assertSame(9, $searchTermTransfer->getIdSearchRankingSaturationPointCalibrationSearchTerm());
        $this->assertSame(4, $searchTermTransfer->getFkSearchRankingSaturationPointCalibration());
        $this->assertSame('cable tie', $searchTermTransfer->getSearchTerm());
        $this->assertSame(12, $searchTermTransfer->getProductsFound());
        $this->assertSame([1.5, 2.5, 3.5], $searchTermTransfer->getValues());
    }

    /**
     * A search term with no values yet must map to an empty array rather than `[0.0]` (which is what a
     * naive `explode(',', '')` would produce).
     */
    public function testMapsACalibrationSearchTermWithNoValuesToAnEmptyArray(): void
    {
        // Arrange
        $searchTermEntity = new SpySearchRankingSaturationPointCalibrationSearchTerm();
        $searchTermEntity->setSearchTerm('no results yet');
        $searchTermEntity->setProductsFound(0);
        $searchTermEntity->setValues(null);

        // Act
        $searchTermTransfer = (new SearchRankingOptimizerMapper())->mapCalibrationSearchTermEntityToTransfer(
            $searchTermEntity,
            new SearchRankingSaturationPointCalibrationSearchTermTransfer(),
        );

        // Assert
        $this->assertSame([], $searchTermTransfer->getValues());
    }

    public function testImplodeValuesJoinsValuesWithACommaSeparator(): void
    {
        // Act
        $values = (new SearchRankingOptimizerMapper())->implodeValues([1.5, 2.5, 3.5]);

        // Assert
        $this->assertSame('1.5,2.5,3.5', $values);
    }

    /**
     * An empty values array must become a genuine NULL, not an empty string, so "no values recorded"
     * stays distinguishable from a calibration search term that scored everything at zero.
     */
    public function testImplodeValuesReturnsNullForAnEmptyArray(): void
    {
        // Act
        $values = (new SearchRankingOptimizerMapper())->implodeValues([]);

        // Assert
        $this->assertNull($values);
    }

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

    public function testMapsAutoTuneMetricConfigEntityFieldsOntoTheTransfer(): void
    {
        // Arrange
        $autoTuneMetricConfigEntity = new SpySearchRankingAutoTuneMetricConfig();
        $autoTuneMetricConfigEntity->setIdSearchRankingAutoTuneMetricConfig(3);
        $autoTuneMetricConfigEntity->setFkSearchRankingMetric(7);
        $autoTuneMetricConfigEntity->setStoreName('DE');
        $autoTuneMetricConfigEntity->setLocaleName('de_DE');
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
        $this->assertSame('DE', $autoTuneMetricConfigTransfer->getStoreName());
        $this->assertSame('de_DE', $autoTuneMetricConfigTransfer->getLocaleName());
        $this->assertSame(0.8, $autoTuneMetricConfigTransfer->getAutoTuneThreshold());
        $this->assertTrue($autoTuneMetricConfigTransfer->getIsAutoUpdateEnabled());
        $this->assertSame(SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY, $autoTuneMetricConfigTransfer->getAutoUpdateScope());
        $this->assertFalse($autoTuneMetricConfigTransfer->getIsNotifyEnabled());
    }

    public function testMapsAutoTuneMetricConfigTransferFieldsOntoTheEntity(): void
    {
        // Arrange
        $autoTuneMetricConfigTransfer = (new SearchRankingAutoTuneMetricConfigTransfer())
            ->setIdSearchRankingMetric(7)
            ->setStoreName('DE')
            ->setLocaleName('de_DE')
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
        $this->assertSame('DE', $autoTuneMetricConfigEntity->getStoreName());
        $this->assertSame('de_DE', $autoTuneMetricConfigEntity->getLocaleName());
        $this->assertSame(0.8, $autoTuneMetricConfigEntity->getAutoTuneThreshold());
        $this->assertTrue($autoTuneMetricConfigEntity->getIsAutoUpdateEnabled());
        $this->assertSame(SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY, $autoTuneMetricConfigEntity->getAutoUpdateScope());
        $this->assertFalse($autoTuneMetricConfigEntity->getIsNotifyEnabled());
    }

    /**
     * A NULL threshold (opted-out metric) must map through as NULL, not silently coerced to 0.0.
     */
    public function testMapsANullAutoTuneThresholdAsNullNotZero(): void
    {
        // Arrange
        $autoTuneMetricConfigTransfer = (new SearchRankingAutoTuneMetricConfigTransfer())
            ->setIdSearchRankingMetric(7)
            ->setStoreName('DE')
            ->setLocaleName('de_DE')
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

    public function testMapsOptimizerRunEntityFieldsOntoTheTransferIncludingDecodedBestMetricWeights(): void
    {
        // Arrange
        $optimizerRunEntity = new SpySearchRankingOptimizerRun();
        $optimizerRunEntity->setIdSearchRankingOptimizerRun(3);
        $optimizerRunEntity->setStoreName('DE');
        $optimizerRunEntity->setLocaleName('en_US');
        $optimizerRunEntity->setAlgorithm(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES);
        $optimizerRunEntity->setIsTerminationCriteriaTrusted(true);
        $optimizerRunEntity->setIsRestartOnPlateauEnabled(true);
        $optimizerRunEntity->setWarmStartFraction(0.5);
        $optimizerRunEntity->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE);
        $optimizerRunEntity->setTotalCount(400);
        $optimizerRunEntity->setProcessedCount(400);
        $optimizerRunEntity->setGenerationsUsed(189);
        $optimizerRunEntity->setBaselineScore(0.65);
        $optimizerRunEntity->setBestRelevanceWeight(0.8);
        $optimizerRunEntity->setBestMetricWeights('[{"idSearchRankingMetric":1,"name":"top_seller","weight":0.6}]');
        $optimizerRunEntity->setRestartHistory('[{"restartIndex":0,"populationSize":6,"generationsAllowed":150,"generationsUsed":30,"terminationReason":"TOL_FUN","bestScore":0.75,"evaluationsConsumed":180,"improvedOverallBest":true}]');
        $optimizerRunEntity->setBestScore(0.91);
        $optimizerRunEntity->setBestSpecificityBlendWeight(0.75);
        $optimizerRunEntity->setBestSpecificityWeightExponent(1.2);
        $optimizerRunEntity->setBestSpecificityWeightShiftMagnitude(0.25);
        $optimizerRunEntity->setAppliedAt('2026-07-29 12:00:00');

        // Act
        $optimizerRunTransfer = (new SearchRankingOptimizerMapper())->mapOptimizerRunEntityToTransfer(
            $optimizerRunEntity,
            new SearchRankingOptimizerRunTransfer(),
        );

        // Assert
        $this->assertSame(3, $optimizerRunTransfer->getIdSearchRankingOptimizerRun());
        $this->assertSame('DE', $optimizerRunTransfer->getStoreName());
        $this->assertSame('en_US', $optimizerRunTransfer->getLocaleName());
        $this->assertSame(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES, $optimizerRunTransfer->getAlgorithm());
        $this->assertTrue($optimizerRunTransfer->getIsTerminationCriteriaTrusted());
        $this->assertTrue($optimizerRunTransfer->getIsRestartOnPlateauEnabled());
        $this->assertSame(0.5, $optimizerRunTransfer->getWarmStartFraction());
        $this->assertSame(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE, $optimizerRunTransfer->getStatus());
        $this->assertSame(400, $optimizerRunTransfer->getTotalCount());
        $this->assertSame(400, $optimizerRunTransfer->getProcessedCount());
        $this->assertSame(189, $optimizerRunTransfer->getGenerationsUsed());
        $this->assertSame(0.65, $optimizerRunTransfer->getBaselineScore());
        $this->assertSame(0.8, $optimizerRunTransfer->getBestRelevanceWeight());
        $this->assertSame(0.91, $optimizerRunTransfer->getBestScore());
        $this->assertSame(0.75, $optimizerRunTransfer->getBestSpecificityBlendWeight());
        $this->assertSame(1.2, $optimizerRunTransfer->getBestSpecificityWeightExponent());
        $this->assertSame(0.25, $optimizerRunTransfer->getBestSpecificityWeightShiftMagnitude());
        $this->assertNotNull($optimizerRunTransfer->getAppliedAt());

        $bestMetricWeights = iterator_to_array($optimizerRunTransfer->getBestMetricWeights());
        $this->assertCount(1, $bestMetricWeights);
        $this->assertSame('top_seller', $bestMetricWeights[0]->getName());
        $this->assertSame(0.6, $bestMetricWeights[0]->getWeight());

        $restartHistory = iterator_to_array($optimizerRunTransfer->getRestartHistory());
        $this->assertCount(1, $restartHistory);
        $this->assertSame(0, $restartHistory[0]->getRestartIndex());
        $this->assertSame(6, $restartHistory[0]->getPopulationSize());
        $this->assertSame(150, $restartHistory[0]->getGenerationsAllowed());
        $this->assertSame(30, $restartHistory[0]->getGenerationsUsed());
        $this->assertSame('TOL_FUN', $restartHistory[0]->getTerminationReason());
        $this->assertSame(0.75, $restartHistory[0]->getBestScore());
        $this->assertSame(180, $restartHistory[0]->getEvaluationsConsumed());
        $this->assertTrue($restartHistory[0]->getImprovedOverallBest());
    }

    public function testMapsAnOptimizerRunWithNoBestMetricWeightsYetToAnEmptyCollection(): void
    {
        // Arrange
        $optimizerRunEntity = new SpySearchRankingOptimizerRun();
        $optimizerRunEntity->setIdSearchRankingOptimizerRun(4);
        $optimizerRunEntity->setStoreName('DE');
        $optimizerRunEntity->setLocaleName('en_US');
        $optimizerRunEntity->setAlgorithm(SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION);
        $optimizerRunEntity->setStatus(SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_QUEUED);

        // Act
        $optimizerRunTransfer = (new SearchRankingOptimizerMapper())->mapOptimizerRunEntityToTransfer(
            $optimizerRunEntity,
            new SearchRankingOptimizerRunTransfer(),
        );

        // Assert
        $this->assertCount(0, iterator_to_array($optimizerRunTransfer->getBestMetricWeights()));
        $this->assertCount(0, iterator_to_array($optimizerRunTransfer->getRestartHistory()));
    }

    public function testEncodeRestartHistoryReturnsNullForAnEmptyArray(): void
    {
        // Act
        $encoded = (new SearchRankingOptimizerMapper())->encodeRestartHistory([]);

        // Assert
        $this->assertNull($encoded);
    }

    public function testEncodeRestartHistoryEncodesEachEntryIncludingTheNegatedBestValue(): void
    {
        // Arrange
        $restartHistoryEntry = new RestartHistoryEntry(1, 12, 60, 40, TerminationReason::TOL_FUN, -0.752, 480, true);

        // Act
        $encoded = (new SearchRankingOptimizerMapper())->encodeRestartHistory([$restartHistoryEntry]);
        $decoded = json_decode((string)$encoded, true);

        // Assert
        $this->assertCount(1, $decoded);
        $this->assertSame(1, $decoded[0]['restartIndex']);
        $this->assertSame(12, $decoded[0]['populationSize']);
        $this->assertSame(60, $decoded[0]['generationsAllowed']);
        $this->assertSame(40, $decoded[0]['generationsUsed']);
        $this->assertSame('TOL_FUN', $decoded[0]['terminationReason']);
        // Every blackbox-optimizer algorithm MINIMIZES, so a negative bestValueAtStop (a real, "good" nDCG
        // once negated back) must come out POSITIVE here -- see this method's own docblock.
        $this->assertSame(0.752, $decoded[0]['bestScore']);
        $this->assertSame(480, $decoded[0]['evaluationsConsumed']);
        $this->assertTrue($decoded[0]['improvedOverallBest']);
    }
}
