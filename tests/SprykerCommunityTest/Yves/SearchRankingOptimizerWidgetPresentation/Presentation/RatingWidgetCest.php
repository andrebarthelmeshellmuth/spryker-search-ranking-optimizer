<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\Presentation;

use SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\PageObject\SearchResultsPage;
use SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\SearchRankingOptimizerWidgetPresentationTester;

/**
 * Checklist section 02 - SRP RATING WIDGET: the heart/check/X buttons on the storefront. Every test here
 * un-rates whatever it rated before finishing, so this suite leaves the environment as it found it (and
 * incidentally keeps the Zed QueryCest/EvaluationCest suites honest about which query really has a
 * fresh, real rating versus stale leftovers from a previous run).
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Yves
 * @group SearchRankingOptimizerWidgetPresentation
 * @group Presentation
 * @group RatingWidgetCest
 * Add your own group annotations below this line
 */
class RatingWidgetCest
{
    /**
     * @param \SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\SearchRankingOptimizerWidgetPresentationTester $i
     */
    public function _before(SearchRankingOptimizerWidgetPresentationTester $i): void
    {
        $i->amYves();
        $i->loginAsCustomer(SearchRankingOptimizerWidgetPresentationTester::PERMITTED_CUSTOMER_EMAIL);
    }

    /**
     * @param \SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\SearchRankingOptimizerWidgetPresentationTester $i
     */
    public function clickingHeartColorizesAndPersistsAcrossReload(SearchRankingOptimizerWidgetPresentationTester $i): void
    {
        $i->amOnPage(SearchResultsPage::URL_CHAIR);
        $i->waitForElementVisible(SearchResultsPage::SELECTOR_HEART_BUTTON, 10);

        $i->click(SearchResultsPage::SELECTOR_HEART_BUTTON);
        $i->wait(1);
        $i->seeElement(SearchResultsPage::SELECTOR_HEART_BUTTON . '[aria-pressed="true"]');

        $i->amOnPage(SearchResultsPage::URL_CHAIR);
        $i->waitForElementVisible(SearchResultsPage::SELECTOR_HEART_BUTTON, 10);
        $i->seeElement(SearchResultsPage::SELECTOR_HEART_BUTTON . '[aria-pressed="true"]');

        // Clean up: clicking the already-active button again removes the rating (checklist card o2-2).
        $i->click(SearchResultsPage::SELECTOR_HEART_BUTTON);
        $i->wait(1);
        $i->seeElement(SearchResultsPage::SELECTOR_HEART_BUTTON . '[aria-pressed="false"]');

        $i->amOnPage(SearchResultsPage::URL_CHAIR);
        $i->waitForElementVisible(SearchResultsPage::SELECTOR_HEART_BUTTON, 10);
        $i->seeElement(SearchResultsPage::SELECTOR_HEART_BUTTON . '[aria-pressed="false"]');
    }

    /**
     * @param \SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\SearchRankingOptimizerWidgetPresentationTester $i
     */
    public function onlyOneButtonStaysActivePerProduct(SearchRankingOptimizerWidgetPresentationTester $i): void
    {
        $i->amOnPage(SearchResultsPage::URL_CHAIR);
        $i->waitForElementVisible(SearchResultsPage::SELECTOR_CHECK_BUTTON, 10);

        $i->click(SearchResultsPage::SELECTOR_CHECK_BUTTON);
        $i->wait(1);
        $i->seeElement(SearchResultsPage::SELECTOR_CHECK_BUTTON . '[aria-pressed="true"]');
        $i->seeElement(SearchResultsPage::SELECTOR_HEART_BUTTON . '[aria-pressed="false"]');
        $i->seeElement(SearchResultsPage::SELECTOR_X_BUTTON . '[aria-pressed="false"]');

        $i->click(SearchResultsPage::SELECTOR_X_BUTTON);
        $i->wait(1);
        $i->seeElement(SearchResultsPage::SELECTOR_X_BUTTON . '[aria-pressed="true"]');
        $i->seeElement(SearchResultsPage::SELECTOR_CHECK_BUTTON . '[aria-pressed="false"]');

        // Clean up.
        $i->click(SearchResultsPage::SELECTOR_X_BUTTON);
        $i->wait(1);
        $i->seeElement(SearchResultsPage::SELECTOR_X_BUTTON . '[aria-pressed="false"]');
    }

    /**
     * @param \SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\SearchRankingOptimizerWidgetPresentationTester $i
     */
    public function coexistsWithTheSearchDebugOverlayOnTheSameTile(SearchRankingOptimizerWidgetPresentationTester $i): void
    {
        $i->amOnPage(SearchResultsPage::URL_CHAIR_WITH_SEARCH_DEBUG);
        $i->waitForElementVisible(SearchResultsPage::SELECTOR_SCORE_TRIGGER, 10);

        // Both this widget's rating buttons and search-debug's own score trigger render fully on the
        // same product tile — this demoshop's page-layout-catalog.scss carries a scoped fix for exactly
        // this (a wrapper-height bug that shipped once already, per the checklist).
        $i->seeElement(SearchResultsPage::SELECTOR_SCORE_TRIGGER);
        $i->seeElement(SearchResultsPage::SELECTOR_HEART_BUTTON);
        $i->seeElement(SearchResultsPage::SELECTOR_CHECK_BUTTON);
        $i->seeElement(SearchResultsPage::SELECTOR_X_BUTTON);
    }
}
