<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console;

use Spryker\Zed\Kernel\Communication\Console\Console;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface getFacade()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 */
class SearchRankingOptimizerOptimizeConsole extends Console
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-ranking-optimizer:optimize';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Picks up the oldest still-queued automated weight-optimization run (Phase O6), scores its live configuration as a baseline, runs the run\'s own algorithm (CMA-ES or Differential Evolution) against the metric-weight simplex + relevanceWeight trust region, and persists the winning candidate for a human to review and, if they choose, apply -- never applies it automatically.';

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::COMMAND_DESCRIPTION);

        parent::configure();
    }

    // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- signature is fixed by the Console base class.

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter
        $optimizerRunTransfer = $this->getFacade()->runNextOptimization();

        if ($optimizerRunTransfer === null) {
            $output->writeln('No optimization run is queued.');

            return static::CODE_SUCCESS;
        }

        if ($optimizerRunTransfer->getStatus() === SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_FAILED) {
            $output->writeln(sprintf('<error>Run #%d failed: %s</error>', $optimizerRunTransfer->getIdSearchRankingOptimizerRunOrFail(), $optimizerRunTransfer->getErrorMessage()));

            return static::CODE_ERROR;
        }

        $baselineScore = $optimizerRunTransfer->getBaselineScoreOrFail();
        $bestScore = $optimizerRunTransfer->getBestScoreOrFail();

        $output->writeln(sprintf(
            'Run #%d (%s) done: baseline nDCG = %.4f, winning candidate nDCG = %.4f (%s) -- review it on the Optimization Zed page.',
            $optimizerRunTransfer->getIdSearchRankingOptimizerRunOrFail(),
            $optimizerRunTransfer->getAlgorithmOrFail(),
            $baselineScore,
            $bestScore,
            $bestScore > $baselineScore ? 'improved' : 'no improvement',
        ));

        return static::CODE_SUCCESS;
    }
}
