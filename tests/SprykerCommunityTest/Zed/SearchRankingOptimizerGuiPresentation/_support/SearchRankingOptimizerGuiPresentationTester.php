<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation;

use Codeception\Actor;
use Exception;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\QueryPage;

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
 * @SuppressWarnings(\SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PHPMD)
 */
class SearchRankingOptimizerGuiPresentationTester extends Actor
{
    use _generated\SearchRankingOptimizerGuiPresentationTesterActions;

    /**
     * @var string
     */
    public const DEFAULT_STORE_NAME = 'DE';

    /**
     * @var string
     */
    public const DEFAULT_LOCALE_NAME = 'de_DE';

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

    /**
     * The store/locale of the first row of the Rated Queries table, or null when no query has been rated
     * anywhere yet.
     *
     * Calibration sources its search terms from organically rated queries and is rejected outright when a
     * scope has none (NoSearchTermsAvailableException), so a calibration test can't just assume
     * DEFAULT_STORE_NAME/DEFAULT_LOCALE_NAME the way the weight-oriented Cests do — it has to run against
     * whichever scope the shop under test actually has ratings in.
     *
     * @return array{0: string, 1: string}|null
     */
    public function grabFirstRatedScope(): ?array
    {
        $this->amOnPage(QueryPage::URL_LIST);
        $this->waitForElementVisible(QueryPage::SELECTOR_TABLE, 10);

        if (!$this->tryToSeeElement(QueryPage::SELECTOR_EDIT_BUTTON)) {
            return null;
        }

        return [
            trim($this->grabTextFrom(QueryPage::SELECTOR_FIRST_ROW_STORE_CELL)),
            trim($this->grabTextFrom(QueryPage::SELECTOR_FIRST_ROW_LOCALE_CELL)),
        ];
    }

    /**
     * Runs a real console command inside this same container (the test process and `vendor/bin/console`
     * share one `/data` working directory) — used to actually process a queued calibration/optimization
     * run synchronously within a test, rather than either waiting for the real 5-minute cron or leaving
     * the apply-writes-through-to-search-ranking behavior untested. Mirrors how this package's own cron
     * jobs are documented to work; not a WebDriver action, so it's a plain Tester method, not a module.
     *
     * @param string $command e.g. "search-ranking-optimizer:calibrate"
     *
     * @return string Combined stdout+stderr, for assertions/debugging.
     */
    public function runConsoleCommand(string $command): string
    {
        $output = shell_exec(sprintf('cd /data && vendor/bin/console %s 2>&1', escapeshellarg($command)));

        return (string)$output;
    }
}
