<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Yves\SearchRankingOptimizerWidgetPresentation\PageObject;

class SearchResultsPage
{
    /**
     * ~56 hits in this demoshop's catalog — the reliable baseline query, same one search-debug's own
     * suite uses.
     *
     * @var string
     */
    public const URL_CHAIR = '/en/search?q=chair';

    /**
     * Same query, with search-debug's own overlay also active — checklist section 02-3, coexistence.
     *
     * @var string
     */
    public const URL_CHAIR_WITH_SEARCH_DEBUG = '/en/search?q=chair&searchDebugInfo=1';

    /**
     * @var string
     */
    public const SELECTOR_RATING_WIDGET = 'search-ranking-optimizer-product-rating';

    /**
     * @var string
     */
    public const SELECTOR_HEART_BUTTON = '.search-ranking-optimizer-product-rating__button--heart';

    /**
     * @var string
     */
    public const SELECTOR_CHECK_BUTTON = '.search-ranking-optimizer-product-rating__button--check';

    /**
     * @var string
     */
    public const SELECTOR_X_BUTTON = '.search-ranking-optimizer-product-rating__button--x';

    /**
     * @var string
     */
    public const SELECTOR_SCORE_TRIGGER = '.search-debug-trigger';
}
