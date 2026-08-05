<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizerGuiPresentation\PageObject;

class CheckpointPage
{
    /**
     * @var string
     */
    public const URL = '/search-ranking-optimizer/checkpoint';

    /**
     * @var string
     */
    public const TAKE_CHECKPOINT_BUTTON_TEXT = 'Take checkpoint now';

    /**
     * @var string
     */
    public const RESTORE_BUTTON_TEXT = 'Restore';

    /**
     * First data row of the History table — checkpoints are newest-first, so this is always the most
     * recently recorded one. Scoped to a row with a Restore button since the page has several plain
     * `.table`s (Current State, metric weights) that aren't the History table.
     *
     * @var string
     */
    public const SELECTOR_FIRST_HISTORY_ROW_RESTORE_BUTTON = "(//table//tbody/tr[.//button[contains(., 'Restore')]])[1]//button[contains(., 'Restore')]";

    /**
     * @var string
     */
    public const SELECTOR_ANY_HISTORY_ROW = "//table//tbody/tr[.//button[contains(., 'Restore')]]";
}
