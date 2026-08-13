<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Evaluation;

use Codeception\Test\Unit;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RelevanceJudgmentGainMapper;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Evaluation
 * @group RelevanceJudgmentGainMapperTest
 * Add your own group annotations below this line
 * @group Portable
 */
class RelevanceJudgmentGainMapperTest extends Unit
{
    public function testMapRatingTypeMapsHeartToItsConfiguredGain(): void
    {
        // Act
        $gain = (new RelevanceJudgmentGainMapper())->mapRatingType(SearchRankingOptimizerConfig::RATING_TYPE_HEART);

        // Assert
        $this->assertSame(SearchRankingOptimizerConfig::getRelevanceJudgmentGainMap()[SearchRankingOptimizerConfig::RATING_TYPE_HEART], $gain);
    }

    public function testMapRatingTypeMapsCheckToItsConfiguredGain(): void
    {
        // Act
        $gain = (new RelevanceJudgmentGainMapper())->mapRatingType(SearchRankingOptimizerConfig::RATING_TYPE_CHECK);

        // Assert
        $this->assertSame(SearchRankingOptimizerConfig::getRelevanceJudgmentGainMap()[SearchRankingOptimizerConfig::RATING_TYPE_CHECK], $gain);
    }

    public function testMapRatingTypeMapsXToItsConfiguredGain(): void
    {
        // Act
        $gain = (new RelevanceJudgmentGainMapper())->mapRatingType(SearchRankingOptimizerConfig::RATING_TYPE_X);

        // Assert
        $this->assertSame(SearchRankingOptimizerConfig::getRelevanceJudgmentGainMap()[SearchRankingOptimizerConfig::RATING_TYPE_X], $gain);
    }

    public function testMapRatingTypeReturnsZeroForAnUnrecognizedRatingType(): void
    {
        // Act
        $gain = (new RelevanceJudgmentGainMapper())->mapRatingType('not-a-real-rating-type');

        // Assert
        $this->assertSame(0.0, $gain);
    }
}
