<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject;

class CalibrationPage
{
    /**
     * @var string
     */
    public const URL = '/search-ranking-optimizer/saturation-point-calibration';

    /**
     * @var string
     */
    public const FIELD_CALIBRATION_TYPE = 'search_ranking_saturation_point_calibration_upload_calibrationType';

    /**
     * @var string
     */
    public const FIELD_RELEVANT_PRODUCT_COUNT = 'search_ranking_saturation_point_calibration_upload_relevantProductCount';

    /**
     * @var string
     */
    public const FIELD_STORE_NAME = 'search_ranking_saturation_point_calibration_upload_storeName';

    /**
     * @var string
     */
    public const FIELD_LOCALE_NAME = 'search_ranking_saturation_point_calibration_upload_localeName';

    /**
     * @var string
     */
    public const START_BUTTON_TEXT = 'Start';

    /**
     * @var string
     */
    public const APPLY_BUTTON_TEXT = 'Apply';

    /**
     * @var string
     */
    public const FLASH_MESSAGE_UPLOADED = 'Calibration run uploaded — the next "search-ranking-optimizer:calibrate" cron tick will calculate it.';

    /**
     * @var string
     */
    public const CONSOLE_COMMAND_CALIBRATE = 'search-ranking-optimizer:calibrate';

    /**
     * The page's own VIEW scope picker — independent of FIELD_STORE_NAME/FIELD_LOCALE_NAME above, which
     * belong to the "Start New Calibration Run" upload form specifically.
     *
     * @var string
     */
    public const VIEW_STORE_SELECT_ID = 'storeName';

    /**
     * @var string
     */
    public const VIEW_LOCALE_SELECT_ID = 'localeName';

    /**
     * @var string
     */
    public const LATEST_CALIBRATION_RUN_HEADING_TEXT = 'Latest Calibration Run';

    /**
     * @var string
     */
    public const NO_CALIBRATION_YET_TEXT = 'No calibration run has finished yet for';
}
