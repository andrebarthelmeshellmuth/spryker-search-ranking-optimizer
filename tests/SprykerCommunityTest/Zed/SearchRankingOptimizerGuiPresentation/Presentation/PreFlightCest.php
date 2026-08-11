<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\Presentation;

use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\AutoTunePage;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\CalibrationPage;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\CheckpointPage;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\EvaluationPage;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\OptimizationPage;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\QueryPage;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\RatingPage;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester;

/**
 * Checklist section 00 - PRE-FLIGHT: all 7 Zed pages load without error (empty tables are fine — this
 * covers wiring, not data).
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizerGuiPresentation
 * @group Presentation
 * @group PreFlightCest
 * Add your own group annotations below this line
 */
class PreFlightCest
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
    public function allSevenPagesLoadWithoutError(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        $i->amOnPage(CalibrationPage::URL);
        $i->see('Saturation Point Calibration');

        $i->amOnPage(QueryPage::URL_LIST);
        $i->waitForElementVisible('.dt-container', 10);

        $i->amOnPage(RatingPage::URL);
        $i->waitForElementVisible('.dt-container', 10);

        $i->amOnPage(EvaluationPage::URL);
        $i->see('Test Current Evaluation');

        $i->amOnPage(CheckpointPage::URL);
        $i->see('Weight Checkpoints');

        $i->amOnPage(AutoTunePage::URL);
        $i->see('Auto-Tune Settings');

        $i->amOnPage(OptimizationPage::URL);
        $i->see('Automated Weight Optimization');
    }

    /**
     * @param \SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester $i
     */
    public function sidebarListsAllSevenPages(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        // The sidebar renders the labels from the package's own Communication/navigation.xml verbatim, so
        // these assert those labels exactly rather than the page headings, which word some of them
        // differently (the Auto-tune page's own heading reads "Auto-Tune Settings").
        $i->amOnPage(CalibrationPage::URL);
        $i->see('Search Ranking Optimizer');
        $i->see('Calibration');
        $i->see('Assess Rated Queries');
        $i->see('Ratings');
        $i->see('Test Current Evaluation');
        $i->see('Weight Checkpoints');
        $i->see('Auto-tune metrics settings');
        $i->see('Automated Weight Optimization');
    }
}
