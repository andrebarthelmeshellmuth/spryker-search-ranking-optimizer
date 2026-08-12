<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration;

class CsvSearchTermParser implements CsvSearchTermParserInterface
{
    /**
     * {@inheritDoc}
     *
     * @param string $csvContent
     *
     * @return array<string>
     */
    public function parse(string $csvContent): array
    {
        $searchTerms = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];

        foreach ($lines as $line) {
            $columns = str_getcsv(trim($line), ',', '"');
            $searchTerm = trim($columns[0] ?? '');

            if ($searchTerm === '') {
                continue;
            }

            $searchTerms[$searchTerm] = $searchTerm;
        }

        return array_values($searchTerms);
    }
}
