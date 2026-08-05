<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject;

class AutoTunePage
{
    /**
     * @var string
     */
    public const URL = '/search-ranking-optimizer/auto-tune';

    /**
     * @var string
     */
    public const SAVE_BUTTON_TEXT = 'Save';

    /**
     * @var string
     */
    public const FLASH_MESSAGE_SAVED = 'Auto-tune settings saved.';

    /**
     * @var string
     */
    public const CONSOLE_COMMAND_AUTO_TUNE = 'search-ranking-optimizer:auto-tune';

    /**
     * The random tie-breaker metric must never appear as its own row (its formula is `random()` -- no R²
     * fit makes sense against noise). See SearchRankingConfig for why "random" is the fixed name.
     *
     * @var string
     */
    public const RANDOM_METRIC_NAME = 'random';

    /**
     * @param string $metricName
     *
     * @return string XPath for the metric's own table row.
     */
    public static function rowXpath(string $metricName): string
    {
        return sprintf("//table//tbody/tr[td[1][normalize-space(text())='%s']]", $metricName);
    }

    /**
     * @param string $metricName
     *
     * @return string XPath for the threshold input inside that metric's own row -- scoped this way
     *   rather than by a bare id, since AutoTuneMetricConfigForm reuses the same block prefix for every
     *   row and the rendered ids are not guaranteed unique across rows.
     */
    public static function thresholdFieldXpath(string $metricName): string
    {
        return static::rowXpath($metricName) . "//input[contains(@name, 'autoTuneThreshold')]";
    }

    /**
     * @param string $metricName
     */
    public static function saveButtonXpath(string $metricName): string
    {
        return static::rowXpath($metricName) . "//button[contains(., 'Save')]";
    }
}
