<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\GroundTruth\SearchRankingOptimizer;

// This package's test namespace isn't registered in the demoshop's composer.json autoload-dev PSR-4 map
// (only search-debug/search-ranking are) -- Codeception's own directory scanner only `include_once`s files
// matching *Test.php/*Cest.php, so a plain support class living alongside these tests (not matching either
// pattern) is never autoloaded at all. An explicit require_once is the most surgical fix -- no change to
// the demoshop's own composer.json (never committed, see the project's git workflow rules) needed.
require_once __DIR__ . '/SpecificityForcedEnabledFacadeDecorator.php';

use Codeception\Test\Unit;
use Elastica\Client;
use Elastica\Document;
use Elastica\Query;
use Elastica\Query\Ids;
use Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer;
use Generated\Shared\Transfer\SearchRankingEvaluationResponseTransfer;
use Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer;
use Generated\Shared\Transfer\SearchRankingQueryRatingTransfer;
use Generated\Shared\Transfer\SearchRankingQueryTransfer;
use Generated\Shared\Transfer\StoreTransfer;
use LogicException;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryQuery;
use Spryker\Client\SearchElasticsearch\Index\IndexNameResolver\IndexNameResolver;
use Spryker\Client\SearchElasticsearch\SearchElasticsearchConfig;
use Spryker\Shared\SearchElasticsearch\ElasticaClient\ElasticaClientFactory;
use SprykerCommunity\Client\SearchRanking\Dependency\Client\SearchRankingToStoreClientInterface;
use SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilder;
use SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculator;
use SprykerCommunity\Client\SearchRanking\Search\QueryTermFrequencyFetcher;
use SprykerCommunity\Client\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingStorageClientBridge;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\LiveCatalogSearchQueryBuilder;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\NeverInvokedStoreClient;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner;
use SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunnerInterface;
use SprykerCommunity\Client\SearchRankingStorage\SearchRankingStorageClient;
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use SprykerCommunity\Zed\SearchRanking\Business\SearchRankingFacade;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RankEvaluationRunner;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\RelevanceJudgmentGainMapper;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismChecker;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\AlgorithmFactory;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationRunner;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacade;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client\SearchRankingOptimizerToSearchRankingClientInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeBridge;
use SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Facade\SearchRankingOptimizerToSearchRankingFacadeInterface;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerEntityManager;
use SprykerCommunity\Zed\SearchRankingOptimizer\Persistence\SearchRankingOptimizerRepository;

/**
 * Shared machinery for the "opt-in ground truth" suite: not run by the default `codecept run` invocation
 * documented in the README (this suite lives in its own directory with its own `codeception.yml`, entirely
 * separate from `Zed/SearchRankingOptimizer`/`Client/SearchRankingOptimizer`) -- these tests hit a REAL,
 * live Elasticsearch/OpenSearch index and run a REAL automated optimization (population x generations of
 * real rank_eval HTTP calls), which is both slow (seconds to a couple of minutes per scenario) and, unlike
 * the rest of this package's test suite, asserts on the OUTCOME of a stochastic search rather than a pure
 * function -- appropriate for an occasional, deliberate "does the mechanism still work" check, not a CI gate.
 *
 * Portability: no product IDs, search terms, or metric names are hardcoded anywhere in this suite --
 * everything is discovered at runtime from whatever real rated queries and active metrics this shop
 * happens to have (see {@see discoverTwoRatedProductIdsAndSearchTerm()}/{@see discoverTwoOptimizableMetricNames()}),
 * so it works against any shop that has used this package's real curation workflow at all, not just this
 * demoshop's specific catalog content.
 *
 * Isolation from real data: every DB write goes through the real `TransactionHelper` (see this suite's own
 * `codeception.yml`), which wraps each test in a transaction rolled back afterward -- a synthetic query
 * cannot leak into real curation data even if an assertion throws mid-test. The one thing that ISN'T part
 * of that DB transaction is Elasticsearch itself: every test that overrides a product's `scores.*` fields
 * MUST read the real values first and restore them in a `finally` block (see {@see overrideScores()}/
 * {@see restoreScores()}), and give its synthetic query an overwhelming `importanceWeight` (see
 * {@see SYNTHETIC_IMPORTANCE_WEIGHT}) rather than touching any real query's own weight -- the real 30 (or
 * however many) rated queries in this shop keep evaluating alongside the synthetic one, just contributing
 * a statistically negligible fraction of the weighted aggregate.
 */
