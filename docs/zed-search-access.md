# Calling Client\Catalog / Client\Search from Zed

Why those clients fail outside an HTTP context, and the two supported ways around it.

## Calling `Client\Catalog`/`Client\Search` from Zed or console (optional)

This package's own `SaturationPointCalibrationSearcher`/`RankEvalRunner` don't call `Client\Catalog`/
`Client\Search` at all — they fire a raw `Elastica` query directly, deliberately bypassing this problem
(see [Limitations](../README.md#limitations)). This section is for a different, broader need: if *your own* project
code (a console command, a Zed controller, a cron job — anything with no live Yves/Glue HTTP session)
ever wants to call the real `Client\Catalog::catalogSearch()`/`Client\Search::search()`, two of Spryker's
own core plugins stand in the way, unconditionally, in every Spryker project we've checked.

**The problem.** `StoreQueryExpanderPlugin`/`LocalizedQueryExpanderPlugin`
(`spryker/search-elasticsearch`) unconditionally call `Client\Store::getCurrentStore()`/
`Client\Locale::getCurrentLocale()` — both require live HTTP/customer session state that simply doesn't
exist in Zed or console context, so the call throws. This is **not specific to this package or to
search tuning** — it breaks the moment *any* Zed/console code calls the real catalog search facade, full
stop, in any project using the standard Elasticsearch query-expander stack.

**Two copy-paste overrides fix it**, reading an explicit store/locale from `$requestParameters` (the
override channel `QueryExpanderPluginInterface::expandQuery()` already defines) and falling back to the
core behavior — i.e. today's storefront/Glue behavior — when that key isn't passed:

- [`docs/examples/StoreQueryExpanderPlugin.php`](examples/StoreQueryExpanderPlugin.php)
- [`docs/examples/LocalizedQueryExpanderPlugin.php`](examples/LocalizedQueryExpanderPlugin.php)

Copy both into your project at `src/Pyz/Client/SearchElasticsearch/Plugin/QueryExpander/`, then register
them in place of the core plugins everywhere your own `CatalogDependencyProvider` (and any other
search-domain `DependencyProvider` — see the audit note below) currently does `new
StoreQueryExpanderPlugin()`/`new LocalizedQueryExpanderPlugin()`. The store override also stamps the
resolved store name onto the query's own `SearchContextTransfer`, not just the Elastica filter clause —
that part is what actually matters: `Client\Catalog::catalogSearch()` runs query expanders *before*
`Client\Search::search()` resolves the Elasticsearch index name via `IndexNameResolver`, and that
resolver falls back to the identical `Client\Store::getCurrentStore()` call whenever the query's context
has no store name set. Fixing only the Elastica filter, without that stamp, leaves the index-resolution
call site crashing on its own — see the plugin's own docblock for the full trace.

**If you have `spryker-eco/algolia` installed, also remove it from any `*QueryPluginVariants()` method**
in your `CatalogDependencyProvider` that registers it (`createCatalogSearchQueryPluginVariants()` and
friends). `AlgoliaSearchQueryPlugin`'s own constructor unconditionally makes the same three
session-dependent calls — and because Spryker's `CatalogFactory` builds the *entire* variants array
eagerly regardless of which variant ultimately gets selected, every registered Algolia variant runs its
constructor on every single search, Algolia or not, active or not. **Most Spryker shops don't have
Algolia installed at all — if that's you, this step is a no-op, skip it.** It only matters if your
project genuinely registers an Algolia variant, in which case it's worth removing even independent of
this section: we found that in a project where Algolia is registered but switched off
(`AlgoliaConfig::getIsActive()` false, e.g. left over from an evaluation that didn't go ahead), every real
customer search was still silently constructing and discarding an `AlgoliaSearchQueryPlugin` instance —
three wasted client calls per request, forever, invisible unless you profile for it.

**One more thing worth checking before you assume you're done**: `StoreQueryExpanderPlugin`/
`LocalizedQueryExpanderPlugin` are commonly registered in *more than one* `DependencyProvider` — Catalog
is rarely the only search domain a project has. Auditing our own demo shop (not this package, the shop
we develop it against) turned up **seven** other `DependencyProvider`s registering the same two
unmodified core plugins for their own search domains (CMS page search, product-set search, merchant
search, configurable-bundle search, sales-return search, and others) — none of which this package touches
or needs, but every one of them has the identical Zed/console crash waiting the moment anyone calls into
them from a session-less context. If you adopt these overrides, grep your own project for
`new StoreQueryExpanderPlugin()`/`new LocalizedQueryExpanderPlugin()` (and any project-specific
query-expander plugins your own team wrote that similarly assume a live session) and swap all of them —
not just Catalog's — or you'll fix the domain you tested and still get paged by the next one.

**Once you've applied the overrides above, you can swap this package's own bypass for the real facade —
no package change needed.** `SaturationPointCalibrationSearcher`/`RankEvalRunner`/`SpecificitySearcher`
are each built by an ordinary, interface-typed `SearchRankingOptimizerFactory` method
(`createCalibrationSearcher()`/`createRankEvalRunner()`/`createSpecificitySearcher()`) — standard Spryker
project-override territory. Extend the factory in your own project
(`Pyz\Client\SearchRankingOptimizer\SearchRankingOptimizerFactory extends
SprykerCommunity\Client\SearchRankingOptimizer\SearchRankingOptimizerFactory`), override whichever
`create*()` method you want, and return your own implementation of the same interface built on the real
`Client\Catalog`/`Client\Search` facade instead — the Locator picks it up automatically, nothing else to
register. This is deliberately **not** something this package auto-detects or switches on internally:
there's no reliable way for the package to tell whether a project has actually applied the overrides
correctly (a project-declared flag would be no more trustworthy than the override method already is, and
reflection on plugin class identity is brittle against future core changes), so the decision is left where
it belongs — with the project that knows what it's actually wired up. Worth doing only if you've confirmed
the bundled bypass's known gap (no customer-group visibility, no price-list scoping, no project-registered
expanders — see [Limitations](../README.md#limitations)) actually skews your own calibration/rank_eval results; for
most shops the bypass's statistical shape is close enough that this isn't worth the parallel implementation
to maintain.
