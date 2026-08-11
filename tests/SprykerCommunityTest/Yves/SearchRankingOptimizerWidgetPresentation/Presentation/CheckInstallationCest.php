<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\Presentation;

use SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\PageObject\CheckInstallationPage;
use SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\SearchRankingOptimizerWidgetPresentationTester;

/**
 * The Yves installation-check page itself. Mirrors the sibling spryker-community/search-debug package's
 * identical Cest: the CLI check-installation command is out of scope for a browser suite.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Yves
 * @group SearchRankingOptimizerWidgetPresentation
 * @group Presentation
 * @group CheckInstallationCest
 * Add your own group annotations below this line
 */
class CheckInstallationCest
{
    /**
     * @param \SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\SearchRankingOptimizerWidgetPresentationTester $i
     */
    public function _before(SearchRankingOptimizerWidgetPresentationTester $i): void
    {
        $i->amYves();
    }

    /**
     * @param \SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\SearchRankingOptimizerWidgetPresentationTester $i
     */
    public function loggedOutVisitorSeesPermissionDenied(SearchRankingOptimizerWidgetPresentationTester $i): void
    {
        $i->amOnPage(CheckInstallationPage::URL);
        $i->see(CheckInstallationPage::PERMISSION_DENIED_HEADING);
        $i->dontSeeElement(CheckInstallationPage::SELECTOR_CONTAINER . ' .check-installation-page__list');
    }

    /**
     * @param \SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\SearchRankingOptimizerWidgetPresentationTester $i
     */
    public function permittedCustomerSeesTheRealChecklist(SearchRankingOptimizerWidgetPresentationTester $i): void
    {
        $i->loginAsCustomer(SearchRankingOptimizerWidgetPresentationTester::PERMITTED_CUSTOMER_EMAIL);
        $i->amOnPage(CheckInstallationPage::URL);
        $i->dontSee(CheckInstallationPage::PERMISSION_DENIED_HEADING);
        $i->seeElement(CheckInstallationPage::SELECTOR_CONTAINER);
        // At least one check row (passed or failed) must render — this confirms the page is wired, not
        // that every check passes; the CLI command judges that in detail.
        $i->assertTrue(
            $i->tryToSeeElement(CheckInstallationPage::SELECTOR_CHECK_PASSED)
            || $i->tryToSeeElement(CheckInstallationPage::SELECTOR_CHECK_FAILED),
        );
    }

    /**
     * Every check row that failed must carry a remedy — a red row with no instruction is worse than no
     * check at all, and nothing else in either suite asserts the twig actually renders the remedy.
     *
     * @param \SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\SearchRankingOptimizerWidgetPresentationTester $i
     */
    public function everyFailedCheckRendersARemedy(SearchRankingOptimizerWidgetPresentationTester $i): void
    {
        $i->loginAsCustomer(SearchRankingOptimizerWidgetPresentationTester::PERMITTED_CUSTOMER_EMAIL);
        $i->amOnPage(CheckInstallationPage::URL);

        if (!$i->tryToSeeElement(CheckInstallationPage::SELECTOR_CHECK_FAILED)) {
            $i->comment('Every check passes in this environment; nothing to assert about failed rows.');

            return;
        }

        $i->seeElement(CheckInstallationPage::SELECTOR_CHECK_FAILED . ' .check-installation-check__remedy');
    }
}
