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

    /**
     * Lower bound on the required metricA-vs-metricB lead {@see discoverMarginalTextRelevancePair()} sizes
     * its pair to -- see that method's own docblock for why a bare "ranks correctly at all" (lead > 0) isn't
     * a reliable pass/fail line for this ground truth. Below this, run-to-run noise (see
     * {@see runRealOptimizationRepeatedMedian()}) can still straddle the sign.
     *
     * @var float
     */
    protected const METRIC_WEIGHT_LEAD_THRESHOLD_MIN = 0.1;

    /**
     * Upper bound on the same required lead -- past this, satisfying the ground truth would demand a lead
     * close to the full [0;1] weight budget, which a real shop's natural catalog score gaps may not even
     * make reachable (see {@see discoverMarginalTextRelevancePair()}).
     *
     * @var float
     */
    protected const METRIC_WEIGHT_LEAD_THRESHOLD_MAX = 0.6;

    /**
     * The relevanceWeight {@see discoverMarginalTextRelevancePair()} and the metric-weight ground truth PIN
     * their runs to, via {@see runRealOptimization()}'s own `$fixedRelevanceWeight` -- deliberately NOT this
     * shop's live value (confirmed at ~0.01 for DE/en_US, see this suite's own memory/README on the
     * relevanceWeight ground truth). At a live value that low, {@see FunctionScoreBuilder}'s blend formula
     * already gives the business term a ~0.99 coefficient, which sounds like it should make ANY metric lead
     * decisive -- but the natural text-relevance gap it has to overcome is ALSO scaled by the same tiny
     * relevanceWeight, so the REQUIRED lead (`relevanceWeight * gap / (1 - relevanceWeight)`) stays tiny
     * regardless (confirmed empirically: 0.0002-0.0050 across every real pair for a real search term in this
     * shop, even using the widest-possible trust-region bound). Pinning relevanceWeight HIGH instead does the
     * opposite: it shrinks the business coefficient, which INFLATES the lead required to overturn the very
     * same natural gap -- turning this shop's small catalog score spread into a comfortably-sized threshold.
     * 0.90 keeps `1 - relevanceWeight` far enough from 0 that the required lead stays computable and finite
     * without needing an implausibly large gap to reach the workable threshold range -- confirmed empirically
     * that 0.85 wasn't quite enough on its own: combined with {@see discoverMarginalTextRelevancePair()}'s
     * OWN cutoff-window restriction (a pair also has to stay inside rank_eval's top-`cutoff` results, see
     * that method's docblock), the widest gap available for a real search term in this shop produced a
     * threshold of ~0.088, just under {@see METRIC_WEIGHT_LEAD_THRESHOLD_MIN}.
     *
     * @var float
     */
    protected const METRIC_WEIGHT_TEST_FIXED_RELEVANCE_WEIGHT = 0.9;

    /**
     * How many additional FILLER pairs {@see selectFillerMarginalTextRelevancePairs()} adds alongside the
     * single pair {@see discoverMarginalTextRelevancePair()} measures against. Confirmed empirically that a
     * single synthetic pair alone makes `rank_eval`'s aggregate nDCG a near-total-flat landscape CMA-ES's own
     * plateau detection stops on well before any real optimum (`generationsUsed` stuck at 29 regardless of a
     * 150/600/1500 generation cap) -- see {@see discoverMarginalTextRelevancePair()}'s own docblock for the
     * full root-cause trace. 4 is not derived from anything more principled than "more than 1, small enough
     * to stay cheap (each filler is 2 more `overrideScores()`/`refreshIndex()` round-trips per scenario)" --
     * revisit if the ground truth is still unstable with this many.
     *
     * @var int
     */
    protected const METRIC_WEIGHT_FILLER_PAIR_COUNT = 4;

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
        return $this->buildAllActiveMetricsSetTo(0.0);
    }

    /**
     * Every ACTIVE metric's name mapped to the SAME value -- the metric-weight analog of
     * {@see discoverTiedTextRelevancePair()}'s "remove relevanceWeight as a lever" trick, and the reason a
     * relevanceWeight ground truth can be built at all.
     *
     * The business term is `Σ weightᵢ × signalᵢ`, and the metric weights are simplex-constrained (they sum
     * to 1). Give every metric the same signal `s` on a product and that term collapses to `s × Σ weightᵢ`
     * = `s`, for ANY weight vector the optimizer can reach. Metric weights therefore cannot influence the
     * ranking at all, which leaves relevanceWeight as the only parameter that CAN -- exactly the isolation
     * a relevanceWeight ground truth needs, and exactly what putting the signal on a single metric fails to
     * achieve (there the optimizer can satisfy the ground truth by moving that metric's weight instead,
     * leaving relevanceWeight free to settle anywhere).
     *
     * @param float $value
     *
     * @return array<string, float>
     */
    protected function buildAllActiveMetricsSetTo(float $value): array
    {
        $scores = [];

        foreach ($this->getSearchRankingFacade()->getActiveMetrics(static::STORE_NAME, static::LOCALE_NAME) as $metric) {
            $scores[$metric['name']] = $value;
        }

        return $scores;
    }

    /**
     * The gap between 2 products' NORMALIZED text relevance -- `_score / (_score + relevanceSaturationPoint)`,
     * the same saturating transform {@see \SprykerCommunity\Client\SearchRanking\Query\FunctionScoreBuilder}
     * applies live, so this is directly comparable to a business signal in `[0;1]` rather than to a raw,
     * unbounded `_score`.
     *
     * Callers need it to size a business signal AGAINST the text gap: with a uniform signal `s` on one
     * product and 0 on the other (see {@see buildAllActiveMetricsSetTo()}), the two rank equally at exactly
     * `relevanceWeight = s / (textGap + s)`. Choosing `s = textGap` puts that tipping point at 0.5, which is
     * what lets one scenario need a relevanceWeight above it and the other below it.
     *
     * @param int $idProductAbstractHigher
     * @param int $idProductAbstractLower
     * @param string $searchTerm
     */
    protected function computeNormalizedTextRelevanceGap(
        int $idProductAbstractHigher,
        int $idProductAbstractLower,
        string $searchTerm,
    ): float {
        $rawScores = $this->fetchRawTextRelevanceScores($searchTerm);
        $saturationPoint = $this->getSearchRankingFacade()->getRelevanceSaturationPoint(static::STORE_NAME, static::LOCALE_NAME);

        $normalize = static fn (float $score): float => $score / ($score + $saturationPoint);

        return $normalize($rawScores[$idProductAbstractHigher]) - $normalize($rawScores[$idProductAbstractLower]);
    }

    /**
     * The (search_term, store_name, locale_name) triple is unique, and {@see discoverTwoRatedProductIdsAndSearchTerm()}
     * deliberately reuses a real, already-existing query's own search term (to guarantee it actually returns
     * both discovered products) -- so a literal duplicate would collide. Appending a punctuation-only
     * `$suffix` (most analyzers strip it as noise, not merely trailing whitespace MySQL's own PAD SPACE
     * collation comparison would silently treat as equal) sidesteps the DB constraint without changing
     * which real products the query returns. Defaults to "." for every existing single-query caller;
     * callers inserting SEVERAL synthetic queries against the SAME base search term (e.g. one measured
     * pair plus several filler pairs, see {@see \SprykerCommunityTest\GroundTruth\SearchRankingOptimizer\RelevanceWeightAndMetricWeightGroundTruthTest::testMetricWeightConvergesTowardWhicheverMetricTheGroundTruthFavors()})
     * must pass a distinct suffix per call, or the 2nd+ call collides with the 1st.
     *
     * @param string $searchTerm
     * @param string $suffix
     */
    protected function insertSyntheticQuery(string $searchTerm, string $suffix = '.'): int
    {
        $queryTransfer = $this->getEntityManager()->createQuery(
            (new SearchRankingQueryTransfer())
                ->setSearchTerm($searchTerm . ' ' . $suffix)
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
     * Every real catalog-match pair for a real search term, within rank_eval's own cutoff ({@see
     * \SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig::getRankEvalCutoff()}),
     * that has a non-zero natural raw `_score` gap -- sorted ascending by that gap. Confirmed empirically
     * that a pair pulled from OUTSIDE the cutoff (rank 45 of 50, in one observed run) makes any ground
     * truth built on it structurally unprovable: rank_eval never evaluates a hit that isn't in its own
     * top-`cutoff` results, so a product starting outside that window would need to out-rank every OTHER
     * real catalog match still ahead of it too, not just its own pair partner -- not something 2 metrics on
     * 2 products can realistically buy. That produced a metric_score of EXACTLY 0.0 for every candidate in
     * every run, so nothing scored against it ever separated from 0 either.
     *
     * Callers needing a SINGLE decisive pair should use {@see discoverMarginalTextRelevancePair()}. This
     * method exists for callers that need SEVERAL pairs spread across the gap distribution instead -- see
     * that method's own docblock for why a single synthetic pair alone produces a fitness landscape CMA-ES's
     * own plateau-detection can get stuck on, confirmed via `generationsUsed` staying at 29 regardless of a
     * 150/600/1500 generation cap.
     *
     * @param string $searchTerm
     *
     * @return array<array{0: int, 1: int, 2: float}> [idProductAbstractTextLoser, idProductAbstractTextWinner,
     *   gap], sorted ascending by gap.
     */
    protected function discoverAllMarginalTextRelevancePairs(string $searchTerm): array
    {
        $scoresByProductId = $this->fetchRawTextRelevanceScores($searchTerm);
        $productIds = array_keys($scoresByProductId);
        $productIdCount = count($productIds);

        $saturationPoint = $this->getSearchRankingFacade()->getRelevanceSaturationPoint(static::STORE_NAME, static::LOCALE_NAME);
        $normalize = static fn (float $score): float => $score / ($score + $saturationPoint);

        $rankEvalCutoff = SearchRankingOptimizerConfig::getRankEvalCutoff();
        $consideredProductIdCount = min($productIdCount, $rankEvalCutoff);

        $pairs = [];

        for ($i = 0; $i < $consideredProductIdCount - 1; $i++) {
            for ($j = $i + 1; $j < $consideredProductIdCount; $j++) {
                $idProductAbstractHigher = $productIds[$i];
                $idProductAbstractLower = $productIds[$j];
                $gap = $normalize($scoresByProductId[$idProductAbstractHigher]) - $normalize($scoresByProductId[$idProductAbstractLower]);

                if ($gap <= 0.0) {
                    continue;
                }

                $pairs[] = [$idProductAbstractLower, $idProductAbstractHigher, $gap];
            }
        }

        usort($pairs, static fn (array $left, array $right): int => $left[2] <=> $right[2]);

        return $pairs;
    }

    /**
     * The single pair from {@see discoverAllMarginalTextRelevancePairs()} with the LARGEST gap whose
     * implied metric-weight lead threshold still falls inside [{@see METRIC_WEIGHT_LEAD_THRESHOLD_MIN};
     * {@see METRIC_WEIGHT_LEAD_THRESHOLD_MAX}] -- sized so that overturning their natural text-relevance
     * order genuinely REQUIRES a metricA-vs-metricB lead of at least that threshold, not just any lead > 0.
     * The run this pair feeds MUST be queued with `$fixedRelevanceWeight = `
     * {@see METRIC_WEIGHT_TEST_FIXED_RELEVANCE_WEIGHT} (see {@see runRealOptimization()}) -- the threshold
     * this method returns is only valid for that exact value.
     *
     * {@see discoverTiedTextRelevancePair()} (an exact tie) was this test's original approach and remains
     * correct in theory: with text relevance perfectly neutralized, ANY lead in the right direction ranks
     * the pair correctly. In practice that's exactly the problem -- rank_eval's nDCG over a single rated
     * pair is a step function (every lead that ranks it correctly scores identically), so a population-based
     * search has NO further gradient once it crosses zero, and the winning candidate {@see runRealOptimization()}
     * reports back is just whichever one happened to cross first. That candidate lands within a hair of the
     * true zero, on either side, depending on random sampling -- confirmed empirically across several real
     * runs landing leads of -0.0504, -0.0136, -0.0433, and +0.0552, i.e. flipping sign run to run, never far
     * from 0.
     *
     * Using a pair with a genuine (if small) natural text-relevance gap instead moves the whole step function
     * away from zero: ranking the weaker-text product first now additionally has to out-vote that natural
     * gap, which -- given {@see FunctionScoreBuilder}'s blend formula -- requires
     * `lead > relevanceWeight * gap / (1 - relevanceWeight)`. relevanceWeight would ALSO be free within this
     * run by default (its own trust region), which is why {@see runRealOptimization()} PINS it for this
     * ground truth via `$fixedRelevanceWeight` rather than merely reasoning about the worst case it could
     * reach -- confirmed empirically that reasoning about the worst case isn't enough on its own here: this
     * shop's LIVE relevanceWeight is ~0.01, and even its widest trust-region bound (~0.16) only produced
     * thresholds of 0.0002-0.0050 for a real search term's ENTIRE catalog spread, nowhere near
     * {@see METRIC_WEIGHT_LEAD_THRESHOLD_MIN}. Pinning relevanceWeight to
     * {@see METRIC_WEIGHT_TEST_FIXED_RELEVANCE_WEIGHT} (0.85, comfortably away from both this shop's live
     * value and 1.0) inflates the SAME natural gaps into a workable threshold instead -- see that constant's
     * own docblock for the reasoning. The step function's edge is then pinned at that (comfortably positive)
     * threshold instead of at 0, so the same run-to-run sampling noise that used to straddle the sign now
     * straddles a clearly-positive number instead.
     *
     * ROOT CAUSE, traced past this method entirely (confirmed empirically, not theoretical): even with a
     * comfortably-sized threshold, `rank_eval`'s nDCG over a SINGLE rated pair is still piecewise-constant
     * across most of the parameter space -- it only changes at the handful of points where that one pair's
     * relative rank actually flips. CMA-ES's own TolFun plateau-detection ({@see
     * \BlackboxOptimizer\Algorithm\Internal\TerminationCriteria::hasFlatFitnessHistory()}, part of the
     * `andrebarthelmeshellmuth/blackbox-optimizer` dependency) correctly detects long flat stretches of that
     * staircase as "converged" and stops -- confirmed via `generationsUsed` landing at 29 regardless of
     * whether `maxGenerations` was capped at 150, 600, or 1500, so the real budget was never the constraint.
     * Which "step" of the staircase a run happens to be sitting in when that fires is essentially decided by
     * CMA-ES's own random initialization, which is exactly the wild run-to-run variance measured here
     * (individual runs from -0.09 to +0.28, uncorrelated with generation budget). A largest-gap vs.
     * smallest-gap pick (both tried) only moves WHERE the threshold sits, not how coarse the staircase
     * around it is, so neither reliably fixes this alone.
     *
     * The actual fix lives one level up, in {@see \SprykerCommunityTest\GroundTruth\SearchRankingOptimizer\RelevanceWeightAndMetricWeightGroundTruthTest::testMetricWeightConvergesTowardWhicheverMetricTheGroundTruthFavors()}:
     * several FILLER pairs from {@see discoverAllMarginalTextRelevancePairs()}, each crossing its own lead
     * threshold at a different point, give the aggregate objective many more real steps spread across the
     * swept lead range -- much closer to what a real shop's dozens of independently-rated queries already
     * look like, and much less likely to sit flat for 10 straight generations. This method still supplies
     * the ONE pair the test's own assertion measures against; it no longer needs to single-handedly carry
     * the whole landscape's shape.
     *
     * @param string $searchTerm
     *
     * @return array{0: int, 1: int, 2: float} [idProductAbstractTextLoser, idProductAbstractTextWinner,
     *   requiredLeadThreshold] -- the text LOSER is the one this test's scenarios rate as the ground-truth
     *   winner, since it's the one that needs metric weight's help to rank first.
     */
    protected function discoverMarginalTextRelevancePair(string $searchTerm): array
    {
        $fixedRelevanceWeight = static::METRIC_WEIGHT_TEST_FIXED_RELEVANCE_WEIGHT;
        $relevanceWeightCoefficient = $fixedRelevanceWeight / (1 - $fixedRelevanceWeight);

        $bestPair = null;
        $bestGap = 0.0;
        $requiredLeadThreshold = null;

        foreach ($this->discoverAllMarginalTextRelevancePairs($searchTerm) as [$idLoser, $idWinner, $gap]) {
            $threshold = $relevanceWeightCoefficient * $gap;

            if ($threshold < static::METRIC_WEIGHT_LEAD_THRESHOLD_MIN || $threshold > static::METRIC_WEIGHT_LEAD_THRESHOLD_MAX) {
                continue;
            }

            if ($gap <= $bestGap) {
                continue;
            }

            $bestGap = $gap;
            $bestPair = [$idLoser, $idWinner];
            $requiredLeadThreshold = $threshold;
        }

        if ($bestPair === null) {
            $this->markTestSkipped(sprintf(
                'No real text-relevance gap for "%s" implies a metric-weight lead threshold inside the'
                    . ' workable [%.2f;%.2f] range -- nothing to build a decisive metric-weight ground'
                    . ' truth on.',
                $searchTerm,
                static::METRIC_WEIGHT_LEAD_THRESHOLD_MIN,
                static::METRIC_WEIGHT_LEAD_THRESHOLD_MAX,
            ));
        }

        return [$bestPair[0], $bestPair[1], $requiredLeadThreshold];
    }

    /**
     * Up to {@see METRIC_WEIGHT_FILLER_PAIR_COUNT} additional pairs from {@see discoverAllMarginalTextRelevancePairs()},
     * spread evenly across the gap distribution (not clustered near the measured pair's own gap), excluding
     * any pair that shares a product with `$excludedProductIds` -- {@see discoverMarginalTextRelevancePair()}'s
     * own measured pair, so a filler never silently doubles as (or conflicts with) the pair the test's own
     * assertion depends on.
     *
     * Spread across the FULL gap range on purpose, not just gaps near the measured pair's own threshold:
     * each filler crosses its OWN rank-flip point at a different metricA-vs-metricB lead value (small gaps
     * flip early, large gaps flip late), so together they give the aggregate `rank_eval` objective real
     * gradient across the whole swept lead range -- much closer to what a real shop's dozens of
     * independently-rated queries already look like than the single pair {@see discoverMarginalTextRelevancePair()}
     * alone could provide. See that method's own docblock for the full root-cause trace this exists to fix.
     *
     * @param string $searchTerm
     * @param array<int> $excludedProductIds
     *
     * @return array<array{0: int, 1: int}> [idProductAbstractTextLoser, idProductAbstractTextWinner] pairs.
     */
    protected function selectFillerMarginalTextRelevancePairs(string $searchTerm, array $excludedProductIds): array
    {
        $candidates = array_values(array_filter(
            $this->discoverAllMarginalTextRelevancePairs($searchTerm),
            static fn (array $pair): bool => !in_array($pair[0], $excludedProductIds, true) && !in_array($pair[1], $excludedProductIds, true),
        ));

        $candidateCount = count($candidates);
        $desiredCount = min(static::METRIC_WEIGHT_FILLER_PAIR_COUNT, $candidateCount);

        if ($desiredCount === 0) {
            return [];
        }

        $selectedByIndex = [];

        for ($k = 0; $k < $desiredCount; $k++) {
            $index = $desiredCount > 1
                ? (int)round($k * ($candidateCount - 1) / ($desiredCount - 1))
                : intdiv($candidateCount, 2);
            $selectedByIndex[$index] = [$candidates[$index][0], $candidates[$index][1]];
        }

        return array_values($selectedByIndex);
    }

    /**
     * Overrides scores and inserts one synthetic query+rating per filler pair, in the SAME "loser gets the
     * favored metric, winner gets the other" orientation {@see \SprykerCommunityTest\GroundTruth\SearchRankingOptimizer\RelevanceWeightAndMetricWeightGroundTruthTest::testMetricWeightConvergesTowardWhicheverMetricTheGroundTruthFavors()}
     * applies to its own measured pair -- the loser (the one needing a business-signal boost to overturn its
     * natural text-relevance disadvantage) is always rated HEART, the winner always X, in both scenarios;
     * only which metric the loser favors flips between them, exactly mirroring the measured pair's own
     * scenario-1-vs-scenario-2 structure.
     *
     * @param array<array{0: int, 1: int}> $fillerPairs
     * @param array<string, float> $zeroedScores
     * @param string $favoredMetric
     * @param string $otherMetric
     * @param string $searchTerm
     *
     * @return array<int> The filler synthetic query ids, for the caller to delete once this scenario's run
     *   is done.
     */
    protected function applyFillerPairsForScenario(
        array $fillerPairs,
        array $zeroedScores,
        string $favoredMetric,
        string $otherMetric,
        string $searchTerm,
    ): array {
        $queryIds = [];

        foreach ($fillerPairs as $index => [$idFillerLoser, $idFillerWinner]) {
            $this->overrideScores($idFillerLoser, array_merge($zeroedScores, [$favoredMetric => 1.0, $otherMetric => 0.0]));
            $this->overrideScores($idFillerWinner, array_merge($zeroedScores, [$favoredMetric => 0.0, $otherMetric => 1.0]));

            $idQuery = $this->insertSyntheticQuery($searchTerm, 'filler' . $index);
            $this->insertSyntheticRating($idQuery, $idFillerLoser, SearchRankingOptimizerConfig::RATING_TYPE_HEART);
            $this->insertSyntheticRating($idQuery, $idFillerWinner, SearchRankingOptimizerConfig::RATING_TYPE_X);

            $queryIds[] = $idQuery;
        }

        return $queryIds;
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
     * @param float|null $fixedRelevanceWeight Null (the default): relevanceWeight is free, same as a real
     *   "Run now" click with every parameter left unchecked. Non-null: pins relevanceWeight at exactly this
     *   value for the run -- lets a ground truth remove relevanceWeight as a confound OUTRIGHT (see
     *   {@see discoverMarginalTextRelevancePair()}) rather than merely reasoning about the worst case it
     *   could reach.
     * @param string $terminationMode `SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_FIXED_BUDGET`
     *   (the default): a single run, same as every ground truth scenario before this parameter existed.
     *   `OPTIMIZATION_TERMINATION_MODE_RESTART_ON_PLATEAU`: wraps the run's algorithm in `blackbox-optimizer`'s
     *   `RestartingOptimizerDecorator` -- see {@see \SprykerCommunityTest\GroundTruth\SearchRankingOptimizer\RelevanceWeightAndMetricWeightGroundTruthTest::testRestartOnPlateauRaisesSingleRunHitRateForMetricWeight()},
     *   which measures exactly what this buys a single "Run now" click against the same scenario
     *   {@see \SprykerCommunityTest\GroundTruth\SearchRankingOptimizer\RelevanceWeightAndMetricWeightGroundTruthTest::METRIC_WEIGHT_REPEAT_COUNT}'s
     *   own docblock measured the ~10% baseline hit rate on.
     */
    protected function runRealOptimization(
        string $algorithm = SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
        ?float $fixedRelevanceWeight = null,
        string $terminationMode = SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_FIXED_BUDGET,
    ): SearchRankingOptimizerRunTransfer {
        $queuedRunTransfer = $this->getFacade()->queueOptimizationRun(
            static::STORE_NAME,
            static::LOCALE_NAME,
            $algorithm,
            $terminationMode,
            0.0,
            $fixedRelevanceWeight,
            null,
            null,
            null,
            null,
            [],
        );
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
     * @param float|null $fixedRelevanceWeight Passed straight through to {@see runRealOptimization()}.
     */
    protected function runRealOptimizationRepeatedMedian(
        callable $extractor,
        int $times = 3,
        string $algorithm = SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
        ?float $fixedRelevanceWeight = null,
    ): float {
        $values = [];

        for ($i = 0; $i < $times; $i++) {
            $values[] = $extractor($this->runRealOptimization($algorithm, $fixedRelevanceWeight));
        }

        sort($values);

        return $values[intdiv(count($values), 2)];
    }

    /**
     * Runs a real optimization $times times and returns the BEST (not median) of whatever $extractor pulls
     * out of each winning candidate -- the metric-weight ground truth's own variant of {@see runRealOptimizationRepeatedMedian()},
     * for a landscape confirmed to be genuinely MULTI-MODAL rather than noisy-around-one-true-value: CMA-ES
     * here converges fast (`generationsUsed` pinned at 29 in every observed run, unrelated to the
     * `maxGenerations` cap or to how many rated pairs the objective has -- see
     * {@see \SprykerCommunityTest\GroundTruth\SearchRankingOptimizer\RelevanceWeightAndMetricWeightGroundTruthTest::testMetricWeightConvergesTowardWhicheverMetricTheGroundTruthFavors()}'s
     * own docblock for the full trace), but WHICH of several real local optima of very different quality it
     * lands in is essentially decided by its own random initialization. Confirmed empirically (before
     * restart-on-plateau existed): 3 independent runs of the exact same scenario landed leads of -0.17,
     * +0.02, and +0.30 -- the correct answer is genuinely reachable (+0.30 clears the required threshold
     * comfortably), a MEDIAN of those 3 just as easily reports the -0.17 or +0.02 run as "typical," actively
     * hiding that the optimizer CAN find it. Best-of-N is not a weaker bar chosen to dodge that failure --
     * "can the optimizer find this ground truth given a few tries" is the more honest question for a real
     * user who would re-run an unsatisfying result via the same "Run now" button anyway; median-of-N was
     * only ever the right statistic under the assumption (since falsified for metric weights) that failures
     * are Gaussian noise around one true value rather than a genuinely different basin.
     *
     * The deeper fix this docblock used to call for -- a restart strategy that would make a SINGLE
     * `runRealOptimization()` call already robust to this, making repeat-and-take-best unnecessary -- now
     * exists (`andrebarthelmeshellmuth/blackbox-optimizer`'s `RestartingOptimizerDecorator`, via
     * $terminationMode below). This method's own repeat-and-take-best is kept anyway, not made
     * redundant by it: restart-on-plateau is measured (see `testRestartOnPlateauRaisesSingleRunHitRateForMetricWeight()`)
     * to raise a SINGLE run's hit rate dramatically, not guarantee it every time, and $times gives this
     * caller's own margin on top of that -- see {@see \SprykerCommunityTest\GroundTruth\SearchRankingOptimizer\RelevanceWeightAndMetricWeightGroundTruthTest::METRIC_WEIGHT_REPEAT_COUNT}'s
     * own docblock for the combined math.
     *
     * @param callable(\Generated\Shared\Transfer\SearchRankingOptimizerRunTransfer): float $extractor
     * @param bool $preferMax True: return the largest value found (e.g. "metricA should outweigh metricB").
     *   False: return the smallest (e.g. "metricB should outweigh metricA", i.e. the most negative lead).
     * @param int $times
     * @param string $algorithm
     * @param float|null $fixedRelevanceWeight Passed straight through to {@see runRealOptimization()}.
     * @param string $terminationMode Passed straight through to {@see runRealOptimization()}.
     */
    protected function runRealOptimizationRepeatedBest(
        callable $extractor,
        bool $preferMax,
        int $times = 5,
        string $algorithm = SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_CMA_ES,
        ?float $fixedRelevanceWeight = null,
        string $terminationMode = SearchRankingOptimizerConfig::OPTIMIZATION_TERMINATION_MODE_FIXED_BUDGET,
    ): float {
        $values = [];

        for ($i = 0; $i < $times; $i++) {
            $values[] = $extractor($this->runRealOptimization($algorithm, $fixedRelevanceWeight, $terminationMode));
        }

        return $preferMax ? max($values) : min($values);
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
