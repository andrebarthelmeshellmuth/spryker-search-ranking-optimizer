<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form;

use Spryker\Zed\Kernel\Communication\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 */
class CalibrationApplyForm extends AbstractType
{
    /**
     * @var string
     */
    public const FIELD_RELEVANCE_SATURATION_POINT = 'relevanceSaturationPoint';

    /**
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array<string, mixed> $options
     *
     * @return void
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder->add(static::FIELD_RELEVANCE_SATURATION_POINT, NumberType::class, [
            'label' => 'Saturation point (k) to apply',
            'help' => 'Pre-filled with the last calibration run\'s computed value — edit it before applying if you want to round it or nudge it based on judgement.',
            'constraints' => [
                new NotBlank(),
                new GreaterThan(0),
            ],
        ]);
    }

    /**
     * @return string
     */
    public function getBlockPrefix(): string
    {
        return 'search_ranking_calibration_apply';
    }
}
