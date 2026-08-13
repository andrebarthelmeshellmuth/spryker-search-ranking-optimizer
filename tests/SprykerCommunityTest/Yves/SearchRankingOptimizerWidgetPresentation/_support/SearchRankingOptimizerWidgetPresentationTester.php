<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation;

use Codeception\Actor;
use Exception;
use SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\PageObject\LoginPage;

/**
 * Inherited Methods
 *
 * @method void wantToTest($text)
 * @method void wantTo($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method \Codeception\Lib\Friend haveFriend($name, $actorClass = null)
 *
 * @SuppressWarnings(\SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\PHPMD)
 */
class SearchRankingOptimizerWidgetPresentationTester extends Actor
{
    use _generated\SearchRankingOptimizerWidgetPresentationTesterActions;

    /**
     * The account this demoshop's fixtures grant RateSearchRelevancePermissionPlugin to — same account
     * used by the sibling search-debug suite (it also holds SeeSearchDebugInfoPermissionPlugin), see
     * data/import/common/common/company_role_permission.csv.
     *
     * @var string
     */
    public const PERMITTED_CUSTOMER_EMAIL = 'search-admin@test-company.example';

    /**
     * Same company (test-company) as the permitted customer, but with no company role assignment at
     * all — confirmed against company_user.csv/company_user_role.csv (customer_reference DE--1 has no
     * role, unlike search-admin's DE--35).
     *
     * @var string
     */
    public const UNPERMITTED_CUSTOMER_EMAIL = 'spencor.hopkin@acme.com';

    /**
     * @var string
     */
    public const CUSTOMER_PASSWORD = 'change123';

    /**
     * @param string $email
     */
    public function loginAsCustomer(string $email): void
    {
        // WebDriver keeps the browser session across Cests in this suite (restart: false), so a
        // prior test's login can still be active here - log out first or the login form never renders.
        $this->amOnPage('/logout');
        $this->amOnPage(LoginPage::URL);
        $this->submitForm(['name' => 'loginForm'], [
            LoginPage::FORM_FIELD_EMAIL => $email,
            LoginPage::FORM_FIELD_PASSWORD => static::CUSTOMER_PASSWORD,
        ]);
    }

    /**
     * Counts the widget's own submit/clear round trips the browser has actually COMPLETED — a resource
     * timing entry only appears once the response has been fully received.
     *
     * @var string
     */
    protected const JS_COMPLETED_ROUND_TRIP_COUNT = 'return window.performance.getEntriesByType("resource")'
        . '.filter(function (entry) { return entry.name.indexOf("relevance-judgment") !== -1; }).length;';

    /**
     * The rating widget persists via `fetch()` and colorizes optimistically, BEFORE that request resolves —
     * so a plain wait-then-navigate races the POST and can abandon it in flight, which looks exactly like a
     * rating that silently failed to save. Waiting on the resource timing entry instead is a real
     * completion signal rather than a guessed sleep duration.
     *
     * @param string $selector
     */
    public function clickAndWaitForRatingRoundTrip(string $selector): void
    {
        $completedBefore = (int)$this->executeJS(static::JS_COMPLETED_ROUND_TRIP_COUNT);

        $this->click($selector);

        $this->waitForJS(
            sprintf('%s > %d;', rtrim(static::JS_COMPLETED_ROUND_TRIP_COUNT, ';'), $completedBefore),
            10,
        );
    }

    /**
     * @param string $selector
     */
    public function tryToSeeElement(string $selector): bool
    {
        try {
            $this->seeElement($selector);

            return true;
        } catch (Exception) {
            return false;
        }
    }
}
