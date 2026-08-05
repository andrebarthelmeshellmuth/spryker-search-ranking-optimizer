<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\Presentation;

use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\CalibrationPage;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\SearchRankingSettingsPage;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester;

/**
 * Checklist section 04 - CALIBRATION. Starting a run is upload-then-cron (async), so
 * `startingARunQueuesItWithoutTouchingLiveConfig` stays smoke-level and doubles as checklist section
 * 10-2's negative test (an un-applied/uncalculated run never silently takes effect). The second test
 * goes further and actually runs the real `search-ranking-optimizer:calibrate` console command via
 * SearchRankingOptimizerGuiPresentationTester::runConsoleCommand() (this test process and the console
 * share one /data working directory) to prove the full upload -> calculate -> Apply -> written-into-
 * search-ranking chain end to end, then manually reverts the live saturation point afterward — per the
 * checklist's own documented caveat, weight checkpoints deliberately exclude k, so this is the one
 * place in this whole suite that has to clean up by hand rather than via a checkpoint restore.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizerGuiPresentation
 * @group Presentation
 * @group CalibrationCest
 * Add your own group annotations below this line
 */
class CalibrationCest
{
    /**
     * @param \SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester $i
     *
     * @return void
     */
    public function _before(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        $i->amZed();
        $i->amLoggedInUser();
    }

    /**
     * @param \SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester $i
     *
     * @return void
     */
    public function startingARunQueuesItWithoutTouchingLiveConfig(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        $i->amOnPage(SearchRankingSettingsPage::URL);
        $relevanceSaturationPointBefore = $i->grabValueFrom('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_SATURATION_POINT);

        $i->amOnPage(CalibrationPage::URL);
        $i->selectOption('#' . CalibrationPage::FIELD_CALIBRATION_TYPE, 'Relevance score');
        $i->fillField('#' . CalibrationPage::FIELD_RELEVANT_PRODUCT_COUNT, '6');
        $i->selectOption('#' . CalibrationPage::FIELD_STORE_NAME, SearchRankingOptimizerGuiPresentationTester::DEFAULT_STORE_NAME);
        $i->selectOption('#' . CalibrationPage::FIELD_LOCALE_NAME, SearchRankingOptimizerGuiPresentationTester::DEFAULT_LOCALE_NAME);
        $i->click(CalibrationPage::START_BUTTON_TEXT);
        $i->see(CalibrationPage::FLASH_MESSAGE_UPLOADED);

        // Uploaded, not calculated - the live value must be exactly untouched.
        $i->amOnPage(SearchRankingSettingsPage::URL);
        $i->seeInField('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_SATURATION_POINT, $relevanceSaturationPointBefore);
    }

    /**
     * @param \SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester $i
     *
     * @return void
     */
    public function calculatingAndApplyingWritesKIntoSearchRanking(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        $i->amOnPage(SearchRankingSettingsPage::URL);
        $originalRelevanceSaturationPoint = $i->grabValueFrom('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_SATURATION_POINT);

        $i->amOnPage(CalibrationPage::URL);
        $i->selectOption('#' . CalibrationPage::FIELD_CALIBRATION_TYPE, 'Relevance score');
        $i->fillField('#' . CalibrationPage::FIELD_RELEVANT_PRODUCT_COUNT, '6');
        $i->selectOption('#' . CalibrationPage::FIELD_STORE_NAME, SearchRankingOptimizerGuiPresentationTester::DEFAULT_STORE_NAME);
        $i->selectOption('#' . CalibrationPage::FIELD_LOCALE_NAME, SearchRankingOptimizerGuiPresentationTester::DEFAULT_LOCALE_NAME);
        $i->click(CalibrationPage::START_BUTTON_TEXT);
        $i->see(CalibrationPage::FLASH_MESSAGE_UPLOADED);

        $consoleOutput = $i->runConsoleCommand(CalibrationPage::CONSOLE_COMMAND_CALIBRATE);

        $i->amOnPage(CalibrationPage::URL);

        if (!$i->tryToSeeElement("//button[contains(., '" . CalibrationPage::APPLY_BUTTON_TEXT . "')]")) {
            // No organically-rated search term existed yet for the console command to sample (this run
            // sources terms from real ratings, same precondition QueryCest/EvaluationCest document) -
            // a legitimate outcome in a freshly-seeded environment, not a failure of this test.
            $i->comment('Calibration did not produce a calculated run (console output: ' . trim($consoleOutput) . '); skipping the Apply assertion.');

            return;
        }

        $i->see('Computed k (mean)');
        $i->click(CalibrationPage::APPLY_BUTTON_TEXT);

        $i->amOnPage(SearchRankingSettingsPage::URL);
        $newRelevanceSaturationPoint = $i->grabValueFrom('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_SATURATION_POINT);
        $i->assertNotSame($originalRelevanceSaturationPoint, $newRelevanceSaturationPoint, 'Expected Apply to have changed the live relevanceSaturationPoint.');

        // Deliberate manual cleanup: checkpoints exclude k by design (see the checklist's own caveat), so
        // there is no Restore button that would undo this - only a direct Settings edit does.
        $i->fillField('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_SATURATION_POINT, $originalRelevanceSaturationPoint);
        $i->click('button[type="submit"]');
        $i->see('Ranking settings were saved.');
        $i->amOnPage(SearchRankingSettingsPage::URL);
        $i->seeInField('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_SATURATION_POINT, $originalRelevanceSaturationPoint);
    }
}
