<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune;

use Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;

class AutoTuneMetricConfigWriter implements AutoTuneMetricConfigWriterInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     */
    public function __construct(
        protected SearchRankingOptimizerEntityManagerInterface $entityManager,
        protected SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer
     *
     * @return array<string, \Generated\Shared\Transfer\SearchRankingAutoTuneMetricConfigTransfer>
     */
    public function save(SearchRankingAutoTuneMetricConfigTransfer $autoTuneMetricConfigTransfer): array
    {
        $effectiveLocaleNames = $this->searchRankingFacade->resolveEffectiveWeightLocales(
            $autoTuneMetricConfigTransfer->getIdSearchRankingMetricOrFail(),
            $autoTuneMetricConfigTransfer->getStoreNameOrFail(),
            $autoTuneMetricConfigTransfer->getLocaleNameOrFail(),
        );

        $savedTransfersByLocale = [];

        foreach ($effectiveLocaleNames as $effectiveLocaleName) {
            $savedTransfersByLocale[$effectiveLocaleName] = $this->entityManager->saveAutoTuneMetricConfig(
                (clone $autoTuneMetricConfigTransfer)
                    ->setIdSearchRankingAutoTuneMetricConfig(null)
                    ->setLocaleName($effectiveLocaleName),
            );
        }

        return $savedTransfersByLocale;
    }
}
