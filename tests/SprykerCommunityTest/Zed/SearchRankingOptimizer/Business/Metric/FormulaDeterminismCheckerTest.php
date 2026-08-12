<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Metric;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismChecker;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Metric
 * @group FormulaDeterminismCheckerTest
 * @group Portable
 */
class FormulaDeterminismCheckerTest extends Unit
{
    public function testIsDeterministicReturnsFalseForARandomFormula(): void
    {
        $this->assertFalse((new FormulaDeterminismChecker())->isDeterministic('random()'));
    }

    public function testIsDeterministicReturnsFalseWhenRandomIsPartOfALargerExpression(): void
    {
        $this->assertFalse((new FormulaDeterminismChecker())->isDeterministic('x + random() * 0.1'));
    }

    public function testIsDeterministicReturnsFalseRegardlessOfWhitespaceBeforeTheParenthesis(): void
    {
        $this->assertFalse((new FormulaDeterminismChecker())->isDeterministic('random ()'));
    }

    public function testIsDeterministicReturnsTrueForARealBusinessFormula(): void
    {
        $checker = new FormulaDeterminismChecker();

        $this->assertTrue($checker->isDeterministic('atan(x / avg) / (pi() / 2)'));
        $this->assertTrue($checker->isDeterministic('x / max'));
    }

    /**
     * A different, hypothetical function that merely starts with the same letters as "random" must never
     * false-positive -- there is no word boundary between "random" and "_", so \b alone wouldn't catch
     * this; the formula must literally be "random(" (optionally with whitespace) to match.
     */
    public function testIsDeterministicDoesNotFalsePositiveOnADifferentFunctionSharingAPrefix(): void
    {
        $this->assertTrue((new FormulaDeterminismChecker())->isDeterministic('random_seed(x)'));
    }
}
