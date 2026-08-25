<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console;

use Generated\Shared\Transfer\SearchRankingHybridComparisonTransfer;
use Generated\Shared\Transfer\SearchRankingQueryComparisonTransfer;
use Spryker\Zed\Kernel\Communication\Console\Console;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface getFacade()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 */
class SearchRankingOptimizerEvaluateHybridConsole extends Console
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-ranking-optimizer:evaluate-hybrid';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'P4\'s own lexical-vs-hybrid comparison: runs the full judged query set for one (store, locale) through two ranking configurations -- a forced alpha=1.0 baseline ("lexical") and a candidate alpha ("hybrid") -- and prints a per-query nDCG@k breakdown by hardcoded query-type bucket, plus both weighted aggregates. --fusion selects the "hybrid" side\'s own fusion mode: "linear" (default, the alpha-blended formula) or "rrf" (Reciprocal Rank Fusion of two independently-retrieved candidate lists -- --alpha has no effect on RRF mode\'s own ranking, only on what alpha the lexical baseline comparison is labeled with).';

    /**
     * @var string
     */
    public const OPTION_STORE = 'store';

    /**
     * @var string
     */
    public const OPTION_LOCALE = 'locale';

    /**
     * @var string
     */
    public const OPTION_ALPHA = 'alpha';

    /**
     * @var string
     */
    public const OPTION_FUSION = 'fusion';

    /**
     * @var string
     */
    protected const OPTION_STORE_DEFAULT = 'DE';

    /**
     * @var string
     */
    protected const OPTION_LOCALE_DEFAULT = 'en_US';

    /**
     * @var float
     */
    protected const OPTION_ALPHA_DEFAULT = 0.5;

    /**
     * @var array<int, string>
     */
    protected const OPTION_FUSION_ALLOWED_VALUES = [
        SearchRankingOptimizerConfig::FUSION_MODE_LINEAR,
        SearchRankingOptimizerConfig::FUSION_MODE_RRF,
    ];

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::COMMAND_DESCRIPTION);

        $this->addOption(static::OPTION_STORE, null, InputOption::VALUE_REQUIRED, 'Store name.', static::OPTION_STORE_DEFAULT);
        $this->addOption(static::OPTION_LOCALE, null, InputOption::VALUE_REQUIRED, 'Locale name.', static::OPTION_LOCALE_DEFAULT);
        $this->addOption(static::OPTION_ALPHA, null, InputOption::VALUE_REQUIRED, 'Candidate hybrid alpha (0.0 = fully semantic, 1.0 = fully lexical). Ignored by --fusion=rrf\'s own ranking.', (string)static::OPTION_ALPHA_DEFAULT);
        $this->addOption(static::OPTION_FUSION, null, InputOption::VALUE_REQUIRED, sprintf('Hybrid fusion mode: one of "%s".', implode('", "', static::OPTION_FUSION_ALLOWED_VALUES)), SearchRankingOptimizerConfig::FUSION_MODE_LINEAR);

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeName = (string)$input->getOption(static::OPTION_STORE);
        $localeName = (string)$input->getOption(static::OPTION_LOCALE);
        $alpha = (float)$input->getOption(static::OPTION_ALPHA);
        $fusionMode = (string)$input->getOption(static::OPTION_FUSION);

        if (!in_array($fusionMode, static::OPTION_FUSION_ALLOWED_VALUES, true)) {
            $output->writeln(sprintf(
                '<error>Invalid --fusion value "%s" -- must be one of "%s".</error>',
                $fusionMode,
                implode('", "', static::OPTION_FUSION_ALLOWED_VALUES),
            ));

            return static::CODE_ERROR;
        }

        $comparisonTransfer = $this->getFacade()->compareLexicalVsHybrid($storeName, $localeName, $alpha, $fusionMode);

        $queryComparisonTransfers = iterator_to_array($comparisonTransfer->getQueryComparisons());

        if ($queryComparisonTransfers === []) {
            $output->writeln(sprintf('No judged queries found for store=%s, locale=%s.', $storeName, $localeName));

            return static::CODE_SUCCESS;
        }

        usort(
            $queryComparisonTransfers,
            fn (SearchRankingQueryComparisonTransfer $a, SearchRankingQueryComparisonTransfer $b): int => [$a->getBucketOrFail(), $a->getDeltaOrFail()] <=> [$b->getBucketOrFail(), $b->getDeltaOrFail()],
        );

        $table = new Table($output);
        $table->setHeaders(['Search term', 'Bucket', 'Lexical nDCG', 'Hybrid nDCG', 'Delta']);

        foreach ($queryComparisonTransfers as $queryComparisonTransfer) {
            $table->addRow([
                $queryComparisonTransfer->getSearchTermOrFail(),
                $queryComparisonTransfer->getBucketOrFail(),
                number_format($queryComparisonTransfer->getLexicalScoreOrFail(), 4),
                number_format($queryComparisonTransfer->getHybridScoreOrFail(), 4),
                number_format($queryComparisonTransfer->getDeltaOrFail(), 4),
            ]);
        }

        $table->render();

        $this->writeSummary($output, $comparisonTransfer, count($queryComparisonTransfers));

        return static::CODE_SUCCESS;
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param \Generated\Shared\Transfer\SearchRankingHybridComparisonTransfer $comparisonTransfer
     * @param int $queryCount
     */
    protected function writeSummary(OutputInterface $output, SearchRankingHybridComparisonTransfer $comparisonTransfer, int $queryCount): void
    {
        $lexicalWeightedAggregate = $comparisonTransfer->getLexicalWeightedAggregateOrFail();
        $hybridWeightedAggregate = $comparisonTransfer->getHybridWeightedAggregateOrFail();

        $output->writeln(sprintf(
            '%d quer%s compared (fusion=%s, alpha=%.2f): lexical weighted aggregate = %.4f, hybrid weighted aggregate = %.4f, delta = %.4f.',
            $queryCount,
            $queryCount === 1 ? 'y' : 'ies',
            $comparisonTransfer->getFusionMode() ?? SearchRankingOptimizerConfig::FUSION_MODE_LINEAR,
            $comparisonTransfer->getAlphaOrFail(),
            $lexicalWeightedAggregate,
            $hybridWeightedAggregate,
            $hybridWeightedAggregate - $lexicalWeightedAggregate,
        ));
    }
}
