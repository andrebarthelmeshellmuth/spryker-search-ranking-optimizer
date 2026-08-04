<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form;

use Symfony\Component\Form\AbstractType;

/**
 * No fields — "take a checkpoint now" needs nothing beyond a CSRF-protected POST, since the recorder reads
 * every value it snapshots directly off `search-ranking`'s own facade.
 */
class RecordWeightCheckpointForm extends AbstractType
{
    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'search_ranking_optimizer_record_weight_checkpoint';
    }
}
