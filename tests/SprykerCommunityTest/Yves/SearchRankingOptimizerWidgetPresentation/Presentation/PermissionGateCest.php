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
 * Checklist section 10-1 - the rating widget's own negative test: no RateSearchRelevancePermissionPlugin,
 * no buttons at all.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Yves
 * @group SearchRankingOptimizerWidgetPresentation
 * @group Presentation
 * @group PermissionGateCest
 * Add your own group annotations below this line
 */
class PermissionGateCest
{
    /**
     * @param \SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\SearchRankingOptimizerWidgetPresentationTester $i
     *
     * @return void
     */
    public function _before(SearchRankingOptimizerWidgetPresentationTester $i): void
    {
        $i->amYves();
    }

    /**
     * @param \SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\SearchRankingOptimizerWidgetPresentationTester $i
     *
     * @return void
     */
    public function anonymousShopperSeesNoRatingButtons(SearchRankingOptimizerWidgetPresentationTester $i): void
    {
        $i->amOnPage(SearchResultsPage::URL_CHAIR);
        $i->dontSeeElement(SearchResultsPage::SELECTOR_HEART_BUTTON);
        $i->dontSeeElement(SearchResultsPage::SELECTOR_CHECK_BUTTON);
        $i->dontSeeElement(SearchResultsPage::SELECTOR_X_BUTTON);
    }

    /**
     * @param \SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\SearchRankingOptimizerWidgetPresentationTester $i
     *
     * @return void
     */
    public function loggedInCustomerWithoutTheRoleSeesNoRatingButtons(SearchRankingOptimizerWidgetPresentationTester $i): void
    {
        $i->loginAsCustomer(SearchRankingOptimizerWidgetPresentationTester::UNPERMITTED_CUSTOMER_EMAIL);
        $i->amOnPage(SearchResultsPage::URL_CHAIR);
        $i->dontSeeElement(SearchResultsPage::SELECTOR_HEART_BUTTON);
    }

    /**
     * @param \SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\SearchRankingOptimizerWidgetPresentationTester $i
     *
     * @return void
     */
    public function permittedCustomerDoesSeeIt(SearchRankingOptimizerWidgetPresentationTester $i): void
    {
        // Positive control for the two negative tests above.
        $i->loginAsCustomer(SearchRankingOptimizerWidgetPresentationTester::PERMITTED_CUSTOMER_EMAIL);
        $i->amOnPage(SearchResultsPage::URL_CHAIR);
        $i->waitForElementVisible(SearchResultsPage::SELECTOR_HEART_BUTTON, 10);
    }
}
