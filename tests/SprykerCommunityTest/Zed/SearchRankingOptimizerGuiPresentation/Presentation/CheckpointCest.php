<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\Presentation;

use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\CheckpointPage;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\SearchRankingSettingsPage;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester;

/**
 * Checklist sections 01 (safety net) and 06 (restore). Fully self-contained: captures the real live
 * relevanceWeight, mutates it, restores via a checkpoint taken from THIS test's own starting state, and
 * ends with the environment back exactly where it found it — no dependency on any other test's ordering
 * or on section 01's checkpoint still existing.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizerGuiPresentation
 * @group Presentation
 * @group CheckpointCest
 * Add your own group annotations below this line
 */
class CheckpointCest
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
    public function takingACheckpointRecordsCurrentState(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        $i->amOnPage(CheckpointPage::URL);
        $rowCountBefore = count($i->grabMultiple(CheckpointPage::SELECTOR_ANY_HISTORY_ROW));

        $i->click(CheckpointPage::TAKE_CHECKPOINT_BUTTON_TEXT);
        $i->see('recorded.');

        $rowCountAfter = count($i->grabMultiple(CheckpointPage::SELECTOR_ANY_HISTORY_ROW));
        $i->assertSame($rowCountBefore + 1, $rowCountAfter);
    }

    /**
     * @param \SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester $i
     */
    public function restoringACheckpointRevertsWeightsAndRecordsANewOne(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        // Captured/compared as floats throughout: the field re-renders a submitted "0.60" back as
        // "0.6" (HTML5 number input normalization), so a naive string round-trip would never match.
        $i->amOnPage(SearchRankingSettingsPage::URL);
        $originalRelevanceWeight = (float)$i->grabValueFrom('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_WEIGHT);

        // Take a baseline checkpoint of that exact state.
        $i->amOnPage(CheckpointPage::URL);
        $i->click(CheckpointPage::TAKE_CHECKPOINT_BUTTON_TEXT);
        $i->see('recorded.');

        // Mutate it: a different value that's still a valid [0;1] relevanceWeight.
        $testValue = $originalRelevanceWeight === 0.6 ? 0.65 : 0.6;
        $i->amOnPage(SearchRankingSettingsPage::URL);
        $i->fillField('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_WEIGHT, (string)$testValue);
        // The Symfony debug toolbar sits fixed to the viewport bottom and can intercept a plain click
        // on a button positioned right under it (see AutoTuneCest for the same fix, hit first there).
        $i->scrollTo('button[type="submit"]', 0, -150);
        $i->click('button[type="submit"]');
        $i->see('Ranking settings were saved.');
        $i->amOnPage(SearchRankingSettingsPage::URL);
        $i->assertSame($testValue, (float)$i->grabValueFrom('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_WEIGHT));

        // Restore the baseline just taken above (always the most recent history row at this point).
        $i->amOnPage(CheckpointPage::URL);
        $rowCountBeforeRestore = count($i->grabMultiple(CheckpointPage::SELECTOR_ANY_HISTORY_ROW));
        $i->waitForElementVisible(CheckpointPage::SELECTOR_FIRST_HISTORY_ROW_RESTORE_BUTTON, 10);
        // Clear of the fixed Symfony debug toolbar's dead zone at this viewport size — the history table
        // grows by one row per run of this suite, so the first row's own Restore button drifts down the
        // page over time.
        $i->scrollTo(CheckpointPage::SELECTOR_FIRST_HISTORY_ROW_RESTORE_BUTTON, 0, -150);
        $i->click(CheckpointPage::SELECTOR_FIRST_HISTORY_ROW_RESTORE_BUTTON);
        // The restore button's onclick fires a native confirm() warning about the store-wide fan-out
        // (see index.twig) — must be accepted before any further command, or WebDriver throws
        // UnexpectedAlertOpenException on the very next one. The form's own POST + redirect only
        // actually fires once the dialog is dismissed, so a plain see() right after acceptPopup() can
        // race the navigation — waitForText gives it a moment instead of asserting on a stale DOM.
        $i->acceptPopup();
        $i->waitForText('recorded as new checkpoint', 10);

        // Restoring is itself an apply: a brand-new checkpoint row exists recording the restored state,
        // not just a special undo with no audit trail of its own.
        $rowCountAfterRestore = count($i->grabMultiple(CheckpointPage::SELECTOR_ANY_HISTORY_ROW));
        $i->assertSame($rowCountBeforeRestore + 1, $rowCountAfterRestore);

        // The live value is genuinely back to what it was before this test touched anything.
        $i->amOnPage(SearchRankingSettingsPage::URL);
        $i->assertSame($originalRelevanceWeight, (float)$i->grabValueFrom('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_WEIGHT));
    }
}
