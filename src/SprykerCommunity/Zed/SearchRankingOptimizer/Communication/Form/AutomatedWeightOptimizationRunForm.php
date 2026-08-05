<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form;

use Spryker\Zed\Kernel\Communication\Form\AbstractType;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 */
class AutomatedWeightOptimizationRunForm extends AbstractType
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
    public const FIELD_ALGORITHM = 'algorithm';

    /**
     * @var string
     */
    public const OPTION_STORE_CHOICES = 'store_choices';

    /**
     * @var string
     */
    public const OPTION_LOCALE_CHOICES = 'locale_choices';

    /**
     * @var string
     */
    public const OPTION_ALGORITHMS = 'algorithms';

    /**
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder->add(static::FIELD_STORE_NAME, ChoiceType::class, [
            'label' => 'Store',
            'help' => 'Zed has no "current store" of its own — pick which store\'s rated queries to optimize against.',
            'choices' => $options[static::OPTION_STORE_CHOICES],
            'constraints' => [new NotBlank()],
        ]);

        $builder->add(static::FIELD_LOCALE_NAME, ChoiceType::class, [
            'label' => 'Locale',
            'help' => 'Locale the rated queries were written in.',
            'choices' => $options[static::OPTION_LOCALE_CHOICES],
            'constraints' => [new NotBlank()],
        ]);

        $builder->add(static::FIELD_ALGORITHM, ChoiceType::class, [
            'label' => 'Algorithm',
            'help' => $this->buildAlgorithmHelp($options[static::OPTION_ALGORITHMS]),
            'choices' => $this->buildAlgorithmChoices($options[static::OPTION_ALGORITHMS]),
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
            static::OPTION_ALGORITHMS => [],
        ]);
    }

    /**
     * @param array<string, \BlackboxOptimizer\Algorithm\OptimizerAlgorithmInterface> $algorithms Keyed by
     *   `SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_*`, as returned by
     *   {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface::getOptimizationAlgorithms()}.
     *
     * @return array<string, string>
     */
    private function buildAlgorithmChoices(array $algorithms): array
    {
        $choices = [];

        foreach ($algorithms as $algorithmName => $algorithm) {
            $choices[$algorithm->getName()] = $algorithmName;
        }

        return $choices;
    }

    /**
     * Combines `blackbox-optimizer`'s own factual `getDescription()` per algorithm with this package's
     * own comparative framing (which algorithm to prefer and why) -- framing that stays here rather than
     * traveling into `blackbox-optimizer`, which is deliberately unopinionated about that.
     *
     * @param array<string, \BlackboxOptimizer\Algorithm\OptimizerAlgorithmInterface> $algorithms Keyed by
     *   `SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_*`.
     */
    private function buildAlgorithmHelp(array $algorithms): string
    {
        $recommendations = [
            SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES => 'Generally the stronger choice, at some extra complexity.',
            SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_RECHENBERG_SCHWEFEL_ES => 'CMA-ES\'s own historical predecessor.',
            SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION => 'The simplest option here -- "the thing to beat" rather than expected to win.',
        ];

        $sentences = [];

        foreach ($algorithms as $algorithmName => $algorithm) {
            $sentences[] = sprintf('%s — %s %s', $algorithm->getName(), $algorithm->getDescription(), $recommendations[$algorithmName] ?? '');
        }

        return implode(' ', $sentences);
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'search_ranking_optimizer_automated_weight_optimization_run';
    }
}
