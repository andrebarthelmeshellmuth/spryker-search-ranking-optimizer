<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject;

class QueryPage
{
    /**
     * @var string
     */
    public const URL_LIST = '/search-ranking-optimizer/assess-rated-query';

    /**
     * @var string
     */
    public const URL_EDIT = '/search-ranking-optimizer/assess-rated-query/edit';

    /**
     * @var string
     */
    public const URL_PARAM_ID = 'id-search-ranking-query';

    /**
     * @var string
     */
    public const SELECTOR_TABLE = '.dataTable';

    /**
     * @var string
     */
    public const SELECTOR_EDIT_BUTTON = '.btn-edit';

    /**
     * @var string
     */
    public const FIELD_IMPORTANCE_WEIGHT = 'search_ranking_query_importance_weight_importanceWeight';

    /**
     * @var string
     */
    public const FLASH_MESSAGE_UPDATED_FORMAT = 'Importance weight for "%s" was updated.';
}
