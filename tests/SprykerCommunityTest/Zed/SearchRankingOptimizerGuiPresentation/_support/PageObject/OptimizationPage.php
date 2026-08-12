<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject;

class OptimizationPage
{
    /**
     * @var string
     */
    public const URL = '/search-ranking-optimizer/automated-weight-optimization';

    /**
     * @var string
     */
    public const FIELD_STORE_NAME = 'search_ranking_optimizer_automated_weight_optimization_run_storeName';

    /**
     * @var string
     */
    public const FIELD_LOCALE_NAME = 'search_ranking_optimizer_automated_weight_optimization_run_localeName';

    /**
     * @var string
     */
    public const FIELD_ALGORITHM = 'search_ranking_optimizer_automated_weight_optimization_run_algorithm';

    /**
     * @var string
     */
    public const RUN_NOW_BUTTON_TEXT = 'Run now';

    /**
     * Same button as RUN_NOW_BUTTON_TEXT, addressed by selector rather than text so it can be scrolled
     * clear of the fixed Symfony debug toolbar before being clicked.
     *
     * @var string
     */
    public const SELECTOR_RUN_NOW_BUTTON = '[data-role="run-now-button"]';

    /**
     * @var string
     */
    public const APPLY_BUTTON_TEXT = 'Apply';

    /**
     * @var string
     */
    public const FLASH_MESSAGE_QUEUED = 'Optimization run queued — the next "search-ranking-optimizer:optimize" cron tick will process it.';

    /**
     * @var string
     */
    public const CONSOLE_COMMAND_OPTIMIZE = 'search-ranking-optimizer:optimize';

    /**
     * @var string
     */
    public const SELECTOR_STATUS_DONE_LABEL = '.label-success';
}
