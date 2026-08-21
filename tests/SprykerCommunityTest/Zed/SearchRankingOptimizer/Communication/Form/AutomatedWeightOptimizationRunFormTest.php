<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Communication\Form;

use BlackboxOptimizer\Algorithm\CmaEsAlgorithm;
use BlackboxOptimizer\Algorithm\DifferentialEvolutionAlgorithm;
use BlackboxOptimizer\Algorithm\RechenbergSchwefelEsAlgorithm;
use Codeception\Test\Unit;
use ReflectionMethod;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\AutomatedWeightOptimizationRunForm;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Communication
 * @group Form
 * @group AutomatedWeightOptimizationRunFormTest
 * @group Portable
 */
class AutomatedWeightOptimizationRunFormTest extends Unit
{
    /**
     * `buildAlgorithmChoices()`/`buildAlgorithmHelp()` are both `private` -- invoked directly via
     * reflection (same approach {@see \SprykerCommunityTest\Client\SearchRankingOptimizer\Search\RankEvalRunnerTest}
     * already uses for this package's own protected methods) rather than driving them through a real
     * Symfony `FormBuilder`, which would need a full `FormFactory` for two pure string/array
     * transformations that have nothing to do with Symfony's own form-building machinery.
     */
    public function testBuildAlgorithmChoicesInvertsEachAlgorithmsNameToItsOwnConfigKey(): void
    {
        $form = new AutomatedWeightOptimizationRunForm();
        $buildAlgorithmChoices = new ReflectionMethod($form, 'buildAlgorithmChoices');

        $choices = $buildAlgorithmChoices->invoke($form, [
            SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES => new CmaEsAlgorithm(),
            SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION => new DifferentialEvolutionAlgorithm(),
        ]);

        $this->assertSame([
            'CMA-ES' => SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
            'Differential Evolution' => SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION,
        ], $choices);
    }

    public function testBuildAlgorithmHelpCombinesEachAlgorithmsFactualDescriptionWithItsOwnComparativeRecommendation(): void
    {
        $form = new AutomatedWeightOptimizationRunForm();
        $buildAlgorithmHelp = new ReflectionMethod($form, 'buildAlgorithmHelp');

        $help = $buildAlgorithmHelp->invoke($form, [
            SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES => new CmaEsAlgorithm(),
        ]);

        $this->assertStringContainsString('CMA-ES', $help);
        $this->assertStringContainsString((new CmaEsAlgorithm())->getDescription(), $help);
        $this->assertStringContainsString('Generally the stronger choice, at some extra complexity.', $help);
    }

    public function testBuildAlgorithmHelpFallsBackToNoRecommendationClauseForAnAlgorithmKeyWithNoHardcodedRecommendation(): void
    {
        $form = new AutomatedWeightOptimizationRunForm();
        $buildAlgorithmHelp = new ReflectionMethod($form, 'buildAlgorithmHelp');

        $help = $buildAlgorithmHelp->invoke($form, [
            'some_future_algorithm_key_not_yet_given_a_recommendation' => new RechenbergSchwefelEsAlgorithm(),
        ]);

        $this->assertSame(
            'Rechenberg/Schwefel ES — ' . (new RechenbergSchwefelEsAlgorithm())->getDescription() . ' ',
            $help,
            'An algorithm key with no entry in the hardcoded $recommendations map must fall back to an '
            . 'empty string, not throw or leave a stray placeholder in the help text.',
        );
    }

    public function testBuildAlgorithmHelpJoinsMultipleAlgorithmsWithASingleSpace(): void
    {
        $form = new AutomatedWeightOptimizationRunForm();
        $buildAlgorithmHelp = new ReflectionMethod($form, 'buildAlgorithmHelp');

        $help = $buildAlgorithmHelp->invoke($form, [
            SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES => new CmaEsAlgorithm(),
            SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION => new DifferentialEvolutionAlgorithm(),
        ]);

        $this->assertStringContainsString('CMA-ES', $help);
        $this->assertStringContainsString('Differential Evolution', $help);
        $this->assertLessThan(
            mb_strpos($help, 'Differential Evolution'),
            mb_strpos($help, 'CMA-ES'),
            'CMA-ES was given first, so its sentence must come first in the joined help text.',
        );
    }

