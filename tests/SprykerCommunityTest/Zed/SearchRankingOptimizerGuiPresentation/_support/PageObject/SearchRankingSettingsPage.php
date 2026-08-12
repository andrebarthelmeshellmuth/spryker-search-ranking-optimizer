<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject;

/**
 * Not this package's own page — spryker-community/search-ranking's Settings page, which several checks
 * in this suite verify AGAINST (checkpoint restore, calibration apply, optimization apply all write
 * here). Field ids/URL confirmed against that package's own SettingsForm/SettingsPage.
 */
class SearchRankingSettingsPage
{
    /**
     * @var string
     */
    public const URL = '/search-ranking-gui/settings';

    /**
     * @var string
     */
    public const FIELD_RELEVANCE_WEIGHT = 'search_ranking_settings_relevanceWeight';

    /**
     * @var string
     */
    public const FIELD_RELEVANCE_SATURATION_POINT = 'search_ranking_settings_relevanceSaturationPoint';
}
