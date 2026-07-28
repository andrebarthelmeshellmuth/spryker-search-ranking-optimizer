# Spryker Search Ranking Optimizer

Deciding *what* the ranking weights and parameters should be, on top of
[spryker-community/search-ranking](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking),
which provides the mechanism to *use* them (business-signal metrics, formulas, `function_score` ranking,
manual weight/parameter editing).

This package is a real, one-directional dependent of `search-ranking`: it reads and writes that package's
tuning parameters through that package's own facade. `search-ranking` has no knowledge of this package and
installs and runs completely standalone without it (see [Relationship to search-ranking](#relationship-to-search-ranking)).

## Status

**Calibration and the SRP relevance-rating widget are both built, tested, and shipping.** The rest of the
tuning layer (weight-slider preview, a propose/review/apply workflow, offline `rank_eval` evaluation, a
monthly auto-tune job, automated weight search) is designed and on the [Roadmap](#roadmap) but not built
yet.

Verified live end-to-end in a real browser (not just the automated test suite — see
[Testing and CI](#testing-and-ci) for why that alone wouldn't have been enough): a customer clicks a rating
button on the storefront, the judgment round-trips through the Yves→Zed gateway with a server-side
permission re-check, and lands correctly in the database.

![The SRP relevance-rating widget: heart/check/X buttons below each product tile, colorized once rated — heart red, check green, X red](docs/screenshots/srp-rating-widget.png)

## What it does today

### Calibration — empirically sampling `relevanceSaturationPoint` (k)

`search-ranking` blends text relevance with business signals using a saturating transform of the raw
Elasticsearch/OpenSearch `_score`: `_score / (_score + k)`, where `k` (`relevanceSaturationPoint`) is the
score at which text relevance contributes exactly 0.5. That constant has no universal correct value — it
depends entirely on a shop's own field boosts and typical query shapes, and `search-ranking`'s README is
explicit that it should be **sampled from real `_score` values, not guessed**. Calibration is the tool that
does that sampling.

The workflow, all from the **Search Ranking Optimizer → Calibration** Zed page:

1. **Upload a run.** Provide a list of representative search terms (CSV, one per line), the store and
   locale to run them against (Zed has no implicit current store, so both are picked explicitly), and the
   number of top results per term to sample (X). The run is persisted in status `uploaded`.
2. **Calculate.** The `search-ranking-optimizer:calibrate` console command (run on a cron, or by hand)
   picks up the newest `uploaded` run, marks any older uploaded runs `skipped`, fires the **live catalog
   search-string query** for each term against the real search index, pools the top-X raw text-relevance
   `_score` values across all terms, and computes a suggested `k` from that pool. The run moves to
   `calculated` (or `failed`, with a stored error message).
3. **Apply.** Back on the Calibration page, review the suggested `k` against the current live value and
   click **Apply** to write it into `search-ranking`'s `relevanceSaturationPoint` setting — through
   `search-ranking`'s own facade, which republishes the ranking configuration exactly as a manual edit on
   its Settings page would. Applying is a deliberate, separate step: calibration *suggests*, a human
   *decides*.

The console prints, e.g.:

```
Calibration #7 done: sampled 214 score(s) across 12 search term(s), computed k = 6.4180.
```

Firing the query from Zed reuses `search-ranking`'s solved raw-Elastica bypass pattern (the standard
`Client\Search` stack assumes a Yves request context that doesn't exist in a console/Zed process), shipped
here as the `Client\SearchRankingOptimizer\Search` component.

### SRP relevance rating — capturing real (query, product) judgments

Calibration answers "what should `relevanceSaturationPoint` be" from a *sample* of search terms. Longer
term, tuning any part of the ranking formula needs a real, organically-grown judgment set — actual people
saying "this product was/wasn't a good result for this query." The rating widget is how that judgment set
gets built, one click at a time, directly on the storefront search results page (SRP).

- **Heart / check / X buttons** render below every product tile on the SRP, for any customer holding the
  **Relevance Rater** permission (`RateSearchRelevancePermissionPlugin`) — heart = highly relevant, check =
  acceptably relevant, X = not relevant. Default grey, colorized on click (heart/X share a red-family
  accent, check is green); only one button is ever pressed per (customer, product) pair on a given SRP.
  Clicking an already-pressed button re-submits the same judgment (idempotent) rather than clearing it —
  there is no separate "unrate" affordance by design.
- **One row per (query, customer, product).** The canonical search term (trimmed, lowercased,
  whitespace-collapsed — deliberately *not* tokenized, so two genuinely different queries never get merged)
  is stored once in `spy_search_ranking_query`; each rating is its own row in
  `spy_search_ranking_query_rating`, unique on `(query, customer_reference, product_abstract)`. The same
  customer re-rating the same pair upserts in place; **different customers rating the same (query, product)
  each keep their own row** — disagreement between raters is a signal to preserve, not average away at
  write time.
- **`importance_weight`** on `spy_search_ranking_query` (default `1`) lets a separate **Query Curator**
  permission (`SetSearchQueryImportancePermissionPlugin`) mark some queries as mattering more than others
  once they've accumulated ratings — a deliberately separate skill/permission from rating relevance itself.
- **Server-side authorization, not just a hidden button.** The Yves widget only *renders* for a permitted
  customer, but the write itself goes through a Zed `GatewayController` that independently re-checks the
  permission via the customer's active `CompanyUser` (never trusts the Yves-side check alone) before
  persisting anything.

This is the data-capture half of what [GAP-2 evaluation and beyond](#roadmap) will eventually score against
— nothing consumes these ratings yet (see Roadmap), but they accumulate from real traffic starting the
moment this is installed.

## Relationship to search-ranking

- **One-directional dependency.** This package depends on `search-ranking`; `search-ranking` never depends
  on this one. That keeps `search-ranking`'s scope to "use business signals to rank" and this package's to
  "decide what the parameters should be."
- **Declared under `suggest`, not `require` — deliberately.** `search-ranking` is currently a **private**
  repository, and GitHub Actions cannot `git clone` a private cross-repo VCS dependency, so declaring it as
  a hard `require` would break this package's own CI. It is listed under `suggest` instead, with a note that
  it is **required at runtime**. The coupling is real but loose: the Zed dependency bridges reference
  `search-ranking`'s facade only in docblock `@param`/`@var` type hints (Spryker's standard untyped-bridge
  convention), never as `use` imports, so nothing here fails to autoload when `search-ranking` is absent —
  only the actual apply path needs it at runtime. Promote it to `require` once `search-ranking` is public
  (or CI can authenticate against it).

## Requirements

- PHP >= 8.3
- Spryker (kernel/gui/catalog/store/locale/propel-orm/search-elasticsearch/permission/company-user — see
  `composer.json` for floors, verified by `composer check-floors`)
- A running Elasticsearch/OpenSearch catalog search (calibration fires real queries against it)
- **`spryker-community/search-ranking` installed and wired** — a real `require` (`^1.0`); the Apply step
  writes into its `relevanceSaturationPoint` setting via its facade
- **B2B company-user accounts** — the rating widget resolves "is this customer allowed to rate" via their
  active `CompanyUser`, the same permission-granting mechanism the rest of a B2B shop already uses. A B2C-only
  shop with no `CompanyUser` module has nothing to grant the Relevance Rater/Query Curator permissions to.

## Installation

If `search-ranking` is already installed in your project, steps 2 and 5 are already done — this package
shares its `SprykerCommunity` core namespace and its `spryker-community/*` translation glob.

### 1. Install the package

Not yet published on Packagist — install from a path repository:

```json
"repositories": [
    {
        "type": "path",
        "url": "packages/spryker-community/search-ranking-optimizer",
        "options": { "symlink": true }
    }
]
```

```bash
composer require spryker-community/search-ranking-optimizer:@dev
```

### 2. Register the core namespace

In `config/Shared/config_default.php`, ensure `SprykerCommunity` is in `KernelConstants::CORE_NAMESPACES`
(already present if `search-ranking` or `search-debug` is installed).

### 3. Register the console command

In `Pyz\Zed\Console\ConsoleDependencyProvider::getConsoleCommands()`:

```php
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerCalibrateConsole;

new SearchRankingOptimizerCalibrateConsole(),
```

### 3a. Register the permission plugins (required for the SRP rating widget)

In **both** `Pyz\Zed\Permission\PermissionDependencyProvider::getPermissionPlugins()` and
`Pyz\Client\Permission\PermissionDependencyProvider::getPermissionPlugins()`:

```php
use SprykerCommunity\Shared\SearchRankingOptimizer\Plugin\RateSearchRelevancePermissionPlugin;
use SprykerCommunity\Shared\SearchRankingOptimizer\Plugin\SetSearchQueryImportancePermissionPlugin;

new RateSearchRelevancePermissionPlugin(),
new SetSearchQueryImportancePermissionPlugin(),
```

Registering the plugin only makes the permission *grantable* — the widget stays invisible until a
customer's company role is actually given `RateSearchRelevancePermissionPlugin` (via your project's own
company-role/permission fixture data, e.g. `company_role_permission.csv` if you use the standard
`CompanyRoleDataImport`). A customer already logged in when the grant is added needs to log out and back
in for the permission to take effect in their session.

### 3b. Register the Yves widget plugins

In `Pyz\Yves\Router\RouterDependencyProvider::getRouteProviderPlugins()`:

```php
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Plugin\Router\SearchRankingOptimizerWidgetRouteProviderPlugin;

new SearchRankingOptimizerWidgetRouteProviderPlugin(),
```

In `Pyz\Yves\Twig\TwigDependencyProvider::getGlobalPlugins()` (or wherever your project registers Twig
plugins):

```php
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Plugin\Twig\SearchRankingOptimizerWidgetTwigPlugin;

new SearchRankingOptimizerWidgetTwigPlugin(),
```

Then render the widget below each product tile in your SRP template (this package does not override
`page-layout-catalog.twig` itself — that stays project-owned):

```twig
{% include molecule('search-ranking-optimizer-product-rating', 'SearchRankingOptimizerWidget') with {
    data: {
        canRate: canRateSearchRelevance(),
        searchTerm: data.searchString,
        idProductAbstract: product.id_product_abstract,
    }
} only %}
```

Compute `canRateSearchRelevance()` **once per page**, not once per product, and pass the same value into
every product's include. If your SRP template also renders `spryker-community/search-debug`'s overlay in a
`.search-debug-product-wrapper` (or any other wrapper that stretches to a fixed row height via CSS Grid/
flex `align-items: stretch`), make sure that wrapper's first child does not have a hard `height: 100%` —
combined with `flex-shrink: 0` that silently eats all the wrapper's height and pushes this widget outside
the wrapper's visible box instead of the wrapper growing to fit it. This project's own fix (a scoped
`height: auto` override, not touching either package) lives in
`src/Pyz/Yves/CatalogPage/Theme/default/templates/page-layout-catalog/page-layout-catalog.scss` if you want
a working reference.

**Yves build gotcha:** a template-paired `.scss` file is silently never bundled unless that same template
directory also has an `index.ts` (`import './your-template';`) — webpack's entry-point discovery keys off
`templates/*/index.ts`, SCSS discovery only piggybacks on that entry point already existing.

### 4. Register the Zed navigation entry

Zed navigation has no glob auto-discovery for `vendor/spryker-community/*` (standard Spryker behavior).
Copy the `<search-ranking-optimizer-gui>` block from this package's
[`src/SprykerCommunity/Zed/SearchRankingOptimizer/Communication/navigation.xml`](src/SprykerCommunity/Zed/SearchRankingOptimizer/Communication/navigation.xml)
into your project's `config/Zed/navigation.xml`, then rebuild the navigation cache (delete the generated
cache first, since `navigation:build-cache` re-serializes whatever is already cached):

```bash
rm -f src/Generated/Zed/Navigation/codeBucket/navigation*.cache
vendor/bin/console navigation:build-cache
```

### 5. Translations

**Zed GUI** (Calibration page): like its siblings, this package ships its Zed strings as
`spryker/translator` CSV catalogs under [`data/translation/Zed/`](data/translation/Zed/) (Zed's `trans`
filter does **not** use the Yves-facing Glossary module). If your project already extended
`Pyz\Zed\Translator\TranslatorConfig::getCoreTranslationFilePathPatterns()` with the
`spryker-community/*` glob for `search-ranking`, this package is auto-discovered by the same glob — no
extra step. Otherwise add it once:

```php
$coreTranslationFilePathPatterns[] = APPLICATION_VENDOR_DIR . '/spryker-community/*/data/translation/Zed/[a-z][a-z]_[A-Z][A-Z].csv';
```

**Yves widget** (the three button titles): the opposite mechanism — a plain
[`data/glossary.csv`](data/glossary.csv), imported the normal Spryker way (this is the same
Redis-backed Glossary module every Yves-facing string in a Spryker shop already uses):

```bash
vendor/bin/console data:import glossary
```

### 6. Build (transfers, Propel tables, caches)

This package ships a Propel schema for **four** tables: `spy_search_ranking_calibration` +
`spy_search_ranking_calibration_search_term` (Calibration), and `spy_search_ranking_query` +
`spy_search_ranking_query_rating` (the SRP rating widget). Generate transfers and install the schema:

```bash
vendor/bin/console transfer:generate
vendor/bin/console propel:install       # creates all four tables + builds ORM classes
vendor/bin/console router:cache:warm-up:backoffice
```

If you wired the Yves widget (step 3b), also warm up the **BackendGateway** router — its cache is separate
from the Backoffice one above and is not covered by it, so a fresh install of just this package's Gateway
controller will 404 with "No route found" until this runs too:

```bash
vendor/bin/console router:cache:warm-up:backend-gateway
```

### 7. Schedule the calibration cron

E.g. in `Pyz\Zed\SymfonyScheduler\SymfonySchedulerConfig::getCronJobs()`:

```php
'search-ranking-optimizer-calibrate' => [
    'command' => '$PHP_BIN vendor/bin/console search-ranking-optimizer:calibrate',
    'schedule' => '*/5 * * * *',
],
```

The command is a safe no-op when there is no `uploaded` run, so it is fine to leave scheduled. (You can
also just run it by hand after each upload.)

## Modules

- **`SearchRankingOptimizer`** (Client/Zed/Shared) — the calibration business logic, persistence, console
  command, Zed GUI (Calibration + Apply controllers), the raw-Elastica search component, the rated-query
  data model, and the Zed Gateway endpoint that persists a rating.
- **`SearchRankingOptimizerWidget`** (Yves) — the SRP heart/check/X rating widget: controller, router/twig
  plugins, and the TypeScript/SCSS component itself.

## Roadmap

Calibration and judgment capture (rating collection) are the first two pieces of a larger tuning layer.
Designed, not yet built:

- **A Zed queries page** — list `spy_search_ranking_query` rows sorted by most-recently-rated first, with
  `importance_weight` inline-editable by the Query Curator permission. The data model and permission already
  exist (see "SRP relevance rating" above); only the listing page
  itself is still open.
- **`_rank_eval` scoring** — turn the ratings this widget already collects into a numeric objective score
  (nDCG) via OpenSearch/Elasticsearch's `_rank_eval` API, so a tuning change can be measured against a real
  objective instead of judged by eye. Heart/check/X → numeric gain mapping stays configurable, not
  hardcoded.
- **SRP weight-slider live preview** — an admin-only panel on the storefront results page: one slider per
  metric plus the relevance/business blend weight, live client-side re-ranking of a buffered result set,
  and a "fetch with these settings" button for a real, verified re-rank.
- **Weight checkpoint/rollback** — every applied weight change (manual, auto-tune, or eventually
  algorithmic) writes a full snapshot, listed and restorable from a simple Zed page.
- **Monthly auto-tune job** — per metric, check whether its live formula still fits the data; on a drop
  below a configurable threshold, propose (or, if enabled, apply) a refit and notify the configured admins.
- **Automated weight search** — once `_rank_eval` scoring exists, search the blend weight plus per-metric
  weights against the judgment set algorithmically (e.g. Bayesian optimization) rather than only via human
  proposals.

## Testing and CI

### Automated checks

`.github/workflows/ci.yml` runs on every push and pull request, the same set of checks as its siblings:

| check | what it protects |
|---|---|
| `composer validate` | the manifest stays well-formed |
| `phpcs` (PHP 8.3, 8.4) | coding standard, via this package's own `phpcs.xml` |
| `composer check-floors` (PHP 8.3, 8.4) | the declared dependency floors are real |
| `phpmd` (`phpmd.xml` + `phpmd-public-methods.xml`) | complexity / method- and class-length limits, run as two separate invocations (PHPMD merges every ruleset's `exclude-pattern` into one global list per run, and only the public-method-count rule should skip Facades/Factories) |

`check-floors` resolves every declared constraint to its **oldest** allowed version
(`composer update --prefer-lowest --prefer-stable --no-dev`) and then asserts every vendor symbol used in
`src/` exists in that tree — the standard community-package guard against a floor that is too low or a
dependency that is undeclared entirely. Run it locally the same way:

```bash
composer check-floors
```

### Test suite

**58 tests, 145 assertions** across two Codeception suites (`Zed/SearchRankingOptimizer`,
`Client/SearchRankingOptimizer`). From a shop that has the package installed:

```bash
vendor/bin/codecept build -c packages/spryker-community/search-ranking-optimizer/tests/SprykerCommunityTest/Zed/SearchRankingOptimizer
vendor/bin/codecept run   -c packages/spryker-community/search-ranking-optimizer/tests/SprykerCommunityTest/Zed/SearchRankingOptimizer
```

The CSV search-term parser, the score calibrator (skip-older-uploads, failing-term-treated-as-zero,
fail-when-nothing-scored, the vanished-row race), the statistics calculator, the persistence mapper, the
search-term canonicalizer, `ProductRelevanceJudgmentWriter` (canonicalization-before-lookup, creating a
query on first rating, rejecting an unknown rating type before touching persistence), and
`RelevanceJudgmentAuthorizer` (never trusts an identifier from the request itself, always re-resolves via
the CompanyUser facade; grants access if *any* of a customer's active company users holds the permission)
are covered as pure unit tests — no database needed.

**Important limitation of this suite, worth knowing before trusting a green run alone:** none of it renders
real Twig or compiled JS/CSS — it is 100% PHP. The SRP widget's actual on-page behavior (does the button
render at the right size, does the click round-trip actually reach the Gateway route, does the permission
fixture grant what the code expects) can only be confirmed with a real browser against a real running shop.
This is not hypothetical: exactly that class of bug (a wrapper-CSS interaction hiding the widget, a
class-naming mismatch between the Twig and the SCSS/JS, a missing permission fixture row) shipped
undetected in this package's own history despite every automated check passing throughout, and was only
caught by a manual click-through. A real WebDriver-based Presentation/Cest suite would close this gap; not
built yet.

`SearchRankingOptimizerEntityManagerTest` and `SearchRankingOptimizerRepositoryTest` are **real database**
integration tests, not mocked: every method here is a thin Propel read-modify-write, so the one thing
actually worth protecting is that a value round-trips correctly (right FK linkage, right column mapping,
a safe no-op instead of a crash on an id that no longer exists) — a mocked query builder could confirm the
right methods were called but never that. One case (`findLatestCalculatedCalibration` returning `null`
when nothing is calculated yet) is exempted from this shop's own suite: this demoshop always has at least
one real calculated calibration already, so the "nothing calculated yet" branch can't be reached without
deleting real data — covered by inspection instead (a two-line early-return, same shape as the four
sibling not-found guards that *are* exercised).

The `Client/SearchRankingOptimizer` suite lives at `tests/SprykerCommunityTest/Client/SearchRankingOptimizer`.
`CalibrationSearcherTest` is a real integration test, not a unit test: it builds the exact query
`SearchRankingOptimizerFactory::createCalibrationSearcher()` builds in production and fires it at this
shop's own real product-page index (a throwaway fixture index would prove nothing here — this class exists
specifically to sample real relevance scores from the real catalog), asserting a known search term returns
real positive scores and an unmatched term returns none. `RawRelevanceScoreExtractorTest` covers the
explanation-parsing logic itself as a pure unit test against all four known `_explanation` shapes
(function-score-wrapped, unwrapped, nested, the zero-value guard) — `CalibrationSearcher` never wraps its
query in `function_score` (unlike search-ranking's live serving path), so the unwrapped-fallback shape
those unit tests assume is the same shape confirmed live against this shop's real OpenSearch 1.3.4.
`NeverInvokedStoreClient` is the one class with no test: a `LogicException`-throwing stub that structurally
satisfies an interface but is documented, by construction, to never actually be called — the same
exemption this project's own audit convention already grants exception/boilerplate classes.

Coverage (Codeception + pcov): 100% of methods/lines on every business-logic class except the two
documented exemptions above (`NeverInvokedStoreClient`, and the one unreachable-in-this-shop branch in
`SearchRankingOptimizerRepository`). `GatewayController::submitProductRelevanceJudgmentAction()` itself is
the one further exemption, same class as those two: it is a thin pass-through to
`RelevanceJudgmentAuthorizer` and `SearchRankingOptimizerFacade` (both independently unit-tested above) and
needs a real HTTP request/response cycle to exercise meaningfully — covered by the live browser
verification in [Status](#status) instead of a unit test.

Static analysis (`phpstan`, level 8, config in [`phpstan.neon`](phpstan.neon), zero errors across all 75
files) is run from a host shop rather than in CI, same reasoning as the test suite — it needs the
generated `Generated\Shared\Transfer\*` classes, which only exist once a project has run
`transfer:generate`. **Invoke it via the real `packages/` path, not the `vendor/` symlink** — running it
against `vendor/spryker-community/search-ranking-optimizer/...` produces spurious "return statement is
missing" errors on every Propel `Query::create()`-returning factory method (a path-resolution artifact of
analyzing a symlinked package, not a real defect); the identical source analyzed via its real path is
clean:

```bash
vendor/bin/phpstan clear-result-cache -c packages/spryker-community/search-ranking-optimizer/phpstan.neon
vendor/bin/phpstan analyse -c packages/spryker-community/search-ranking-optimizer/phpstan.neon packages/spryker-community/search-ranking-optimizer/src
```

## License

MIT — see [LICENSE](LICENSE).
