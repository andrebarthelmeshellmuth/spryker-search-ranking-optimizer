<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization;

use BlackboxOptimizer\Problem\CallableProblem;
use Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingWeightCheckpointMetricWeightTransfer;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunnerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismCheckerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface;
use Throwable;

class OptimizationRunner implements OptimizationRunnerInterface
{
    protected SearchRankingOptimizerRepositoryInterface $repository;

    protected SearchRankingOptimizerEntityManagerInterface $entityManager;

    protected SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade;

    protected RankEvaluationRunnerInterface $rankEvaluationRunner;

    protected FormulaDeterminismCheckerInterface $formulaDeterminismChecker;

    protected AlgorithmFactoryInterface $algorithmFactory;

    protected ?int $maxGenerations;

    /**
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepositoryInterface $repository
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManagerInterface $entityManager
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunnerInterface $rankEvaluationRunner
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismCheckerInterface $formulaDeterminismChecker
     * @param \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\AlgorithmFactoryInterface $algorithmFactory
     * @param int|null $maxGenerations Null uses SearchRankingOptimizerConfig::getOptimizationMaxGenerations() --
     *   overridable only to keep tests fast (a real run doesn't need hundreds of generations to verify this
     *   class's own orchestration logic), never exposed via SearchRankingOptimizerBusinessFactory.
     */
    public function __construct(
        SearchRankingOptimizerRepositoryInterface $repository,
        SearchRankingOptimizerEntityManagerInterface $entityManager,
        SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade,
        RankEvaluationRunnerInterface $rankEvaluationRunner,
        FormulaDeterminismCheckerInterface $formulaDeterminismChecker,
        AlgorithmFactoryInterface $algorithmFactory,
        ?int $maxGenerations = null,
    ) {
        $this->repository = $repository;
        $this->entityManager = $entityManager;
        $this->searchRankingFacade = $searchRankingFacade;
        $this->rankEvaluationRunner = $rankEvaluationRunner;
        $this->formulaDeterminismChecker = $formulaDeterminismChecker;
        $this->algorithmFactory = $algorithmFactory;
        $this->maxGenerations = $maxGenerations;
    }

    /**
     * {@inheritDoc}
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
     */
    protected function process(SearchRankingOptimizerRunTransfer $queuedRunTransfer): void
    {
        $idOptimizerRun = $queuedRunTransfer->getIdSearchRankingOptimizerRunOrFail();
        $storeName = $queuedRunTransfer->getStoreNameOrFail();
        $localeName = $queuedRunTransfer->getLocaleNameOrFail();

        $activeMetrics = $this->searchRankingFacade->getActiveMetrics($storeName, $localeName);

        if ($activeMetrics === []) {
            $this->entityManager->failOptimizerRun($idOptimizerRun, 'No active metrics exist -- nothing to optimize.');

            return;
        }

        $liveConfigurationTransfer = $this->buildLiveConfiguration($storeName, $localeName);
        $baselineScore = $this->rankEvaluationRunner->evaluateCandidate($storeName, $localeName, $liveConfigurationTransfer);

        if ($baselineScore === null) {
            $this->entityManager->failOptimizerRun(
                $idOptimizerRun,
                'No rated query with at least one rated product exists for this store/locale yet -- nothing to evaluate.',
            );

            return;
        }

        $userFixedMetricWeights = $this->buildUserFixedMetricWeights($queuedRunTransfer, $liveConfigurationTransfer);
        [$optimizableMetrics, $fixedMetricWeights] = $this->splitMetricsByDeterminism(
            $activeMetrics,
            $liveConfigurationTransfer,
            $storeName,
            $localeName,
            $userFixedMetricWeights,
        );
        $mapper = new ParameterVectorMapper(
            $optimizableMetrics,
            $fixedMetricWeights,
            $liveConfigurationTransfer->getRelevanceWeightOrFail(),
            $liveConfigurationTransfer->getSpecificityCurveExponentOrFail(),
            $liveConfigurationTransfer->getSpecificityWeightExponentOrFail(),
            $liveConfigurationTransfer->getSpecificityWeightShiftMagnitudeOrFail(),
            $liveConfigurationTransfer->getSpecificityBlendWeightOrFail(),
            $this->searchRankingFacade->isSpecificityWeightingEnabled(),
            $queuedRunTransfer->getFixedRelevanceWeight(),
            $queuedRunTransfer->getFixedSpecificityCurveExponent(),
            $queuedRunTransfer->getFixedSpecificityWeightExponent(),
            $queuedRunTransfer->getFixedSpecificityWeightShiftMagnitude(),
            $queuedRunTransfer->getFixedSpecificityBlendWeight(),
        );
        $populationSize = $this->computePopulationSize($mapper->getDimensionCount());
        $maxGenerations = $this->maxGenerations ?? SearchRankingOptimizerConfig::getOptimizationMaxGenerations();
        $algorithmName = $queuedRunTransfer->getAlgorithmOrFail();
        $warmStartFraction = $queuedRunTransfer->getWarmStartFraction() ?? 0.0;
        $warmStartVector = $warmStartFraction > 0.0 ? $mapper->mapConfigurationToVector($liveConfigurationTransfer) : null;
        $algorithm = $this->algorithmFactory->create(
            $algorithmName,
            $populationSize,
            $maxGenerations,
            $queuedRunTransfer->getIsTerminationCriteriaTrusted() ?? false,
            $warmStartVector,
            $warmStartFraction,
        );

        $this->entityManager->startOptimizerRun(
            $idOptimizerRun,
            $algorithm->estimateEvaluationCount(),
            $baselineScore,
        );

        $objectiveFunction = $this->buildObjectiveFunction($mapper, $liveConfigurationTransfer->getRelevanceSaturationPointOrFail(), $storeName, $localeName, $idOptimizerRun);
        $problem = new CallableProblem($objectiveFunction, $mapper->getLowerBounds(), $mapper->getUpperBounds());
        $result = $algorithm->optimize($problem);

        $bestConfigurationTransfer = $mapper->mapVectorToConfiguration($result->getBestVector(), $liveConfigurationTransfer->getRelevanceSaturationPointOrFail());

        $this->entityManager->completeOptimizerRun(
            $idOptimizerRun,
            $bestConfigurationTransfer->getRelevanceWeightOrFail(),
            $this->buildBestMetricWeightTransfers($activeMetrics, $bestConfigurationTransfer),
            -$result->getBestValue(),
            $bestConfigurationTransfer->getSpecificityBlendWeightOrFail(),
            $bestConfigurationTransfer->getSpecificityCurveExponentOrFail(),
            $bestConfigurationTransfer->getSpecificityWeightExponentOrFail(),
            $bestConfigurationTransfer->getSpecificityWeightShiftMagnitudeOrFail(),
            count($result->getBestValueHistory()),
        );
    }