abstract class AbstractGroundTruthTest extends Unit
{
    /**
     * @var string
     */
    protected const STORE_NAME = 'DE';

    /**
     * @var string
     */
    protected const LOCALE_NAME = 'en_US';

    /**
     * `runNextOptimization()` processes the OLDEST queued run across the whole shop, not scoped to this
     * test's own (store, locale) or run id -- see {@see runRealOptimization()}'s own docblock for why a
     * bounded drain loop, not a single call, is needed to reliably get back the run this test just queued.
     *
     * @var int
     */
    protected const MAX_QUEUE_DRAIN_ATTEMPTS = 20;

    /**
     * Large enough that the real, already-rated queries in this shop (whatever their own importanceWeight)
     * contribute a statistically negligible fraction of the weighted aggregate rank_eval score -- see this
     * class's own docblock for why this is preferred over touching any real query's weight.
     *
     * @var float
     */
    protected const SYNTHETIC_IMPORTANCE_WEIGHT = 1_000_000.0;

    /**
     * @var string
     */
    protected const CUSTOMER_REFERENCE = 'ground-truth-test';

    protected ?SearchRankingOptimizerRepository $repository = null;

    protected ?SearchRankingOptimizerEntityManager $entityManager = null;

    protected ?SearchRankingOptimizerFacade $facade = null;

    protected ?SearchRankingOptimizerToSearchRankingFacadeInterface $searchRankingFacade = null;

    protected ?Client $elasticaClient = null;

    protected ?string $indexName = null;

    protected function getRepository(): SearchRankingOptimizerRepository
    {
        return $this->repository ??= new SearchRankingOptimizerRepository();
    }

    protected function getEntityManager(): SearchRankingOptimizerEntityManager
    {
        return $this->entityManager ??= new SearchRankingOptimizerEntityManager();
    }

    protected function getFacade(): SearchRankingOptimizerFacade
    {
        return $this->facade ??= new SearchRankingOptimizerFacade();
    }

    /**
     * The real bridge production code already uses, wrapping a real, live `search-ranking` Zed facade --
     * reuses the exact same array-shape translation {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Optimization\OptimizationRunner}
     * itself depends on, rather than re-deriving it from raw transfer collections here.
     */
    protected function getSearchRankingFacade(): SearchRankingOptimizerToSearchRankingFacadeInterface
    {
        return $this->searchRankingFacade ??= new SearchRankingOptimizerToSearchRankingFacadeBridge(new SearchRankingFacade());
    }

    protected function getElasticaClient(): Client
    {
        if ($this->elasticaClient === null) {
            $searchElasticsearchConfig = new SearchElasticsearchConfig();
            $this->elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());
        }

