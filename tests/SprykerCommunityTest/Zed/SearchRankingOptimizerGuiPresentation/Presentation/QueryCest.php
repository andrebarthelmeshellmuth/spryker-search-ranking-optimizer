<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\Presentation;

use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject\QueryPage;
use SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\SearchRankingOptimizerGuiPresentationTester;

/**
 * Checklist section 03 - QUERY CURATION: editing a rated query's importance weight. Needs at least one
 * rated query to exist (created by the sibling Yves suite's rating-widget tests, or by any real usage of
 * this demoshop) — gracefully comments and skips if the list is genuinely empty, rather than failing on
 * an environment-data precondition this Cest doesn't own.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizerGuiPresentation
 * @group Presentation
 * @group QueryCest
 * Add your own group annotations below this line
 */
class QueryCest
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
    public function editingImportanceWeightRoundTripsCorrectly(SearchRankingOptimizerGuiPresentationTester $i): void
    {
        $i->amOnPage(QueryPage::URL_LIST);
        $i->waitForElementVisible(QueryPage::SELECTOR_TABLE, 10);

        if (!$i->tryToSeeElement(QueryPage::SELECTOR_EDIT_BUTTON)) {
            $i->comment('No rated query exists yet in this scope; skipping. Run the sibling Yves suite\'s rating-widget tests first for full coverage.');

            return;
        }

        // Url::generate()->build() returns a relative path here (unlike search-debug's magnifier links,
        // which are absolute) - amOnPage, not amOnUrl, is the one that accepts that.
        $editHref = $i->grabAttributeFrom(QueryPage::SELECTOR_EDIT_BUTTON, 'href');
        $i->amOnPage($editHref);

        // Grabbed/compared as floats throughout: the field renders with a fixed 4-decimal scale
        // (e.g. "2.0000"), so a naive string round-trip against a plain "2" would never match.
        $originalWeight = (float)$i->grabValueFrom('#' . QueryPage::FIELD_IMPORTANCE_WEIGHT);
        $searchTerm = $i->grabTextFrom('//tr[th[text()="Search term"]]/td');

        $testWeight = $originalWeight === 2.0 ? 3.0 : 2.0;
        $i->fillField('#' . QueryPage::FIELD_IMPORTANCE_WEIGHT, (string)$testWeight);
        $i->click('button[type="submit"]');
        $i->see(sprintf(QueryPage::FLASH_MESSAGE_UPDATED_FORMAT, $searchTerm));

        $i->amOnPage($editHref);
        $i->assertSame($testWeight, (float)$i->grabValueFrom('#' . QueryPage::FIELD_IMPORTANCE_WEIGHT));

        // Round-trip: leave the query exactly as this test found it.
        $i->fillField('#' . QueryPage::FIELD_IMPORTANCE_WEIGHT, (string)$originalWeight);
        $i->click('button[type="submit"]');
        $i->amOnPage($editHref);
        $i->assertSame($originalWeight, (float)$i->grabValueFrom('#' . QueryPage::FIELD_IMPORTANCE_WEIGHT));
    }
}
