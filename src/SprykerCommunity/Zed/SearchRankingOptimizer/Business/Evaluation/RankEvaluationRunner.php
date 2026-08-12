<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation;

use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationProductGainTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationQueryTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

class RankEvaluationRunner implements RankEvaluationRunnerInterface
{
    protected SearchRankingOptimizerRepositoryInterface $repository;

    protected SearchRankingOptimizerEntityManagerInterface $entityManager;

    protected SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient;

    protected RelevanceJudgmentGainMapperInterface $gainMapper;

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RelevanceJudgmentGainMapperInterface $gainMapper
     */
    public function __construct(
        SearchRankingOptimizerRepositoryInterface $repository,
        SearchRankingOptimizerEntityManagerInterface $entityManager,
        SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient,
        RelevanceJudgmentGainMapperInterface $gainMapper,
    ) {
        $this->repository = $repository;
        $this->entityManager = $entityManager;
        $this->searchRankingClient = $searchRankingClient;
        $this->gainMapper = $gainMapper;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $storeName
     * @param string $localeName
     */
    public function evaluate(string $storeName, string $localeName): ?SearchRankingEvaluationTransfer
    {
        $weightedAggregate = $this->computeWeightedAggregateFor($storeName, $localeName, null);

        if ($weightedAggregate === null) {
            return null;
        }

        [$metricScore, $queryCount] = $weightedAggregate;

        return $this->entityManager->createEvaluation(
            (new SearchRankingEvaluationTransfer())
                ->setStoreName($storeName)
                ->setLocaleName($localeName)
                ->setMetricScore($metricScore)
                ->setQueryCount($queryCount),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param string $storeName
     * @param string $localeName
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer $candidateConfigurationTransfer
     */
    public function evaluateCandidate(
        string $storeName,
        string $localeName,
        SearchRankingConfigurationStorageTransfer $candidateConfigurationTransfer,
    ): ?float {
        $weightedAggregate = $this->computeWeightedAggregateFor($storeName, $localeName, $candidateConfigurationTransfer);

        if ($weightedAggregate === null) {
            return null;
        }

        [$metricScore] = $weightedAggregate;

        return $metricScore;
    }

    /**
     * The pipeline shared by {@see evaluate()} and {@see evaluateCandidate()} — differ only in whether a
     * candidate configuration override is passed through to the fired query (null = the live synced
     * configuration) and in what the caller does with the result (persist a full evaluation vs. return a
     * bare float). This method itself never persists anything.
     *
     * @param string $storeName
     * @param string $localeName
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer|null $rankingConfigurationOverride
     *
     * @return array{0: float, 1: int}|null
     */
    protected function computeWeightedAggregateFor(
        string $storeName,
        string $localeName,
        ?SearchRankingConfigurationStorageTransfer $rankingConfigurationOverride,
    ): ?array {
        $queryTransfers = $this->repository->findQueriesByStoreLocale($storeName, $localeName);
        $ratingTransfers = $this->repository->findRatingsByStoreLocale($storeName, $localeName);

        if ($queryTransfers === [] || $ratingTransfers === []) {
            return null;
        }

        $meanGainsByQueryAndProduct = $this->buildMeanGainsByQueryAndProduct($ratingTransfers);
        $requestTransfer = $this->buildEvaluationRequest($storeName, $localeName, $queryTransfers, $meanGainsByQueryAndProduct);
        $requestTransfer->setRankingConfiguration($rankingConfigurationOverride);

        if (count($requestTransfer->getQueries()) === 0) {
            return null;
        }

        $responseTransfer = $this->searchRankingClient->evaluateRankings($requestTransfer);

        return $this->computeWeightedAggregate($requestTransfer, $responseTransfer);
    }

    /**
     * Groups every individual rating by (query, product), mapping each rating_type to its numeric gain and
     * averaging across however many admins rated that exact pair — the same mean-not-overwrite aggregation
     * decided for disagreement across raters, applied here at read time.
     *
     * @param array<\Generated\Shared\Transfer\SearchRankingQueryRatingTransfer> $ratingTransfers
     *
     * @return array<int, array<int, float>>
     */
    protected function buildMeanGainsByQueryAndProduct(array $ratingTransfers): array
    {
        $gainsByQueryAndProduct = [];

        foreach ($ratingTransfers as $ratingTransfer) {
            $queryId = $ratingTransfer->getFkSearchRankingQueryOrFail();
            $productId = $ratingTransfer->getFkProductAbstractOrFail();
            $gain = $this->gainMapper->mapRatingType($ratingTransfer->getRatingTypeOrFail());

            $gainsByQueryAndProduct[$queryId][$productId][] = $gain;
        }

        $meanGainsByQueryAndProduct = [];

        foreach ($gainsByQueryAndProduct as $queryId => $gainsByProduct) {
            foreach ($gainsByProduct as $productId => $gains) {
                $meanGainsByQueryAndProduct[$queryId][$productId] = array_sum($gains) / count($gains);
            }
        }

        return $meanGainsByQueryAndProduct;
    }

    /**
     * @param string $storeName
     * @param string $localeName
     * @param array<\Generated\Shared\Transfer\SearchRankingQueryTransfer> $queryTransfers
     * @param array<int, array<int, float>> $meanGainsByQueryAndProduct
     */
    protected function buildEvaluationRequest(
        string $storeName,
        string $localeName,
        array $queryTransfers,
        array $meanGainsByQueryAndProduct,
    ): SearchRankingEvaluationRequestTransfer {
        $requestTransfer = (new SearchRankingEvaluationRequestTransfer())
            ->setStoreName($storeName)
            ->setLocaleName($localeName)
            ->setCutoff(SearchRankingOptimizerConfig::getRankEvalCutoff());

        foreach ($queryTransfers as $queryTransfer) {
            $queryId = $queryTransfer->getIdSearchRankingQueryOrFail();
            $productGains = $meanGainsByQueryAndProduct[$queryId] ?? [];

            if ($productGains === []) {
                continue;
            }

            $evaluationQueryTransfer = (new SearchRankingEvaluationQueryTransfer())
                ->setIdSearchRankingQuery($queryId)
                ->setSearchTerm($queryTransfer->getSearchTermOrFail())
                ->setImportanceWeight($queryTransfer->getImportanceWeightOrFail());

            foreach ($productGains as $productId => $gain) {
                $evaluationQueryTransfer->addProductGain(
                    (new SearchRankingEvaluationProductGainTransfer())
                        ->setIdProductAbstract($productId)
                        ->setGain($gain),
                );
            }

            $requestTransfer->addQuery($evaluationQueryTransfer);
        }

        return $requestTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer $requestTransfer
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer $responseTransfer
     *
     * @return array{0: float, 1: int}|null
     */
    protected function computeWeightedAggregate(
        SearchRankingEvaluationRequestTransfer $requestTransfer,
        SearchRankingEvaluationResponseTransfer $responseTransfer,
    ): ?array {
        $importanceWeightByQueryId = [];

        foreach ($requestTransfer->getQueries() as $queryTransfer) {
            $importanceWeightByQueryId[$queryTransfer->getIdSearchRankingQueryOrFail()] = $queryTransfer->getImportanceWeightOrFail();
        }

        $weightedScoreSum = 0.0;
        $weightSum = 0.0;
        $queryCount = 0;

        foreach ($responseTransfer->getQueryScores() as $queryScoreTransfer) {
            $queryId = $queryScoreTransfer->getIdSearchRankingQueryOrFail();
            $weight = $importanceWeightByQueryId[$queryId] ?? 1.0;

            $weightedScoreSum += $weight * $queryScoreTransfer->getMetricScoreOrFail();
            $weightSum += $weight;
            $queryCount++;
        }

        if ($queryCount === 0 || $weightSum <= 0.0) {
            return null;
        }

        return [$weightedScoreSum / $weightSum, $queryCount];
    }
}
