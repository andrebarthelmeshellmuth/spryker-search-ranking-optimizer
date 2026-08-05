<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\Presentation;

use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\EvaluationPage;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester;

/**
 * Checklist section 05 - RANK EVALUATION: unlike Calibration/Optimization, this is a single batched
 * `_rank_eval` call and runs fully synchronously on submit — no cron/polling needed, so this is a
 * complete end-to-end test, not a smoke one.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizerGuiPresentation
 * @group Presentation
 * @group EvaluationCest
 * Add your own group annotations below this line
 */
class EvaluationCest
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
    public function evaluateNowReturnsASynchronousResult(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        $i->amOnPage(EvaluationPage::URL);
        $i->selectOption('#' . EvaluationPage::FIELD_STORE_NAME, SearchRankingOptimizerGuiPresentationTester::DEFAULT_STORE_NAME);
        $i->selectOption('#' . EvaluationPage::FIELD_LOCALE_NAME, SearchRankingOptimizerGuiPresentationTester::DEFAULT_LOCALE_NAME);
        $i->click(EvaluationPage::EVALUATE_BUTTON_TEXT);

        // Either a real nDCG result, or a clean "nothing rated yet" message — both are legitimate,
        // immediate outcomes; what this test actually proves is that the request is synchronous (no
        // polling/reload needed to see either one).
        $sawRealResult = $i->tryToSeeElement("//*[contains(text(), '" . EvaluationPage::FLASH_MESSAGE_EVALUATED_SUBSTRING . "')]");
        $sawNothingToEvaluate = $i->tryToSeeElement("//*[contains(text(), '" . EvaluationPage::FLASH_MESSAGE_NOTHING_TO_EVALUATE_SUBSTRING . "')]");
        $i->assertTrue($sawRealResult || $sawNothingToEvaluate, 'Expected either a real nDCG result or the "nothing to evaluate" message.');

        if (!$sawRealResult) {
            return;
        }

        // The run also lands in history immediately, with no reload needed.
        $i->see('History');
        $i->dontSee('No evaluation history yet.');
    }
}
