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

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Communication
 * @group Form
 * @group AutomatedWeightOptimizationRunFormTest
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
}