    /**
     * Drives the real `buildForm()`/`configureOptions()` through an actual Symfony `FormFactory` (same
     * pattern as this package's sibling repos' own Form tests) -- covers the field-wiring/defaults/
     * constraints these two methods are otherwise the only thing exercising, since the reflection-based
     * tests above only reach the two private helpers they delegate to.
     */
    public function testSubmittingWithAllFieldsPresentIsValid(): void
    {
        $form = $this->createFormFactory()->create(AutomatedWeightOptimizationRunForm::class, null, [
            AutomatedWeightOptimizationRunForm::OPTION_STORE_CHOICES => ['DE' => 'DE'],
            AutomatedWeightOptimizationRunForm::OPTION_LOCALE_CHOICES => ['de_DE' => 'de_DE'],
            AutomatedWeightOptimizationRunForm::OPTION_ALGORITHMS => [
                SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES => new CmaEsAlgorithm(),
            ],
        ]);

        $form->submit([
            AutomatedWeightOptimizationRunForm::FIELD_STORE_NAME => 'DE',
            AutomatedWeightOptimizationRunForm::FIELD_LOCALE_NAME => 'de_DE',
            AutomatedWeightOptimizationRunForm::FIELD_ALGORITHM => SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
            AutomatedWeightOptimizationRunForm::FIELD_TERMINATION_MODE => SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_FIXED_BUDGET,
            AutomatedWeightOptimizationRunForm::FIELD_WARM_START_FRACTION_PERCENT => '25',
        ]);

        $this->assertTrue($form->isValid());
        $this->assertSame('DE', $form->getData()[AutomatedWeightOptimizationRunForm::FIELD_STORE_NAME]);
        $this->assertSame('de_DE', $form->getData()[AutomatedWeightOptimizationRunForm::FIELD_LOCALE_NAME]);
        $this->assertSame(
            SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
            $form->getData()[AutomatedWeightOptimizationRunForm::FIELD_ALGORITHM],
        );
        $this->assertSame(25, $form->getData()[AutomatedWeightOptimizationRunForm::FIELD_WARM_START_FRACTION_PERCENT]);
    }

    /**
     * `terminationMode`'s `'data' => ...FIXED_BUDGET` option only pre-fills the WIDGET on an unsubmitted
     * render -- `submit()` still clears an omitted field to blank first (Symfony's normal `clearMissing`
     * behavior), which then fails this field's own `NotBlank` constraint. Documents that real behavior
     * rather than assuming the option acts as a submit-time fallback.
     */
    public function testOmittingTerminationModeFromSubmittedDataIsInvalidRatherThanFallingBackToTheDefault(): void
    {
        $form = $this->createFormFactory()->create(AutomatedWeightOptimizationRunForm::class, null, [
            AutomatedWeightOptimizationRunForm::OPTION_STORE_CHOICES => ['DE' => 'DE'],
            AutomatedWeightOptimizationRunForm::OPTION_LOCALE_CHOICES => ['de_DE' => 'de_DE'],
            AutomatedWeightOptimizationRunForm::OPTION_ALGORITHMS => [
                SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES => new CmaEsAlgorithm(),
            ],
        ]);

        $form->submit([
            AutomatedWeightOptimizationRunForm::FIELD_STORE_NAME => 'DE',
            AutomatedWeightOptimizationRunForm::FIELD_LOCALE_NAME => 'de_DE',
            AutomatedWeightOptimizationRunForm::FIELD_ALGORITHM => SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
            AutomatedWeightOptimizationRunForm::FIELD_WARM_START_FRACTION_PERCENT => '25',
        ]);

        $this->assertFalse($form->isValid());
    }

    public function testWarmStartFractionPercentAboveOneHundredIsInvalid(): void
    {
        $form = $this->createFormFactory()->create(AutomatedWeightOptimizationRunForm::class, null, [
            AutomatedWeightOptimizationRunForm::OPTION_STORE_CHOICES => ['DE' => 'DE'],
            AutomatedWeightOptimizationRunForm::OPTION_LOCALE_CHOICES => ['de_DE' => 'de_DE'],
            AutomatedWeightOptimizationRunForm::OPTION_ALGORITHMS => [
                SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES => new CmaEsAlgorithm(),
            ],
        ]);

        $form->submit([
            AutomatedWeightOptimizationRunForm::FIELD_STORE_NAME => 'DE',
            AutomatedWeightOptimizationRunForm::FIELD_LOCALE_NAME => 'de_DE',
            AutomatedWeightOptimizationRunForm::FIELD_ALGORITHM => SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
            AutomatedWeightOptimizationRunForm::FIELD_WARM_START_FRACTION_PERCENT => '101',
        ]);

        $this->assertFalse($form->isValid());
        $this->assertGreaterThan(0, $form->getErrors(true)->count());
    }

    public function testSubmittingAnAlgorithmNotInTheGivenChoicesIsInvalid(): void
    {
        $form = $this->createFormFactory()->create(AutomatedWeightOptimizationRunForm::class, null, [
            AutomatedWeightOptimizationRunForm::OPTION_STORE_CHOICES => ['DE' => 'DE'],
            AutomatedWeightOptimizationRunForm::OPTION_LOCALE_CHOICES => ['de_DE' => 'de_DE'],
            AutomatedWeightOptimizationRunForm::OPTION_ALGORITHMS => [
                SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES => new CmaEsAlgorithm(),
            ],
        ]);

        $form->submit([
            AutomatedWeightOptimizationRunForm::FIELD_STORE_NAME => 'DE',
            AutomatedWeightOptimizationRunForm::FIELD_LOCALE_NAME => 'de_DE',
            AutomatedWeightOptimizationRunForm::FIELD_ALGORITHM => SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION,
            AutomatedWeightOptimizationRunForm::FIELD_WARM_START_FRACTION_PERCENT => '0',
        ]);

        $this->assertFalse($form->isValid());
    }

    protected function createFormFactory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }
}
