<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form;

use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;

/**
 * One row per metric on the Auto-Tune Settings page. `autoTuneThreshold` is deliberately NOT required —
 * a blank field is how a metric opts OUT of auto-tune entirely (see the transfer/schema's own
 * docblocks); `isAutoUpdateEnabled`/`isNotifyEnabled` only matter once a threshold is actually set.
 */
class AutoTuneMetricConfigForm extends AbstractType
{
    /**
     * @var string
     */
    public const FIELD_ID_SEARCH_RANKING_METRIC = 'idSearchRankingMetric';

    /**
     * @var string
     */
    public const FIELD_AUTO_TUNE_THRESHOLD = 'autoTuneThreshold';

    /**
     * @var string
     */
    public const FIELD_IS_AUTO_UPDATE_ENABLED = 'isAutoUpdateEnabled';

    /**
     * @var string
     */
    public const FIELD_AUTO_UPDATE_SCOPE = 'autoUpdateScope';

    /**
     * @var string
     */
    public const FIELD_IS_NOTIFY_ENABLED = 'isNotifyEnabled';

    /**
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array<string, mixed> $options
     *
     * @return void
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder->add(static::FIELD_ID_SEARCH_RANKING_METRIC, HiddenType::class);

        $builder->add(static::FIELD_AUTO_TUNE_THRESHOLD, NumberType::class, [
            'label' => 'Auto-tune threshold (R²)',
            'html5' => true,
            'scale' => 4,
            'required' => false,
            'constraints' => [
                new GreaterThan(0),
                new LessThanOrEqual(1),
            ],
        ]);

        $builder->add(static::FIELD_IS_AUTO_UPDATE_ENABLED, ChoiceType::class, [
            'label' => 'Auto-update',
            'choices' => ['Off' => false, 'On' => true],
        ]);

        $builder->add(static::FIELD_AUTO_UPDATE_SCOPE, ChoiceType::class, [
            'label' => 'Auto-update scope',
            'choices' => [
                'Keep current shape (parameters only)' => SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY,
                "Program's choice (may switch shape)" => SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE,
            ],
        ]);

        $builder->add(static::FIELD_IS_NOTIFY_ENABLED, ChoiceType::class, [
            'label' => 'Notify by email',
            'choices' => ['Off' => false, 'On' => true],
        ]);
    }

    /**
     * @return string
     */
    public function getBlockPrefix(): string
    {
        return 'search_ranking_optimizer_auto_tune_metric_config';
    }
}
