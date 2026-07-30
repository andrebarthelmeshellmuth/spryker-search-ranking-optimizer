<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Query;

class SearchTermCanonicalizer implements SearchTermCanonicalizerInterface
{
    /**
     * @param string $rawSearchTerm
     *
     * @return string
     */
    public function canonicalize(string $rawSearchTerm): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($rawSearchTerm)) ?? trim($rawSearchTerm);

        return mb_strtolower($collapsed, 'UTF-8');
    }
}
