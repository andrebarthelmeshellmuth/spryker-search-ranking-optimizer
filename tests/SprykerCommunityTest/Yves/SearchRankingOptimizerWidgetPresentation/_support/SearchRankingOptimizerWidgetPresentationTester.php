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
     *
     * @return void
     */
    public function loginAsCustomer(string $email): void
    {
        $this->amOnPage(LoginPage::URL);
        $this->submitForm(['name' => 'loginForm'], [
            LoginPage::FORM_FIELD_EMAIL => $email,
            LoginPage::FORM_FIELD_PASSWORD => static::CUSTOMER_PASSWORD,
        ]);
    }

    /**
     * @param string $selector
     *
     * @return bool
     */
    public function tryToSeeElement(string $selector): bool
    {
        try {
            $this->seeElement($selector);

            return true;
        } catch (Exception $exception) {
            return false;
        }
    }
}
