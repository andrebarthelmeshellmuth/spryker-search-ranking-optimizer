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
     *
     * @return void
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
        $i->click('button[type="submit"]');
        $i->see('Ranking settings were saved.');
        $i->amOnPage(SearchRankingSettingsPage::URL);
        $i->assertSame($testValue, (float)$i->grabValueFrom('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_WEIGHT));

        // Restore the baseline just taken above (always the most recent history row at this point).
        $i->amOnPage(CheckpointPage::URL);
        $rowCountBeforeRestore = count($i->grabMultiple(CheckpointPage::SELECTOR_ANY_HISTORY_ROW));
        $i->waitForElementVisible(CheckpointPage::SELECTOR_FIRST_HISTORY_ROW_RESTORE_BUTTON, 10);
        $i->click(CheckpointPage::SELECTOR_FIRST_HISTORY_ROW_RESTORE_BUTTON);
        $i->see('recorded as new checkpoint');

        // Restoring is itself an apply: a brand-new checkpoint row exists recording the restored state,
        // not just a special undo with no audit trail of its own.
        $rowCountAfterRestore = count($i->grabMultiple(CheckpointPage::SELECTOR_ANY_HISTORY_ROW));
        $i->assertSame($rowCountBeforeRestore + 1, $rowCountAfterRestore);

        // The live value is genuinely back to what it was before this test touched anything.
        $i->amOnPage(SearchRankingSettingsPage::URL);
        $i->assertSame($originalRelevanceWeight, (float)$i->grabValueFrom('#' . SearchRankingSettingsPage::FIELD_RELEVANCE_WEIGHT));
    }
}
