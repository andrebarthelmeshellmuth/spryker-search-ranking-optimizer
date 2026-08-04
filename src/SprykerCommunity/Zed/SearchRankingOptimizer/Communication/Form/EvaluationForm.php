<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form;

use Spryker\Zed\Kernel\Communication\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 */
class EvaluationForm extends AbstractType
{
    /**
     * @var string
     */
    public const FIELD_STORE_NAME = 'storeName';

    /**
     * @var string
     */
    public const FIELD_LOCALE_NAME = 'localeName';

    /**
     * @var string
     */
    public const OPTION_STORE_CHOICES = 'store_choices';

    /**
     * @var string
     */
    public const OPTION_LOCALE_CHOICES = 'locale_choices';

    /**
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder->add(static::FIELD_STORE_NAME, ChoiceType::class, [
            'label' => 'Store',
            'help' => 'Zed has no "current store" of its own — pick which store\'s rated queries to evaluate.',
            'choices' => $options[static::OPTION_STORE_CHOICES],
            'constraints' => [new NotBlank()],
        ]);

        $builder->add(static::FIELD_LOCALE_NAME, ChoiceType::class, [
            'label' => 'Locale',
            'help' => 'Locale the rated queries were written in.',
            'choices' => $options[static::OPTION_LOCALE_CHOICES],
            'constraints' => [new NotBlank()],
        ]);
    }

    /**
     * @param \Symfony\Component\OptionsResolver\OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            static::OPTION_STORE_CHOICES => [],
            static::OPTION_LOCALE_CHOICES => [],
            // POST, not GET: submitting this form has a real side effect (fires a rank_eval evaluation and
            // persists its result), so it must not be a safe/idempotent request a browser refresh, back-
            // button replay, or prefetcher could silently re-trigger.
            'method' => 'POST',
        ]);
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'search_ranking_optimizer_evaluation';
    }
}
