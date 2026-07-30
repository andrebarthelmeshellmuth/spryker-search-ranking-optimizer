<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer;

use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer;
use Spryker\Client\Kernel\AbstractClient;

/**
 * @method \SprykerCommunity\Client\SearchRankingOptimizer\SearchRankingOptimizerFactory getFactory()
 */
class SearchRankingOptimizerClient extends AbstractClient implements SearchRankingOptimizerClientInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $limit
     *
     * @return array<float>
     */
    public function getCalibrationScores(string $searchTerm, string $storeName, string $localeName, int $limit): array
    {
        return $this->getFactory()
            ->createCalibrationSearcher()
            ->searchScores($searchTerm, $storeName, $localeName, $limit);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer
     */
    public function submitProductRelevanceJudgment(
        SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer,
    ): SearchRankingProductRelevanceJudgmentResponseTransfer {
        return $this->getFactory()
            ->createProductRelevanceJudgmentStub()
            ->submitProductRelevanceJudgment($requestTransfer);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentResponseTransfer
     */
    public function clearProductRelevanceJudgment(
        SearchRankingProductRelevanceJudgmentRequestTransfer $requestTransfer,
    ): SearchRankingProductRelevanceJudgmentResponseTransfer {
        return $this->getFactory()
            ->createProductRelevanceJudgmentStub()
            ->clearProductRelevanceJudgment($requestTransfer);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer $requestTransfer
     *
     * @return \Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer
     */
    public function evaluateRankings(SearchRankingEvaluationRequestTransfer $requestTransfer): SearchRankingEvaluationResponseTransfer
    {
        return $this->getFactory()
            ->createRankEvalRunner()
            ->evaluate($requestTransfer);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param string $searchTerm
     * @param string $storeName
     * @param string $localeName
     * @param int $idProductAbstract
     *
     * @return bool
     */
    public function productMatchesSearch(string $searchTerm, string $storeName, string $localeName, int $idProductAbstract): bool
    {
        return $this->getFactory()
            ->createProductSearchMatchVerifier()
            ->matches($searchTerm, $storeName, $localeName, $idProductAbstract);
    }
}
