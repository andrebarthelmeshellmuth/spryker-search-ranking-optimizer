<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject;

class EvaluationPage
{
    /**
     * @var string
     */
    public const URL = '/search-ranking-optimizer/test-current-evaluation';

    /**
     * @var string
     */
    public const FIELD_STORE_NAME = 'search_ranking_optimizer_test_current_evaluation_storeName';

    /**
     * @var string
     */
    public const FIELD_LOCALE_NAME = 'search_ranking_optimizer_test_current_evaluation_localeName';

    /**
     * @var string
     */
    public const EVALUATE_BUTTON_TEXT = 'Evaluate now';

    /**
     * Real evaluation: "Evaluated N rated quer(y|ies): weighted nDCG@10 = X.XXXX."
     *
     * @var string
     */
    public const FLASH_MESSAGE_EVALUATED_SUBSTRING = 'weighted nDCG@';

    /**
     * No rated query exists yet for this scope — a legitimate outcome, not a failure, if this suite runs
     * before any rating has ever been recorded.
     *
     * @var string
     */
    public const FLASH_MESSAGE_NOTHING_TO_EVALUATE_SUBSTRING = 'Nothing to evaluate for';
}