        return $this->elasticaClient;
    }

    protected function getIndexName(): string
    {
        if ($this->indexName === null) {
            $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), new SearchElasticsearchConfig());
            $this->indexName = $indexNameResolver->resolve(SearchRankingOptimizerConfig::PAGE_SOURCE_IDENTIFIER, static::STORE_NAME);
        }

        return $this->indexName;
    }

    /**
     * Same id format {@see \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunner::buildProductDocumentId()}
     * uses, matching this shop's real index.
     *
     * @param int $idProductAbstract
     */
    protected function buildProductDocumentId(int $idProductAbstract): string
    {
        return sprintf(
            'product_abstract:%s:%s:%d',
            strtolower(static::STORE_NAME),
            strtolower(static::LOCALE_NAME),
            $idProductAbstract,
        );
    }

    /**
     * Finds a real, already-rated query in this shop (any one, oldest-first) with at least 2 DISTINCT rated
     * products, and returns 2 of them plus that query's own search term -- no hardcoded product ids or
     * search terms anywhere, so this works against any shop that has real curation data at all.
     *
     * @return array{0: string, 1: int, 2: int} [searchTerm, idProductAbstractA, idProductAbstractB]
     */
    protected function discoverTwoRatedProductIdsAndSearchTerm(): array
    {
        $queryTransfers = $this->getRepository()->findQueriesByStoreLocale(static::STORE_NAME, static::LOCALE_NAME);
        $ratingTransfers = $this->getRepository()->findRatingsByStoreLocale(static::STORE_NAME, static::LOCALE_NAME);

        $productIdsByQueryId = [];

        foreach ($ratingTransfers as $ratingTransfer) {
            $queryId = $ratingTransfer->getFkSearchRankingQueryOrFail();
            $productIdsByQueryId[$queryId][$ratingTransfer->getFkProductAbstractOrFail()] = true;
        }

        foreach ($queryTransfers as $queryTransfer) {
            $queryId = $queryTransfer->getIdSearchRankingQueryOrFail();
            $productIds = array_keys($productIdsByQueryId[$queryId] ?? []);

            if (count($productIds) >= 2) {
                return [$queryTransfer->getSearchTermOrFail(), $productIds[0], $productIds[1]];
            }
        }

        $this->markTestSkipped('No real rated query with at least 2 distinct rated products exists for ' . static::STORE_NAME . '/' . static::LOCALE_NAME . ' -- nothing to build a ground truth on.');
    }

    /**
     * Finds 2 real ACTIVE metrics whose formula is deterministic (excluding e.g. a `random()` placeholder
     * metric the same way {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\Metric\FormulaDeterminismCheckerInterface}
     * would for a real optimization run) -- no hardcoded metric names.
     *
     * @return array{0: string, 1: string} [metricNameA, metricNameB]
     */
    protected function discoverTwoOptimizableMetricNames(): array
    {
        $names = [];

        foreach ($this->getSearchRankingFacade()->getActiveMetrics(static::STORE_NAME, static::LOCALE_NAME) as $metric) {
            $metricDetail = $this->getSearchRankingFacade()->findMetricDetail($metric['idSearchRankingMetric'], static::STORE_NAME, static::LOCALE_NAME);

            if ($metricDetail !== null && preg_match('/\brandom\s*\(/', $metricDetail['formula']) === 1) {
                continue;
            }

            $names[] = $metric['name'];

            if (count($names) === 2) {
                return [$names[0], $names[1]];
            }
        }

        $this->markTestSkipped('Fewer than 2 active, deterministic-formula metrics exist -- nothing to build a metric-weight ground truth on.');
    }

    /**
     * Every ACTIVE metric's name mapped to 0.0 -- including ones {@see discoverTwoOptimizableMetricNames()}
     * itself excludes (e.g. a non-deterministic `random()` metric, which is held FIXED at its live weight
     * rather than searched, but still contributes its real, uncontrolled `scores.random` value to the
     * business term unless explicitly zeroed here too). A relevanceWeight ground truth needs the ENTIRE
     * business term to be zero except for whichever ONE metric that scenario deliberately controls -- any
     * other real, uncontrolled metric value would be an uncontrolled alternate signal the optimizer could
     * exploit instead of the one actually being tested.
     *
     * @return array<string, float>
     */
    protected function buildAllActiveMetricsZeroedOut(): array
    {
        $zeroedScores = [];

        foreach ($this->getSearchRankingFacade()->getActiveMetrics(static::STORE_NAME, static::LOCALE_NAME) as $metric) {
            $zeroedScores[$metric['name']] = 0.0;
        }

        return $zeroedScores;
    }

    /**
     * The (search_term, store_name, locale_name) triple is unique, and {@see discoverTwoRatedProductIdsAndSearchTerm()}
     * deliberately reuses a real, already-existing query's own search term (to guarantee it actually returns
     * both discovered products) -- so a literal duplicate would collide. Appending " ." (a punctuation-only
     * token most analyzers strip as noise, not merely trailing whitespace MySQL's own PAD SPACE collation
     * comparison would silently treat as equal) sidesteps the DB constraint without changing which real
     * products the query returns.
     *
     * @param string $searchTerm
     */
    protected function insertSyntheticQuery(string $searchTerm): int
    {
        $queryTransfer = $this->getEntityManager()->createQuery(
            (new SearchRankingQueryTransfer())
                ->setSearchTerm($searchTerm . ' .')
                ->setStoreName(static::STORE_NAME)
                ->setLocaleName(static::LOCALE_NAME)
                ->setImportanceWeight(static::SYNTHETIC_IMPORTANCE_WEIGHT),
        );

        return $queryTransfer->getIdSearchRankingQueryOrFail();
    }

    /**
     * @param int $idSearchRankingQuery
     * @param int $idProductAbstract
     * @param string $ratingType
     */
    protected function insertSyntheticRating(int $idSearchRankingQuery, int $idProductAbstract, string $ratingType): void
    {
        $this->getEntityManager()->upsertRating(
            (new SearchRankingQueryRatingTransfer())
                ->setFkSearchRankingQuery($idSearchRankingQuery)
                ->setCustomerReference(static::CUSTOMER_REFERENCE)
                ->setFkProductAbstract($idProductAbstract)
                ->setRatingType($ratingType),
        );
    }

    /**
     * Reads the real, current `scores.*` sub-object of a product's document directly off the live index
     * (works despite `_source` filtering elsewhere in this suite's own history of diagnostics -- an
     * explicit `_source` field list still resolves against stored field data) -- the snapshot this class's
     * own {@see overrideScores()}/{@see restoreScores()} pair needs to put a real document back exactly as
     * it was.
     *
     * @param int $idProductAbstract
     *
     * @return array<string, float>
     */
    protected function readScores(int $idProductAbstract): array
    {
        $query = new Query();
        $query->setQuery(new Ids([$this->buildProductDocumentId($idProductAbstract)]));
        $query->setSource(['scores']);
        $query->setSize(1);

        $results = $this->getElasticaClient()->getIndex($this->getIndexName())->search($query)->getResults();

        if ($results === []) {
            return [];
        }

        return $results[0]->getSource()['scores'] ?? [];
    }

    /**
     * Partially updates ONLY the `scores` object of one real product's document -- every other field
     * (name, description, `is_active`, ...) is left completely untouched, so the document stays a real,
     * matchable catalog entry throughout the test.
     *
     * @param int $idProductAbstract
     * @param array<string, float> $scores
     */
    protected function overrideScores(int $idProductAbstract, array $scores): void
    {
        $document = new Document($this->buildProductDocumentId($idProductAbstract), ['scores' => $scores]);
        $document->setDocAsUpsert(false);
        $this->getElasticaClient()->getIndex($this->getIndexName())->updateDocument($document);
        $this->getElasticaClient()->getIndex($this->getIndexName())->refresh();
    }

    protected function refreshIndex(): void
    {
        $this->getElasticaClient()->getIndex($this->getIndexName())->refresh();
    }

    /**
     * Explicit interim cleanup between scenarios within ONE test method -- `TransactionHelper`'s own
     * rollback only fires once the whole test method finishes, so a test running scenario A then scenario B
     * must delete scenario A's synthetic query itself, or scenario B's optimizer run would see BOTH
     * simultaneously (same open transaction, same connection sees its own uncommitted writes). Cascades to
     * the query's own ratings (`onDelete="CASCADE"` on the FK).
     *
     * @param int $idSearchRankingQuery
     */
    protected function deleteSyntheticQuery(int $idSearchRankingQuery): void
    {
        SpySearchRankingQueryQuery::create()->filterByIdSearchRankingQuery($idSearchRankingQuery)->delete();
    }

    /**
     * The real, raw text-relevance order Elasticsearch itself returns for a search term -- used by the
     * relevanceWeight ground truth scenario to know, independent of any metric, which of 2 products text
     * relevance ALONE would already rank first.
     *
     * @param string $searchTerm
     *
     * @return array<int> Product abstract ids, best raw `_score` first.
     */
    protected function fetchRawTextRelevanceOrder(string $searchTerm): array
    {
        return array_keys($this->fetchRawTextRelevanceScores($searchTerm));
    }

    /**
     * @param string $searchTerm
     *
     * @return array<int, float> Product abstract id => raw `_score`, best first.
     */
    protected function fetchRawTextRelevanceScores(string $searchTerm): array
    {
        $queryBuilder = new LiveCatalogSearchQueryBuilder();
        $elasticaQuery = $queryBuilder->build($searchTerm, static::STORE_NAME, static::LOCALE_NAME);
        $query = Query::create($elasticaQuery->getQuery());
        $query->setSize(50);
        $query->setSource(false);

        $scoresByProductId = [];

        foreach ($this->getElasticaClient()->getIndex($this->getIndexName())->search($query)->getResults() as $result) {
            // The doc id itself already encodes the product abstract id -- see buildProductDocumentId() --
            // no need to depend on any particular _source field being present/retrievable.
            $productId = (int)substr((string)strrchr($result->getId(), ':'), 1);
            $scoresByProductId[$productId] = $result->getScore();
        }

        return $scoresByProductId;
    }

    /**
     * The top 2 raw text hits for a real, already-used search term -- deliberately ADJACENT in rank, not an
     * arbitrary rated pair: an arbitrary pair can have a huge natural `_score` gap (e.g. rank 1 vs. rank 40),
     * and `relevanceWeight`'s trust region (see `SearchRankingOptimizerConfig::getRelevanceWeightTrustRegionMaxDistance()`)
     * only allows a BOUNDED reduction from its live starting value -- a business-metric swing bounded to
     * [0;1] can only plausibly overturn a SMALL natural score gap within that bounded relevanceWeight
     * reduction, never an arbitrarily large one. Confirmed empirically: an earlier version of this test
     * using an arbitrary rated pair produced a relevanceWeight ceiling in BOTH scenarios (the natural gap
     * was too large for any reachable relevanceWeight to flip), which read as a false negative before this
     * fix, not evidence the optimizer doesn't work.
     *
     * @param string $searchTerm
     *
     * @return array{0: int, 1: int} [idProductAbstractRankedFirst, idProductAbstractRankedSecond]
     */
    protected function discoverAdjacentTextRelevancePair(string $searchTerm): array
    {
        $scoresByProductId = $this->fetchRawTextRelevanceScores($searchTerm);
        $productIds = array_keys($scoresByProductId);

        // Deliberately skips past any EXACT tie (common in this kind of catalog data -- see
        // discoverTiedTextRelevancePair()) to the first adjacent pair with a genuine, if small, score gap:
        // a tied pair would make relevanceWeight irrelevant to the objective either way (confirmed
        // empirically), which is exactly the opposite of what THIS discovery method's callers need.
        $productIdCount = count($productIds);

        for ($i = 0; $i < $productIdCount - 1; $i++) {
            if ($scoresByProductId[$productIds[$i]] !== $scoresByProductId[$productIds[$i + 1]]) {
                return [$productIds[$i], $productIds[$i + 1]];
            }
        }

        $this->markTestSkipped('No 2 adjacent real catalog matches have a non-zero raw _score gap for "' . $searchTerm . '" -- nothing to build a relevanceWeight ground truth on.');
    }

    /**
     * 2 real products with the EXACT same raw `_score` for a real search term -- ties among the top hits of
     * a real query are common (Lucene/BM25 scores collapse to identical floats for documents with identical
     * relevant field content, which product-catalog data generated from a shared template set produces
     * often). This is the metric-weight ground truth's own analog of {@see discoverAdjacentTextRelevancePair()}'s
     * "control for relevanceWeight" concern, but stricter: an ADJACENT pair can still have a small but real
     * score gap that lets relevanceWeight alone satisfy the ground truth (confirmed empirically -- an
     * earlier version of this test using the top-2 adjacent pair produced an arbitrary/reversed metric
     * weight, because relevanceWeight alone already ranked the rated-heart product first regardless of any
     * metric). A genuine TIE removes relevanceWeight as a lever entirely, whatever it converges to, forcing
     * the metric-weight comparison to be the only thing that CAN move the score.
     *
     * @param string $searchTerm
     *
     * @return array{0: int, 1: int}
     */
    protected function discoverTiedTextRelevancePair(string $searchTerm): array
    {
        $scoresByProductId = $this->fetchRawTextRelevanceScores($searchTerm);
        $productIds = array_keys($scoresByProductId);
        $productIdCount = count($productIds);

        for ($i = 0; $i < $productIdCount - 1; $i++) {
            if ($scoresByProductId[$productIds[$i]] === $scoresByProductId[$productIds[$i + 1]]) {
                return [$productIds[$i], $productIds[$i + 1]];
            }
        }

        $this->markTestSkipped('No 2 real catalog matches share the exact same raw _score for "' . $searchTerm . '" -- nothing to build a relevanceWeight-neutral metric-weight ground truth on.');
    }

    /**
     * Queues and fully processes ONE real optimization run through this package's own real, public Facade
     * -- the exact same call the Zed "Run now" button makes, no shortcuts. Blocks until done/failed since
     * this package's own console command does the same in-request for a shop this size (see the README).
     *
     * `runNextOptimization()` (`OptimizationRunner::runNext()`) dequeues the SINGLE OLDEST queued run
     * across the entire shop -- it has no store/locale/run-id scoping at all, by design (a real background
     * worker just drains a global work queue in FIFO order, and each run is correctly scoped once it's
     * picked up). That's fine for production, but not for this test: if anything else queued a run before
     * this call -- another Presentation suite's own `OptimizationCest::queueingARunNeverSilentlyAppliesLive()`
     * deliberately leaves one behind for its own default scope without processing it, and a real cron tick
     * or another admin action could too -- the very next `runNextOptimization()` call can silently process
     * THAT leftover run instead of the one this method just queued, and this method would misattribute its
     * (unrelated) failure to this test. Confirmed empirically: running this suite right after the
     * Presentation suite reproduces exactly this. So: drain and discard anything that isn't OUR run id,
     * bounded by {@see MAX_QUEUE_DRAIN_ATTEMPTS} rather than looping forever if something is genuinely
     * broken.
     *
     * @param string $algorithm
     */
    protected function runRealOptimization(
        string $algorithm = SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
    ): SearchRankingOptimizerRunTransfer {
        $queuedRunTransfer = $this->getFacade()->queueOptimizationRun(static::STORE_NAME, static::LOCALE_NAME, $algorithm);
        $idQueuedRun = $queuedRunTransfer->getIdSearchRankingOptimizerRunOrFail();

        for ($attempt = 0; $attempt < static::MAX_QUEUE_DRAIN_ATTEMPTS; $attempt++) {
            $runTransfer = $this->getFacade()->runNextOptimization();
            $this->assertNotNull($runTransfer, 'A run was just queued -- runNextOptimization() must pick it up.');

            if ($runTransfer->getIdSearchRankingOptimizerRunOrFail() !== $idQueuedRun) {
                continue;
            }

            $this->assertSame(
                SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE,
                $runTransfer->getStatus(),
                'Run failed: ' . ($runTransfer->getErrorMessage() ?? '(no error message)'),
            );

            return $runTransfer;
        }

        $this->fail(sprintf(
            'Queued run #%d never came back from runNextOptimization() after %d attempts -- the shared run '
                . 'queue has a bigger leftover backlog than expected (see this method\'s own docblock for why '
                . 'the queue can contain other actors\' runs).',
            $idQueuedRun,
            static::MAX_QUEUE_DRAIN_ATTEMPTS,
        ));
    }

    /**
     * Runs a real optimization $times times and returns the MEDIAN of whatever $extractor pulls out of each
     * winning candidate -- a single rated pair per synthetic query gives `rank_eval`'s nDCG an almost
     * step-function landscape (the score is flat everywhere except right at the exact parameter value where
     * the 2 rated products' relative order flips), which a population-based search can occasionally fail to
     * climb from an unlucky random initialization even on an otherwise-easy, unambiguous ground truth --
     * confirmed empirically (the same construction passed on some runs and failed on others, landing near
     * BOTH extremes across separate runs, not just partially converged). Repeating and taking the median is
     * the direct fix for exactly the caveat this suite's own tests were designed around from the start: a
     * real optimizer has a random component, so a single run is not a reliable enough signal on its own.
     *
     * @param callable(\Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer): float $extractor
     * @param int $times
     * @param string $algorithm
     */
    protected function runRealOptimizationRepeatedMedian(
        callable $extractor,
        int $times = 3,
        string $algorithm = SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
    ): float {
        $values = [];

        for ($i = 0; $i < $times; $i++) {
            $values[] = $extractor($this->runRealOptimization($algorithm));
        }

        sort($values);

        return $values[intdiv(count($values), 2)];
    }

    /**
     * Scans every real, already-used search term this shop has (no hardcoded terms), computes each one's
     * real raw specificity (blended per-term idf, the same way {@see \SprykerCommunity\Client\SearchRanking\Search\QuerySpecificityCalculator}
     * does live) via a real `_termvectors` probe, and returns the most SPECIFIC (highest idf -- a rare
     * term like a SKU) and most UNSPECIFIC (lowest idf -- an only-common-words query) terms found. A term
     * with zero query-term idf values at all (nothing survived the doc_freq>0 filter) is skipped -- same
     * floor {@see \SprykerCommunity\Client\SearchRanking\Search\SpecificityWeightCalculator} itself enforces.
     *
     * @return array{0: string, 1: string} [mostSpecificSearchTerm, mostUnspecificSearchTerm]
     */
    protected function discoverSpecificAndUnspecificSearchTerms(): array
    {
        $termFrequencyFetcher = $this->createQueryTermFrequencyFetcher();
        $querySpecificityCalculator = new QuerySpecificityCalculator();
        $fieldToSearchAnalyzer = [
            'full-text' => 'fulltext_search_analyzer',
            'full-text-boosted' => 'fulltext_search_analyzer',
        ];

        $rawSpecificityByTerm = [];

        foreach ($this->getRepository()->findQueriesByStoreLocale(static::STORE_NAME, static::LOCALE_NAME) as $queryTransfer) {
            $searchTerm = $queryTransfer->getSearchTermOrFail();
            $termFrequencyResult = $termFrequencyFetcher->fetch($searchTerm, $fieldToSearchAnalyzer);
            $docCount = $termFrequencyResult->getDocCount();

            if ($docCount <= 0) {
                continue;
            }

            $idfByTerm = [];

            foreach ($termFrequencyResult->getTermDocumentFrequencies() as $term => $documentFrequency) {
                if ($documentFrequency <= 0) {
                    continue;
                }

                $idfByTerm[$term] = max(0.0, log($docCount / $documentFrequency));
            }

            if ($idfByTerm === []) {
                continue;
            }

            $rawSpecificityByTerm[$searchTerm] = $querySpecificityCalculator->calculateRawSpecificity($idfByTerm, 0.7);
        }

        if (count($rawSpecificityByTerm) < 2) {
            $this->markTestSkipped('Fewer than 2 real search terms have usable corpus idf evidence -- nothing to build a specificity ground truth on.');
        }

        asort($rawSpecificityByTerm);
        $terms = array_keys($rawSpecificityByTerm);

        return [$terms[count($terms) - 1], $terms[0]];
    }

    /**
     * @throws \LogicException
     */
    protected function createQueryTermFrequencyFetcher(): QueryTermFrequencyFetcher
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $indexName = $this->getIndexName();

        return new class (
            $this->getElasticaClient(),
            $searchElasticsearchConfig,
            // Not NeverInvokedStoreClient -- that implements SearchElasticsearch's own store-client
            // interface, not search-ranking's. Same "structurally required but never actually exercised"
            // reasoning applies: resolveIndexName() below is overridden and never touches $storeClient.
            new class implements SearchRankingToStoreClientInterface {
            /**
             * @throws \LogicException
             *
             * @return \Generated\Shared\Transfer\StoreTransfer
             */
            public function getCurrentStore(): StoreTransfer
            {
            throw new LogicException(__METHOD__ . '() was called -- resolveIndexName() below should have made this unreachable.');
            }
            },
            $indexName,
        ) extends QueryTermFrequencyFetcher
        {
            protected string $fixedIndexName;

            /**
             * @param \Elastica\Client $elasticaClient
             * @param \Spryker\Client\SearchElasticsearch\SearchElasticsearchConfig $searchElasticsearchConfig
             * @param \SprykerCommunity\Client\SearchRanking\Dependency\Client\SearchRankingToStoreClientInterface $storeClient
             * @param string $fixedIndexName
             */
            public function __construct(
                Client $elasticaClient,
                SearchElasticsearchConfig $searchElasticsearchConfig,
                SearchRankingToStoreClientInterface $storeClient,
                string $fixedIndexName,
            ) {
                parent::__construct($elasticaClient, $searchElasticsearchConfig, $storeClient);
                $this->fixedIndexName = $fixedIndexName;
            }

            /**
             * Overridden to bypass Store resolution entirely -- this ground-truth suite already resolves
             * the real index name once via {@see AbstractGroundTruthTest::getIndexName()}.
             */
            protected function resolveIndexName(): string
            {
                return $this->fixedIndexName;
            }
        };
    }

    /**
     * Constructs and runs a REAL `OptimizationRunner` end-to-end, bypassing this package's own DI Factory
     * and the `search-ranking-optimizer:optimize` console command entirely -- necessary ONLY because
     * `SearchRankingConfig::isSpecificityWeightingEnabled()` is a hardcoded `return false;` with no project
     * override actually wired up anywhere (same finding as {@see \SprykerCommunityTest\Client\SearchRankingOptimizer\Search\RankEvalRunnerTest}'s
     * own forced-enabled subclass), so the real Facade path would silently exclude the specificity
     * dimensions from the search entirely on a shop where the feature is off, as it is here. Every OTHER
     * collaborator (repository, entity manager, rank evaluation runner, determinism checker,
     * `ParameterVectorMapper`) is the real, unmodified production class -- only specificity's enablement is
     * forced, at exactly the 2 seams that ever check it.
     *
     * @param string $algorithm
     *
     * @throws \LogicException
     */
    protected function runRealOptimizationWithSpecificityForcedEnabled(
        string $algorithm = SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
    ): SearchRankingOptimizerRunTransfer {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());
        $indexNameResolver = new IndexNameResolver(new NeverInvokedStoreClient(), $searchElasticsearchConfig);

        $forcedEnabledRankEvalRunner = new class (
            $elasticaClient,
            $indexNameResolver,
            new LiveCatalogSearchQueryBuilder(),
            new FunctionScoreBuilder(),
            new SearchRankingOptimizerToSearchRankingStorageClientBridge(new SearchRankingStorageClient()),
            new QuerySpecificityCalculator(),
        ) extends RankEvalRunner {
            protected function isSpecificityWeightingEnabled(): bool
            {
                return true;
            }
        };

        $searchRankingClientDouble = new class ($forcedEnabledRankEvalRunner) implements SearchRankingOptimizerToSearchRankingClientInterface {
            protected RankEvalRunnerInterface $rankEvalRunner;

            /**
             * @param \SprykerCommunity\Client\SearchRankingOptimizer\Search\RankEvalRunnerInterface $rankEvalRunner
             */
            public function __construct(RankEvalRunnerInterface $rankEvalRunner)
            {
                $this->rankEvalRunner = $rankEvalRunner;
            }

            /**
             * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter -- signature is fixed by the
             *   interface this test double implements; never called by the optimization path it exists for.
             *
             * @param string $searchTerm
             * @param string $storeName
             * @param string $localeName
             * @param int $limit
             *
             * @throws \LogicException
             *
             * @return array<float>
             */
            public function getCalibrationScores(string $searchTerm, string $storeName, string $localeName, int $limit): array
            {
                throw new LogicException('Not used by the optimization path this test double exists for.');
            }

            /**
             * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter -- signature is fixed by the
             *   interface this test double implements; never called by the optimization path it exists for.
             *
             * @param string $searchTerm
             * @param string $storeName
             * @param float $blendWeight
             *
             * @throws \LogicException
             */
            public function getCalibrationSpecificity(string $searchTerm, string $storeName, float $blendWeight): float
            {
                throw new LogicException('Not used by the optimization path this test double exists for.');
            }

            /**
             * @param \Generated\Shared\Transfer\SearchRankingEvaluationRequestTransfer $requestTransfer
             */
            public function evaluateRankings(SearchRankingEvaluationRequestTransfer $requestTransfer): SearchRankingEvaluationResponseTransfer
            {
                return $this->rankEvalRunner->evaluate($requestTransfer);
            }

            /**
             * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter -- signature is fixed by the
             *   interface this test double implements; never called by the optimization path it exists for.
             *
             * @param string $searchTerm
             * @param string $storeName
             * @param string $localeName
             * @param int $idProductAbstract
             *
             * @throws \LogicException
             */
            public function productMatchesSearch(string $searchTerm, string $storeName, string $localeName, int $idProductAbstract): bool
            {
                throw new LogicException('Not used by the optimization path this test double exists for.');
            }
        };

        $forcedEnabledFacade = new SpecificityForcedEnabledFacadeDecorator($this->getSearchRankingFacade());

        $optimizationRunner = new OptimizationRunner(
            $this->getRepository(),
            $this->getEntityManager(),
            $forcedEnabledFacade,
            new RankEvaluationRunner($this->getRepository(), $this->getEntityManager(), $searchRankingClientDouble, new RelevanceJudgmentGainMapper()),
            new FormulaDeterminismChecker(),
            new AlgorithmFactory(),
        );

        $queuedRunTransfer = $this->getEntityManager()->createOptimizerRun(
            (new SearchRankingOptimizerRunTransfer())
                ->setStoreName(static::STORE_NAME)
                ->setLocaleName(static::LOCALE_NAME)
                ->setAlgorithm($algorithm),
        );
        $idQueuedRun = $queuedRunTransfer->getIdSearchRankingOptimizerRunOrFail();

        // Same global-FIFO dequeue as OptimizationRunnerInterface::runNext() itself (this IS that real
        // method, just constructed by hand here) -- see runRealOptimization()'s own docblock for why a
        // bounded drain loop, not a single call, is needed to reliably process the run just queued above
        // rather than some other leftover queued run.
        for ($attempt = 0; $attempt < static::MAX_QUEUE_DRAIN_ATTEMPTS; $attempt++) {
            $optimizationRunner->runNext();
            $runTransfer = $this->getRepository()->findOptimizerRunById($idQueuedRun);
            $this->assertNotNull($runTransfer, 'The run this method itself just created must still be findable by id.');

            if ($runTransfer->getStatus() === SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_QUEUED) {
                continue;
            }

            $this->assertSame(
                SearchRankingOptimizerConfig::OPTIMIZATION_RUN_STATUS_DONE,
                $runTransfer->getStatus(),
                'Run failed: ' . ($runTransfer->getErrorMessage() ?? '(no error message)'),
            );

            return $runTransfer;
        }

        $this->fail(sprintf(
            'Queued run #%d was still \'queued\' after %d runNext() attempts -- the shared run queue has a '
                . 'bigger leftover backlog than expected.',
            $idQueuedRun,
            static::MAX_QUEUE_DRAIN_ATTEMPTS,
        ));
    }
}