    /**
     * Reads the LIVE configuration straight from search-ranking's own Zed-side facade (never the synced
     * key-value storage copy the storefront reads at request time) — this package's optimizer works
     * against what a Query Curator actually configured in Zed, not a possibly-stale-or-never-published
     * snapshot. Includes EVERY metric's weight (not just active ones), same as the real live formula does.
     *
     * @param string $storeName
     * @param string $localeName
     */
    protected function buildLiveConfiguration(string $storeName, string $localeName): SearchRankingConfigurationStorageTransfer
    {
        $metricWeightsByName = [];

        foreach ($this->searchRankingFacade->getMetricWeights($storeName, $localeName) as $metricWeight) {
            $metricWeightsByName[$metricWeight['name']] = $metricWeight['weight'];
        }

        return (new SearchRankingConfigurationStorageTransfer())
            ->setRelevanceWeight($this->searchRankingFacade->getRelevanceWeight($storeName, $localeName))
            ->setRelevanceSaturationPoint($this->searchRankingFacade->getRelevanceSaturationPoint($storeName, $localeName))
            ->setMetricWeights($metricWeightsByName)
            ->setSpecificityCurveExponent($this->searchRankingFacade->getSpecificityCurveExponent($storeName, $localeName))
            ->setSpecificityWeightExponent($this->searchRankingFacade->getSpecificityWeightExponent($storeName, $localeName))
            ->setSpecificityWeightShiftMagnitude($this->searchRankingFacade->getSpecificityWeightShiftMagnitude($storeName, $localeName))
            ->setSpecificityBlendWeight($this->searchRankingFacade->getSpecificityBlendWeight($storeName, $localeName));
    }

