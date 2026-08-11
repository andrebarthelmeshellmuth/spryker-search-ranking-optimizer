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
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
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
    public const FIELD_IS_TERMINATION_CRITERIA_TRUSTED = 'isTerminationCriteriaTrusted';

    /**
     * @var string
     */
    public const FIELD_WARM_START_FRACTION_PERCENT = 'warmStartFractionPercent';

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

        $builder->add(static::FIELD_IS_TERMINATION_CRITERIA_TRUSTED, ChoiceType::class, [
            'label' => 'Trust termination criteria',
            'help' => 'The algorithm\'s own convergence/divergence/plateau detection runs every generation '
                . 'either way — this only changes the generation-count ceiling it\'s allowed to stop early '
                . 'against. Off (default): capped at the normal ~150-generation budget, so most runs never '
                . 'get close enough to reach that detection at all. On: the ceiling jumps to a 10,000-'
                . 'generation safety limit instead, for a search that needs real room to keep converging '
                . 'past the normal budget — slower per run, not faster, since it can now run far longer '
                . 'before that detection (or the higher ceiling itself) finally stops it.',
            'choices' => ['Off' => false, 'On' => true],
            'data' => false,
        ]);

        $builder->add(static::FIELD_WARM_START_FRACTION_PERCENT, IntegerType::class, [
            'label' => 'Warm start (%)',
            'help' => '0 (default): start from scratch, the same from-random-or-midpoint exploration run '
                . 'as always — the right choice for periodically checking whether a genuinely different '
                . 'configuration beats the current one. Above 0: bias the search toward the CURRENT live '
                . 'configuration instead, converging faster but with a higher chance of settling into a '
                . 'local optimum near it rather than finding a better region elsewhere — the right choice '
                . 'for quickly verifying whether a specific tweak helps.',
            'attr' => [
                'min' => 0,
                'max' => 100,
            ],
            'constraints' => [
                new GreaterThanOrEqual(0),
                new LessThanOrEqual(100),
            ],
            'data' => 0,
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
