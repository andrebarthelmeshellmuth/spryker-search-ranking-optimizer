<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Query;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query\SearchTermCanonicalizer;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Query
 * @group SearchTermCanonicalizerTest
 * Add your own group annotations below this line
 */
class SearchTermCanonicalizerTest extends Unit
{
    public function testTrimsLeadingAndTrailingWhitespace(): void
    {
        $this->assertSame('office chair', (new SearchTermCanonicalizer())->canonicalize('  office chair  '));
    }

    public function testLowercases(): void
    {
        $this->assertSame('office chair', (new SearchTermCanonicalizer())->canonicalize('Office Chair'));
    }

    public function testCollapsesInternalWhitespace(): void
    {
        $this->assertSame('office chair', (new SearchTermCanonicalizer())->canonicalize('office    chair'));
    }

    public function testCollapsesInternalTabsAndNewlines(): void
    {
        $this->assertSame('office chair', (new SearchTermCanonicalizer())->canonicalize("office\t\nchair"));
    }

    public function testLowercasesMultibyteCharactersCorrectly(): void
    {
        $this->assertSame('bürostuhl', (new SearchTermCanonicalizer())->canonicalize('BÜROSTUHL'));
    }

    /**
     * Deliberately NOT tokenized: a genuinely different query stays different, even a near-miss like a
     * plural — merging these would be a false-positive query match, not a feature.
     */
    public function testDoesNotMergeADifferentSearchTermIntoTheSameCanonicalForm(): void
    {
        $canonicalizer = new SearchTermCanonicalizer();

        $this->assertNotSame(
            $canonicalizer->canonicalize('office chair'),
            $canonicalizer->canonicalize('office chairs'),
        );
    }
}
