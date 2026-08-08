<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console;

use Generated\Shared\Transfer\SearchRankingAutoTuneMetricResultTransfer;
use Spryker\Zed\Kernel\Communication\Console\Console;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface getFacade()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 */
class SearchRankingOptimizerAutoTuneConsole extends Console
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-ranking-optimizer:auto-tune';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Monthly fit-quality check: for every metric with an auto-tune threshold set, checks its live formula\'s current fit and refits (proposing or applying, per that metric\'s own settings) when it has dropped below threshold. Intended to run on a monthly cron.';

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
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter
        $autoTuneResultTransfer = $this->getFacade()->runAutoTune();

        if ($autoTuneResultTransfer->getMetricResults()->count() === 0) {
            $output->writeln('No metric has an auto-tune threshold set (or none had a digest to check yet).');

            return static::CODE_SUCCESS;
        }

        foreach ($autoTuneResultTransfer->getMetricResults() as $metricResultTransfer) {
            // Prefixed with the store, not just the metric name: a multi-store run checks the SAME
            // metric name once per store, so "top_seller: ..." alone would be ambiguous about which
            // store's result a given line describes.
            $label = sprintf('[%s] %s', $metricResultTransfer->getStoreNameOrFail(), $metricResultTransfer->getMetricNameOrFail());

            if ($metricResultTransfer->getErrorMessage() !== null) {
                $output->writeln(sprintf(
                    '%s: FAILED to check — %s',
                    $label,
                    $metricResultTransfer->getErrorMessage(),
                ));

                continue;
            }

            if ($metricResultTransfer->getWasThresholdMetOrFail()) {
                $output->writeln(sprintf(
                    '%s: fit still adequate (R² = %.4f), no change.',
                    $label,
                    $metricResultTransfer->getBeforeFitRSquaredOrFail(),
                ));
                $this->reportLocaleDivergence($output, $label, $metricResultTransfer);

                continue;
            }

            if ($metricResultTransfer->getWasSkippedNonDeterministic()) {
                $output->writeln(sprintf(
                    '%s: fit dropped to R² = %.4f (below threshold) — skipped, no refit: formula is non-deterministic.',
                    $label,
                    $metricResultTransfer->getBeforeFitRSquaredOrFail(),
                ));
                $this->reportLocaleDivergence($output, $label, $metricResultTransfer);

                continue;
            }

            $output->writeln(sprintf(
                '%s: fit dropped to R² = %.4f (below threshold) — %s %s (R² = %.4f).',
                $label,
                $metricResultTransfer->getBeforeFitRSquaredOrFail(),
                $metricResultTransfer->getWasApplied() ? 'applied' : 'proposed',
                $metricResultTransfer->getAfterFormula() ?? '(no candidate found)',
                $metricResultTransfer->getAfterFitRSquared() ?? 0.0,
            ));
            $this->reportLocaleDivergence($output, $label, $metricResultTransfer);
        }

        $output->writeln(sprintf('Notified %d admin(s) by email.', $autoTuneResultTransfer->getNotifiedEmailCountOrFail()));

        return static::CODE_SUCCESS;
    }

    /**
     * Purely informational: this run only ever checks/refits the store's default locale (see
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneRunner}'s own
     * docblock), so nothing here acts differently per locale yet -- this only tells a curator when a
     * store-wide formula quietly fits one locale meaningfully worse than another, which the single
     * before/after R² lines above can't show (they only ever reflect the store's own default locale).
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param string $label
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricResultTransfer $metricResultTransfer
     */
    protected function reportLocaleDivergence(OutputInterface $output, string $label, SearchRankingAutoTuneMetricResultTransfer $metricResultTransfer): void
    {
        $fitByLocale = array_filter($metricResultTransfer->getFitRSquaredByLocale(), static fn (?float $fit): bool => $fit !== null);

        if (count($fitByLocale) < 2) {
            return;
        }

        $spread = max($fitByLocale) - min($fitByLocale);

        if ($spread < SearchRankingOptimizerConfig::getLocaleFitDivergenceWarningThreshold()) {
            return;
        }

        $localeSummary = implode(', ', array_map(
            static fn (string $locale, float $fit): string => sprintf('%s=%.4f', $locale, $fit),
            array_keys($fitByLocale),
            array_values($fitByLocale),
        ));

        $output->writeln(sprintf(
            '%s:   ⚠ fit varies by locale (spread %.4f): %s — this store-wide formula may not fit every locale equally well.',
            $label,
            $spread,
            $localeSummary,
        ));
    }
}
