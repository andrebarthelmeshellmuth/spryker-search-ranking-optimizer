<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\CsvSearchTermParser;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group@group SearchRankingOptimizer
 * @group Business
 * @group SaturationPointCalibration
 * @group CsvSearchTermParserTest
 * Add your own group annotations below this line
 */
class CsvSearchTermParserTest extends Unit
{
    public function testParsesOneSearchTermPerLine(): void
    {
        // Arrange
        $parser = new CsvSearchTermParser();

        // Act
        $searchTerms = $parser->parse("chair\ndesk\nlamp");

        // Assert
        $this->assertSame(['chair', 'desk', 'lamp'], $searchTerms);
    }

    public function testDropsBlankLines(): void
    {
        // Arrange
        $parser = new CsvSearchTermParser();

        // Act
        $searchTerms = $parser->parse("chair\n\n\ndesk\n");

        // Assert
        $this->assertSame(['chair', 'desk'], $searchTerms);
    }

    public function testTrimsWhitespaceAroundEachTerm(): void
    {
        // Arrange
        $parser = new CsvSearchTermParser();

        // Act
        $searchTerms = $parser->parse("  chair  \n\tdesk\t");

        // Assert
        $this->assertSame(['chair', 'desk'], $searchTerms);
    }

    /**
     * Uploading the same search term twice must not fire the same query twice — dedup preserves the
     * first-seen order.
     */
    public function testDeduplicatesSearchTermsPreservingFirstSeenOrder(): void
    {
        // Arrange
        $parser = new CsvSearchTermParser();

        // Act
        $searchTerms = $parser->parse("chair\ndesk\nchair\nlamp\ndesk");

        // Assert
        $this->assertSame(['chair', 'desk', 'lamp'], $searchTerms);
    }

    public function testHandlesWindowsStyleLineEndings(): void
    {
        // Arrange
        $parser = new CsvSearchTermParser();

        // Act
        $searchTerms = $parser->parse("chair\r\ndesk\r\nlamp");

        // Assert
        $this->assertSame(['chair', 'desk', 'lamp'], $searchTerms);
    }

    public function testReturnsAnEmptyArrayForBlankContent(): void
    {
        // Arrange
        $parser = new CsvSearchTermParser();

        // Act
        $searchTerms = $parser->parse("\n\n  \n");

        // Assert
        $this->assertSame([], $searchTerms);
    }
}
