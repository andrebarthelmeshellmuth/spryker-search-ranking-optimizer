<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\CmaEsAlgorithm;
use SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\DifferentialEvolutionAlgorithm;
use SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\OptimizerAlgorithmInterface;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunnerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;
use Throwable;

class OptimizationRunner implements OptimizationRunnerInterface
{
    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface
     */
    protected SearchRankingOptimizerRepositoryInterface $repository;

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface
     */
    protected SearchRankingOptimizerEntityManagerInterface $entityManager;

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface
     */
    protected SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade;

    /**
     * @var \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunnerInterface
     */
    protected RankEvaluationRunnerInterface $rankEvaluationRunner;

    /**
     * @var int|null
     */
    protected ?int $maxGenerations;

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunnerInterface $rankEvaluationRunner
     * @param int|null $maxGenerations Null uses SearchRankingOptimizerConfig::getOptimizationMaxGenerations() --
     *   overridable only to keep tests fast (a real run doesn't need hundreds of generations to verify this
     *   class's own orchestration logic), never exposed via SearchRankingOptimizerBusinessFactory.
     */
    public function __construct(
        SearchRankingOptimizerRepositoryInterface $repository,
        SearchRankingOptimizerEntityManagerInterface $entityManager,
        SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
        RankEvaluationRunnerInterface $rankEvaluationRunner,
        ?int $maxGenerations = null,
    ) {
        $this->repository = $repository;
        $this->entityManager = $entityManager;
        $this->searchRankingFacade = $searchRankingFacade;
        $this->rankEvaluationRunner = $rankEvaluationRunner;
        $this->maxGenerations = $maxGenerations;
    }

    /**
     * {@inheritDoc}
     *
     * @return \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer|null
     */
    public function runNext(): ?SearchRankingOptimizerRunTransfer
    {
        $queuedRunTransfer = $this->repository->findOldestQueuedOptimizerRun();

        if ($queuedRunTransfer === null) {
            return null;
        }

        $idOptimizerRun = $queuedRunTransfer->getIdSearchRankingOptimizerRunOrFail();

        try {
            $this->process($queuedRunTransfer);
        } catch (Throwable $exception) {
            $this->entityManager->failOptimizerRun($idOptimizerRun, $exception->getMessage());
        }

        return $this->repository->findOptimizerRunById($idOptimizerRun);
    }

    /**
     * @param \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer $queuedRunTransfer
     *
     * @return void
     */
    protected function process(SearchRankingOptimizerRunTransfer $queuedRunTransfer): void
    {
        $idOptimizerRun = $queuedRunTransfer->getIdSearchRankingOptimizerRunOrFail();
        $storeName = $queuedRunTransfer->getStoreNameOrFail();
        $localeName = $queuedRunTransfer->getLocaleNameOrFail();

        $activeMetrics = $this->searchRankingFacade->getActiveMetrics();

        if ($activeMetrics === []) {
            $this->entityManager->failOptimizerRun($idOptimizerRun, 'No active metrics exist -- nothing to optimize.');

            return;
        }

        $liveConfigurationTransfer = $this->buildLiveConfiguration();
        $baselineScore = $this->rankEvaluationRunner->evaluateCandidate($storeName, $localeName, $liveConfigurationTransfer);

        if ($baselineScore === null) {
            $this->entityManager->failOptimizerRun(
                $idOptimizerRun,
                'No rated query with at least one rated product exists for this store/locale yet -- nothing to evaluate.',
            );

            return;
        }

        $mapper = new ParameterVectorMapper($activeMetrics, $liveConfigurationTransfer->getRelevanceWeightOrFail());
        $populationSize = $this->computePopulationSize($mapper->getDimensionCount());
        $maxGenerations = $this->maxGenerations ?? SearchRankingOptimizerConfig::getOptimizationMaxGenerations();
        $algorithmName = $queuedRunTransfer->getAlgorithmOrFail();

        $this->entityManager->startOptimizerRun(
            $idOptimizerRun,
            $this->computeTotalEvaluationCount($algorithmName, $populationSize, $maxGenerations),
            $baselineScore,
        );

        $algorithm = $this->buildAlgorithm($algorithmName, $populationSize, $maxGenerations);
        $objectiveFunction = $this->buildObjectiveFunction($mapper, $liveConfigurationTransfer->getRelevanceSaturationPointOrFail(), $storeName, $localeName, $idOptimizerRun);
        $result = $algorithm->optimize($objectiveFunction, $mapper->getLowerBounds(), $mapper->getUpperBounds());

        $bestConfigurationTransfer = $mapper->mapVectorToConfiguration($result->getBestVector(), $liveConfigurationTransfer->getRelevanceSaturationPointOrFail());

        $this->entityManager->completeOptimizerRun(
            $idOptimizerRun,
            $bestConfigurationTransfer->getRelevanceWeightOrFail(),
            $this->buildBestMetricWeightTransfers($activeMetrics, $bestConfigurationTransfer),
            -$result->getBestValue(),
        );
    }

    /**
     * Reads the LIVE configuration straight from search-ranking's own Zed-side facade (never the synced
     * key-value storage copy the storefront reads at request time) — this package's optimizer works
     * against what a Query Curator actually configured in Zed, not a possibly-stale-or-never-published
     * snapshot. Includes EVERY metric's weight (not just active ones), same as the real live formula does.
     *
     * @return \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer
     */
    protected function buildLiveConfiguration(): SearchRankingConfigurationStorageTransfer
    {
        $metricWeightsByName = [];

        foreach ($this->searchRankingFacade->getMetricWeights() as $metricWeight) {
            $metricWeightsByName[$metricWeight['name']] = $metricWeight['weight'];
        }

        return (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight($this->searchRankingFacade->getRelevanceWeight())
            ->setRelevanceSaturationPoint($this->searchRankingFacade->getRelevanceSaturationPoint())
            ->setMetricWeights($metricWeightsByName);
    }

    /**
     * Hansen's own classic CMA-ES default population size formula, computed here (not left to
     * CmaEsAlgorithm's own internal default) so the SAME population size is used for whichever algorithm
     * this run picked, and so the total evaluation count is knowable before the run actually starts.
     *
     * @param int $dimensionCount
     *
     * @return int
     */
    protected function computePopulationSize(int $dimensionCount): int
    {
        return max(4, (int)(4 + floor(3 * log(max($dimensionCount, 2)))));
    }

    /**
     * @param string $algorithmName
     * @param int $populationSize
     * @param int $maxGenerations
     *
     * @return int
     */
    protected function computeTotalEvaluationCount(string $algorithmName, int $populationSize, int $maxGenerations): int
    {
        // DifferentialEvolutionAlgorithm evaluates one extra initial-population batch before its
        // generation loop starts; CmaEsAlgorithm's first generation IS the initial sample.
        $generationBatches = $algorithmName === SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION
            ? $maxGenerations + 1
            : $maxGenerations;

        return $populationSize * $generationBatches;
    }

    /**
     * @param string $algorithmName
     * @param int $populationSize
     * @param int $maxGenerations
     *
     * @return \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\OptimizerAlgorithmInterface
     */
    protected function buildAlgorithm(string $algorithmName, int $populationSize, int $maxGenerations): OptimizerAlgorithmInterface
    {
        if ($algorithmName === SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_DIFFERENTIAL_EVOLUTION) {
            $algorithm = new DifferentialEvolutionAlgorithm();
            $algorithm->setDifferentialEvolutionParameters(populationSize: $populationSize, maxGenerations: $maxGenerations);

            return $algorithm;
        }

        $cmaEsAlgorithm = new CmaEsAlgorithm();
        $cmaEsAlgorithm->setCmaEsParameters(populationSize: $populationSize, maxGenerations: $maxGenerations);

        return $cmaEsAlgorithm;
    }

    /**
     * Builds the closure {@see \SprykerCommunity\Shared\SearchRankingOptimizer\Optimization\Algorithm\OptimizerAlgorithmInterface::optimize()}
     * treats as an opaque objective function: converts an optimizer vector into a real candidate
     * configuration, scores it via the non-persisting evaluation path, updates this run's live progress
     * counter, and negates the score (every algorithm here MINIMIZES, this package wants to MAXIMIZE nDCG).
     * A candidate that can't be evaluated at all (should not happen mid-run, given the baseline check
     * above already confirmed rated queries exist) is scored as 0 rather than thrown, so one bad candidate
     * doesn't abort an otherwise-successful run.
     *
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\ParameterVectorMapperInterface $mapper
     * @param float $relevanceSaturationPoint
     * @param string $storeName
     * @param string $localeName
     * @param int $idOptimizerRun
     *
     * @return callable
     */
    protected function buildObjectiveFunction(
        ParameterVectorMapperInterface $mapper,
        float $relevanceSaturationPoint,
        string $storeName,
        string $localeName,
        int $idOptimizerRun,
    ): callable {
        $evaluationsSoFar = 0;

        return function (array $vector) use ($mapper, $relevanceSaturationPoint, $storeName, $localeName, $idOptimizerRun, &$evaluationsSoFar): float {
            $candidateConfigurationTransfer = $mapper->mapVectorToConfiguration($vector, $relevanceSaturationPoint);
            $score = $this->rankEvaluationRunner->evaluateCandidate($storeName, $localeName, $candidateConfigurationTransfer);

            $evaluationsSoFar++;
            $this->entityManager->updateOptimizerRunProgress($idOptimizerRun, $evaluationsSoFar);

            return -($score ?? 0.0);
        };
    }

    /**
     * @param array<int, array{idSearchRankingMetric: int, name: string}> $activeMetrics
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer $bestConfigurationTransfer
     *
     * @return array<\Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer>
     */
    protected function buildBestMetricWeightTransfers(array $activeMetrics, SearchRankingConfigurationStorageTransfer $bestConfigurationTransfer): array
    {
        $bestMetricWeightsByName = $bestConfigurationTransfer->getMetricWeights();
        $bestMetricWeightTransfers = [];

        foreach ($activeMetrics as $metric) {
            $bestMetricWeightTransfers[] = (new SearchRankingWeightCheckpointMetricWeightTransfer())
                ->setIdSearchRankingMetric($metric['idSearchRankingMetric'])
                ->setName($metric['name'])
                ->setWeight((float)($bestMetricWeightsByName[$metric['name']] ?? 0.0));
        }

        return $bestMetricWeightTransfers;
    }
}
