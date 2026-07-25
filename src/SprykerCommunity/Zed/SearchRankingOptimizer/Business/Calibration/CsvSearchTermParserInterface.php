<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Calibration;

interface CsvSearchTermParserInterface
{
    /**
     * Specification:
     * - Splits the given single-column CSV content into one search term per line.
     * - Trims whitespace and surrounding quotes off each line; blank lines are dropped.
     * - Deduplicates search terms (case-sensitive, exact match) — the same term uploaded twice would
     *   otherwise fire the same search query twice for no benefit.
     * - Preserves first-seen order.
     *
     * @param string $csvContent
     *
     * @return array<string>
     */
    public function parse(string $csvContent): array;
}
