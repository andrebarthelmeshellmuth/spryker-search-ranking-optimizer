<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

class RestoreWeightCheckpointForm extends AbstractType
{
    /**
     * @var string
     */
    public const FIELD_ID_SEARCH_RANKING_WEIGHT_CHECKPOINT = 'idSearchRankingWeightCheckpoint';

    /**
     * @var string
     */
    public const FIELD_STORE_NAME = 'storeName';

    /**
     * @var string
     */
    public const FIELD_LOCALE_NAME = 'localeName';

    /**
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array<string, mixed> $options
     *
     * @return void
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder->add(static::FIELD_ID_SEARCH_RANKING_WEIGHT_CHECKPOINT, HiddenType::class, [
            'constraints' => [
                new NotBlank(),
            ],
        ]);

        // Not user-editable — the TARGET scope to restore into, carried through as hidden fields from
        // whichever scope is currently selected on the page (same discipline CalibrationApplyForm already
        // follows for its own hidden store/locale fields).
        $builder->add(static::FIELD_STORE_NAME, HiddenType::class, [
            'constraints' => [new NotBlank()],
        ]);

        $builder->add(static::FIELD_LOCALE_NAME, HiddenType::class, [
            'constraints' => [new NotBlank()],
        ]);
    }

    /**
     * @return string
     */
    public function getBlockPrefix(): string
    {
        return 'search_ranking_optimizer_restore_weight_checkpoint';
    }
}
