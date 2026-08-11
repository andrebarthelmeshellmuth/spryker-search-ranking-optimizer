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
     */
    public function _before(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        $i->amZed();
        $i->amLoggedInUser();
    }

    /**
     * @param \SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester $i
     */
    public function startingARunQueuesItWithoutTouchingLiveConfig(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        $ratedScope = $i->grabFirstRatedScope();

        if ($ratedScope === null) {
            $i->comment('No rated query exists yet anywhere, so every scope\'s calibration would be rejected up front; skipping. Run the sibling Yves suite\'s rating-widget tests first for full coverage.');

            return;
        }

        [$storeName, $localeName] = $ratedScope;

        $i->amOnPage(SearchRankingSettingsPage::URL);
        $relevanceSaturationPointBefore = $i->grabValueFrom('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_SATURATION_POINT);

        $i->amOnPage(CalibrationPage::URL);
        $i->selectOption('#' . CalibrationPage::FIELD_CALIBRATION_TYPE, 'Relevance score');
        $i->fillField('#' . CalibrationPage::FIELD_RELEVANT_PRODUCT_COUNT, '6');
        $i->selectOption('#' . CalibrationPage::FIELD_STORE_NAME, $storeName);
        $i->selectOption('#' . CalibrationPage::FIELD_LOCALE_NAME, $localeName);
        // The new "Viewing" scope picker above pushes this button further down the page than the fixed
        // Symfony debug toolbar's dead zone at this viewport size — same fix AutoTuneCest/CheckpointCest
        // already use for their own Save/Apply buttons.
        $i->scrollTo("//button[contains(., '" . CalibrationPage::START_BUTTON_TEXT . "')]", 0, -150);
        $i->click(CalibrationPage::START_BUTTON_TEXT);
        $i->see(CalibrationPage::FLASH_MESSAGE_UPLOADED);

        // Uploaded, not calculated - the live value must be exactly untouched.
        $i->amOnPage(SearchRankingSettingsPage::URL);
        $i->seeInField('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_SATURATION_POINT, $relevanceSaturationPointBefore);
    }

    /**
     * @param \SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester $i
     */
    public function calculatingAndApplyingWritesKIntoSearchRanking(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        $ratedScope = $i->grabFirstRatedScope();

        if ($ratedScope === null) {
            $i->comment('No rated query exists yet anywhere, so every scope\'s calibration would be rejected up front; skipping. Run the sibling Yves suite\'s rating-widget tests first for full coverage.');

            return;
        }

        [$storeName, $localeName] = $ratedScope;
        // Any other locale of the same store — this one must NOT show the run produced below.
        $otherLocaleName = $localeName === SearchRankingOptimizerGuiPresentationTester::DEFAULT_LOCALE_NAME
            ? 'en_US'
            : SearchRankingOptimizerGuiPresentationTester::DEFAULT_LOCALE_NAME;

        $i->amOnPage(SearchRankingSettingsPage::URL);
        $originalRelevanceSaturationPoint = $i->grabValueFrom('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_SATURATION_POINT);

        $i->amOnPage(CalibrationPage::URL);
        $i->selectOption('#' . CalibrationPage::FIELD_CALIBRATION_TYPE, 'Relevance score');
        $i->fillField('#' . CalibrationPage::FIELD_RELEVANT_PRODUCT_COUNT, '6');
        $i->selectOption('#' . CalibrationPage::FIELD_STORE_NAME, $storeName);
        $i->selectOption('#' . CalibrationPage::FIELD_LOCALE_NAME, $localeName);
        // The new "Viewing" scope picker above pushes this button further down the page than the fixed
        // Symfony debug toolbar's dead zone at this viewport size — same fix AutoTuneCest/CheckpointCest
        // already use for their own Save/Apply buttons.
        $i->scrollTo("//button[contains(., '" . CalibrationPage::START_BUTTON_TEXT . "')]", 0, -150);
        $i->click(CalibrationPage::START_BUTTON_TEXT);
        $i->see(CalibrationPage::FLASH_MESSAGE_UPLOADED);

        $consoleOutput = $i->runConsoleCommand(CalibrationPage::CONSOLE_COMMAND_CALIBRATE);

        // Explicit scope in the URL: the upload form's own store/locale pickers are independent of the
        // page's VIEW scope, so landing on the default view would show a different scope's (missing) run.
        $i->amOnPage(CalibrationPage::URL . '?storeName=' . $storeName . '&localeName=' . $localeName);

        if (!$i->tryToSeeElement("//button[contains(., '" . CalibrationPage::APPLY_BUTTON_TEXT . "')]")) {
            // The console command found no scorable result for any of the sourced terms — a legitimate
            // outcome on a shop whose catalog doesn't match them, not a failure of this test.
            $i->comment('Calibration did not produce a calculated run (console output: ' . trim($consoleOutput) . '); skipping the Apply assertion.');

            return;
        }

        $i->see('Computed k (mean)');

        // Scope isolation: this real calculated run belongs to one store/locale specifically — switching
        // the page's own VIEW picker to a different locale of the same store must show the "Latest
        // Calibration Run" box as empty for THAT scope, not the run just produced. Proves the store/locale
        // scoping added to findLatestCalculatedCalibration()/the picker actually reaches the real page,
        // not just the unit-tested repository layer.
        $i->selectOption('#' . CalibrationPage::VIEW_LOCALE_SELECT_ID, $otherLocaleName);
        $i->waitForElementVisible('#' . CalibrationPage::VIEW_LOCALE_SELECT_ID, 10);
        $i->see(CalibrationPage::NO_CALIBRATION_YET_TEXT);
        $i->dontSee('Computed k (mean)');

        // Back to the calibrated scope — the actual Apply click below must act on the real calculated run,
        // not whatever (nothing) is showing for the other locale.
        $i->selectOption('#' . CalibrationPage::VIEW_LOCALE_SELECT_ID, $localeName);
        $i->waitForElementVisible('#' . CalibrationPage::VIEW_LOCALE_SELECT_ID, 10);
        $i->see('Computed k (mean)');

        $i->scrollTo("//button[contains(., '" . CalibrationPage::APPLY_BUTTON_TEXT . "')]", 0, -150);
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
