<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\Presentation;

use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\AutoTunePage;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester;

/**
 * Checklist section 07 - AUTO-TUNE: default-opt-out safe no-op, the "random" metric never getting a row
 * at all, and a threshold round-trip (set + save + verify persisted, then cleared back to blank so this
 * test leaves the environment as it found it).
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizerGuiPresentation
 * @group Presentation
 * @group AutoTuneCest
 * Add your own group annotations below this line
 */
class AutoTuneCest
{
    /**
     * @param \SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester $i
     */
    public function _before(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        $i->amZed();
        $i->amLoggedInUser();
    }

    /**
     * @param \SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester $i
     */
    public function randomMetricNeverGetsItsOwnRow(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        $i->amOnPage(AutoTunePage::URL);
        $i->dontSeeElement(AutoTunePage::rowXpath(AutoTunePage::RANDOM_METRIC_NAME));
    }

    /**
     * @param \SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester $i
     */
    public function runningWithNoThresholdsSetIsASafeNoOp(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        $output = $i->runConsoleCommand(AutoTunePage::CONSOLE_COMMAND_AUTO_TUNE);
        $lowerOutput = strtolower($output);
        // Exits cleanly - no PHP fatal/uncaught-exception noise in the combined stdout+stderr.
        $i->assertFalse(str_contains($lowerOutput, 'fatal error'), $output);
        $i->assertFalse(str_contains($lowerOutput, 'uncaught'), $output);
    }

    /**
     * @param \SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester $i
     */
    public function settingAThresholdPersistsAndCanBeClearedAgain(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        $metricName = 'top_seller';

        $i->amOnPage(AutoTunePage::URL);
        $i->waitForElementVisible(AutoTunePage::rowXpath($metricName), 10);

        $i->fillField(AutoTunePage::thresholdFieldXpath($metricName), '0.95');
        // The Symfony debug toolbar is fixed to the viewport bottom and can intercept a plain click on a
        // button that ends up positioned right under it — scroll extra so the row clears that zone first.
        $i->scrollTo(AutoTunePage::saveButtonXpath($metricName), 0, -150);
        $i->click(AutoTunePage::saveButtonXpath($metricName));
        $i->see(AutoTunePage::FLASH_MESSAGE_SAVED);

        $i->amOnPage(AutoTunePage::URL);
        $i->waitForElementVisible(AutoTunePage::thresholdFieldXpath($metricName), 10);
        // Compared as a float, not a literal string: the field's 4-decimal scale can re-render "0.95"
        // as "0.9500" (same gotcha hit on CheckpointCest's/QueryCest's own NumberType fields).
        $i->assertSame(0.95, (float)$i->grabValueFrom(AutoTunePage::thresholdFieldXpath($metricName)));

        // Round-trip: opt back out (blank threshold), leaving the environment as found.
        $i->fillField(AutoTunePage::thresholdFieldXpath($metricName), '');
        $i->scrollTo(AutoTunePage::saveButtonXpath($metricName), 0, -150);
        $i->click(AutoTunePage::saveButtonXpath($metricName));
        $i->see(AutoTunePage::FLASH_MESSAGE_SAVED);
    }
}
