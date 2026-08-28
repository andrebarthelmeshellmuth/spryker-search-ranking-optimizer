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
use Generated\Shared\Transfer\SearchRankingHybridComparisonTransfer;
use Generated\Shared\Transfer\SearchRankingQueryComparisonTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;

class RankEvaluationRunner implements RankEvaluationRunnerInterface
{
    protected SearchRankingOptimizerRepositoryInterface $repository;

    protected SearchRankingOptimizerEntityManagerInterface $entityManager;

    protected SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient;

    protected RelevanceJudgmentGainMapperInterface $gainMapper;

    protected SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade;

    protected QueryBucketClassifierInterface $queryBucketClassifier;

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RelevanceJudgmentGainMapperInterface $gainMapper
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\QueryBucketClassifierInterface $queryBucketClassifier
     */
    public function __construct(
        SearchRankingOptimizerRepositoryInterface $repository,
        SearchRankingOptimizerEntityManagerInterface $entityManager,
        SearchRankingOptimizerToSearchRankingClientInterface $searchRankingClient,
        RelevanceJudgmentGainMapperInterface $gainMapper,
        SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
        QueryBucketClassifierInterface $queryBucketClassifier,
    ) {
        $this->repository = $repository;
        $this->entityManager = $entityManager;
        $this->searchRankingClient = $searchRankingClient;
        $this->gainMapper = $gainMapper;
        $this->searchRankingFacade = $searchRankingFacade;
        $this->queryBucketClassifier = $queryBucketClassifier;
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
     * {@inheritDoc}
     *
     * @param string $storeName
     * @param string $localeName
     * @param float $alpha
     * @param string $fusionMode
     * @param float $brandShift
     * @param float $categoryShift
     */
    public function compareLexicalVsHybrid(
        string $storeName,
        string $localeName,
        float $alpha,
        string $fusionMode = SearchRankingOptimizerConfig::FUSION_MODE_LINEAR,
        float $brandShift = 0.0,
        float $categoryShift = 0.0,
    ): SearchRankingHybridComparisonTransfer {
        $comparisonTransfer = (new SearchRankingHybridComparisonTransfer())
            ->setStoreName($storeName)
            ->setLocaleName($localeName)
            ->setAlpha($alpha)
            ->setFusionMode($fusionMode)
            ->setLexicalWeightedAggregate(0.0)
            ->setHybridWeightedAggregate(0.0);

        $queryTransfers = $this->repository->findQueriesByStoreLocale($storeName, $localeName);
        $ratingTransfers = $this->repository->findRatingsByStoreLocale($storeName, $localeName);

        if ($queryTransfers === [] || $ratingTransfers === []) {
            return $comparisonTransfer;
        }

        $meanGainsByQueryAndProduct = $this->buildMeanGainsByQueryAndProduct($ratingTransfers);
        $baseRequestTransfer = $this->buildEvaluationRequest($storeName, $localeName, $queryTransfers, $meanGainsByQueryAndProduct);

        if (count($baseRequestTransfer->getQueries()) === 0) {
            return $comparisonTransfer;
        }

        // A CLONE of the live config, alpha forced to 1.0 regardless of what the live config's own alpha
        // currently is -- "lexical" must always be an unambiguous baseline, never accidentally inherit a
        // non-1.0 alpha left over from a prior manual test.
        $liveConfigurationTransfer = $this->searchRankingFacade->getConfiguration($storeName, $localeName);
        $lexicalConfigurationTransfer = (clone $liveConfigurationTransfer)
            ->setAlpha(1.0)
            ->setBrandMatchRelevanceWeightShift(0.0)
            ->setCategoryMatchRelevanceWeightShift(0.0);
        $hybridConfigurationTransfer = (clone $liveConfigurationTransfer)
            ->setAlpha($alpha)
            ->setBrandMatchRelevanceWeightShift($brandShift)
            ->setCategoryMatchRelevanceWeightShift($categoryShift);

        // The lexical baseline is ALWAYS the unchanged linear formula with alpha forced to 1.0 -- RRF
        // (and alpha itself) are only ever relevant to the "hybrid" side of this comparison.
        $lexicalRequestTransfer = (clone $baseRequestTransfer)
            ->setRankingConfiguration($lexicalConfigurationTransfer)
            ->setFusionMode(SearchRankingOptimizerConfig::FUSION_MODE_LINEAR);
        $hybridRequestTransfer = (clone $baseRequestTransfer)
            ->setRankingConfiguration($hybridConfigurationTransfer)
            ->setFusionMode($fusionMode);

        $lexicalResponseTransfer = $this->searchRankingClient->evaluateRankings($lexicalRequestTransfer);
        $hybridResponseTransfer = $this->searchRankingClient->evaluateRankings($hybridRequestTransfer);

        $lexicalWeightedAggregate = $this->computeWeightedAggregate($lexicalRequestTransfer, $lexicalResponseTransfer);
        $hybridWeightedAggregate = $this->computeWeightedAggregate($hybridRequestTransfer, $hybridResponseTransfer);

        $comparisonTransfer
            ->setLexicalWeightedAggregate($lexicalWeightedAggregate[0] ?? 0.0)
            ->setHybridWeightedAggregate($hybridWeightedAggregate[0] ?? 0.0);

        return $this->addQueryComparisons($comparisonTransfer, $baseRequestTransfer, $lexicalResponseTransfer, $hybridResponseTransfer);
    }

    /**
     * Joins the lexical and hybrid per-query nDCG scores by `idSearchRankingQuery` (both responses cover
     * the exact same query set, since both requests were built from the same {@see $baseRequestTransfer}
     * — a query missing from either side is simply excluded rather than treated as an error, since that
     * can only mean rank_eval itself dropped it, e.g. no hits at all for that search term), looks up each
     * query's own `searchTerm` (carried on the request, not the response), tags it with its hardcoded
     * bucket, and computes `delta = hybridScore - lexicalScore`.
     *
     * @param \Generated\Shared\Transfer\SearchRankingHybridComparisonTransfer $comparisonTransfer
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer $baseRequestTransfer
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer $lexicalResponseTransfer
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer $hybridResponseTransfer
     */
    protected function addQueryComparisons(
        SearchRankingHybridComparisonTransfer $comparisonTransfer,
        SearchRankingEvaluationRequestTransfer $baseRequestTransfer,
        SearchRankingEvaluationResponseTransfer $lexicalResponseTransfer,
        SearchRankingEvaluationResponseTransfer $hybridResponseTransfer,
    ): SearchRankingHybridComparisonTransfer {
        $searchTermByQueryId = [];

        foreach ($baseRequestTransfer->getQueries() as $queryTransfer) {
            $searchTermByQueryId[$queryTransfer->getIdSearchRankingQueryOrFail()] = $queryTransfer->getSearchTermOrFail();
        }

        $lexicalScoreByQueryId = $this->indexQueryScoresByQueryId($lexicalResponseTransfer);
        $hybridScoreByQueryId = $this->indexQueryScoresByQueryId($hybridResponseTransfer);

        foreach ($searchTermByQueryId as $queryId => $searchTerm) {
            if (!isset($lexicalScoreByQueryId[$queryId]) || !isset($hybridScoreByQueryId[$queryId])) {
                continue;
            }

            $lexicalScore = $lexicalScoreByQueryId[$queryId];
            $hybridScore = $hybridScoreByQueryId[$queryId];

            $comparisonTransfer->addQueryComparison(
                (new SearchRankingQueryComparisonTransfer())
                    ->setIdSearchRankingQuery($queryId)
                    ->setSearchTerm($searchTerm)
                    ->setBucket($this->queryBucketClassifier->classify($searchTerm))
                    ->setLexicalScore($lexicalScore)
                    ->setHybridScore($hybridScore)
                    ->setDelta($hybridScore - $lexicalScore),
            );
        }

        return $comparisonTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer $responseTransfer
     *
     * @return array<int, float>
     */
    protected function indexQueryScoresByQueryId(SearchRankingEvaluationResponseTransfer $responseTransfer): array
    {
        $scoreByQueryId = [];

        foreach ($responseTransfer->getQueryScores() as $queryScoreTransfer) {
            $scoreByQueryId[$queryScoreTransfer->getIdSearchRankingQueryOrFail()] = $queryScoreTransfer->getMetricScoreOrFail();
        }

        return $scoreByQueryId;
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