    /**
     * A human's own parameter-checklist choice at queue time (see `AutomatedWeightOptimizationController`),
     * keyed by metric name -- entirely independent of (and checked BEFORE) the determinism-based exclusion
     * {@see splitMetricsByDeterminism()} applies next. A metric can end up fixed for either reason, or both
     * at once; either way it's held constant for the whole run.
     *
     * @param \Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer $queuedRunTransfer
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer $liveConfigurationTransfer
     *
     * @return array<string, float>
     */
    protected function buildUserFixedMetricWeights(
        SearchRankingOptimizerRunTransfer $queuedRunTransfer,
        SearchRankingConfigurationStorageTransfer $liveConfigurationTransfer,
    ): array {
        $liveMetricWeightsByName = $liveConfigurationTransfer->getMetricWeights();
        $userFixedMetricWeights = [];

        foreach ($queuedRunTransfer->getFixedMetricWeights() as $fixedMetricWeightTransfer) {
            $name = $fixedMetricWeightTransfer->getNameOrFail();
            $userFixedMetricWeights[$name] = $fixedMetricWeightTransfer->getWeight() ?? (float)($liveMetricWeightsByName[$name] ?? 0.0);
        }

        return $userFixedMetricWeights;
    }

    /**
     * Splits the active metrics into the ones this run actually searches (a deterministic formula the
     * human did not choose to pin — the normal case) and the ones it holds fixed instead, because either
     * searching a weight against a non-deterministic formula (e.g. a placeholder/noise metric) is
     * meaningless, or a human explicitly chose to pin this metric on the run form's own checklist. A
     * metric missing its own `findMetricDetail()` row is treated as deterministic rather than silently
     * dropped, the same fail-open posture the rest of this class takes toward a metric deleted mid-run.
     * A store-wide metric (`isLocaleScoped=false`) is searched exactly like any other — its Apply write
     * goes through `search-ranking`'s own `saveMetricWeight()`, which already fans the write out to every
     * real locale of the store, the same as a human editing it manually from the Metrics page in any one
     * locale already does today.
     *
     * @param array<int, array{idSearchRankingMetric: int, name: string, isLocaleScoped: bool}> $activeMetrics
     * @param \Generated\Shared\Transfer\SearchRankingConfigurationStorageTransfer $liveConfigurationTransfer
     * @param string $storeName
     * @param string $localeName
     * @param array<string, float> $userFixedMetricWeights Name => the value a human chose to pin it at, see
     *   {@see buildUserFixedMetricWeights()}.
     *
     * @return array{0: array<int, array{idSearchRankingMetric: int, name: string, isLocaleScoped: bool}>, 1: array<string, float>}
     */
    protected function splitMetricsByDeterminism(
        array $activeMetrics,
        SearchRankingConfigurationStorageTransfer $liveConfigurationTransfer,
        string $storeName,
        string $localeName,
        array $userFixedMetricWeights = [],
    ): array {
        $optimizableMetrics = [];
        $fixedMetricWeights = [];
        $liveMetricWeightsByName = $liveConfigurationTransfer->getMetricWeights();

        foreach ($activeMetrics as $metric) {
            if (array_key_exists($metric['name'], $userFixedMetricWeights)) {
                $fixedMetricWeights[$metric['name']] = $userFixedMetricWeights[$metric['name']];

                continue;
            }

            $metricDetail = $this->searchRankingFacade->findMetricDetail($metric['idSearchRankingMetric'], $storeName, $localeName);
            $isDeterministic = $metricDetail === null || $this->formulaDeterminismChecker->isDeterministic($metricDetail['formula']);

            if ($isDeterministic) {
                $optimizableMetrics[] = $metric;

                continue;
            }

            $fixedMetricWeights[$metric['name']] = (float)($liveMetricWeightsByName[$metric['name']] ?? 0.0);
        }

        return [$optimizableMetrics, $fixedMetricWeights];
    }

    /**
     * Hansen's own classic CMA-ES default population size formula, computed here (not left to
     * CmaEsAlgorithm's own internal default) so the SAME population size is used for whichever algorithm
     * this run picked, and so the total evaluation count is knowable before the run actually starts.
     *
     * @param int $dimensionCount
     */
    protected function computePopulationSize(int $dimensionCount): int
    {
        return max(4, (int)(4 + floor(3 * log(max($dimensionCount, 2)))));
    }

    /**
     * Builds the closure {@see \BlackboxOptimizer\Algorithm\OptimizerAlgorithmInterface::optimize()}
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
     * Every metric this run searched (see {@see splitMetricsByDeterminism()}) gets a proposed transfer
     * here, including a store-wide (`isLocaleScoped=false`) one — {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationApplier}
     * writes each one back through `search-ranking`'s own `saveMetricWeight()`, which fans a store-wide
     * metric's write out to every real locale of the store, same as a manual edit from the Metrics page.
     *
     * @param array<int, array{idSearchRankingMetric: int, name: string, isLocaleScoped: bool}> $activeMetrics
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
