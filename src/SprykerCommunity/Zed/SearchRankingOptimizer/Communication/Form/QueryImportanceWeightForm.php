<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * One field, deliberately: everything else about a query (search term, store, locale) is derived, not
 * editable — a Query Curator's only real decision here is how much this query should matter.
 */
class QueryImportanceWeightForm extends AbstractType
{
    /**
     * @var string
     */
    public const FIELD_IMPORTANCE_WEIGHT = 'importanceWeight';

    /**
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder->add(static::FIELD_IMPORTANCE_WEIGHT, NumberType::class, [
            'label' => 'Importance weight',
            'html5' => true,
            'scale' => 4,
            'constraints' => [
                new NotBlank(),
                new GreaterThan(['value' => 0]),
            ],
        ]);
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'search_ranking_query_importance_weight';
    }
}
