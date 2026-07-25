# Spryker Search Ranking Optimizer

Deciding *what* the ranking weights and parameters should be, on top of
[spryker-community/search-ranking](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking),
which provides the mechanism to *use* them (business-signal metrics, formulas, `function_score` ranking,
manual weight/parameter editing).

This package is a real, one-directional dependent of `search-ranking`: it reads and writes that package's
tuning parameters through that package's own facade. `search-ranking` has no knowledge of this package and
installs and runs completely standalone without it (see [Relationship to search-ranking](#relationship-to-search-ranking)).

## Status

**Calibration is built, tested, and shipping.** The rest of the tuning layer (weight-slider preview, a
propose/review/apply workflow, offline `rank_eval` evaluation, a monthly auto-tune job, automated weight
search) is designed and on the [Roadmap](#roadmap) but not built yet.

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
- Spryker (kernel/gui/catalog/store/locale/propel-orm/search-elasticsearch — see `composer.json` for
  floors, verified by `composer check-floors`)
- A running Elasticsearch/OpenSearch catalog search (calibration fires real queries against it)
- **`spryker-community/search-ranking` installed and wired** — this is the runtime `suggest` dependency;
  the Apply step writes into its `relevanceSaturationPoint` setting

## Installation

If `search-ranking` is already installed in your project, steps 2 and 5 are already done — this package
shares its `SprykerCommunity` core namespace and its `spryker-community/*` translation glob.

### 1. Install the package

```bash
composer require spryker-community/search-ranking-optimizer
```

(During local development it is a `path` repository symlinked into the shop, exactly like its siblings.)

### 2. Register the core namespace

In `config/Shared/config_default.php`, ensure `SprykerCommunity` is in `KernelConstants::CORE_NAMESPACES`
(already present if `search-ranking` or `search-debug` is installed).

### 3. Register the console command

In `Pyz\Zed\Console\ConsoleDependencyProvider::getConsoleCommands()`:

```php
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerCalibrateConsole;

new SearchRankingOptimizerCalibrateConsole(),
```

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

### 5. Translations for the Zed GUI

Like its siblings, this package ships its Zed strings as `spryker/translator` CSV catalogs under
[`data/translation/Zed/`](data/translation/Zed/) (Zed's `trans` filter does **not** use the Yves-facing
Glossary module). If your project already extended
`Pyz\Zed\Translator\TranslatorConfig::getCoreTranslationFilePathPatterns()` with the
`spryker-community/*` glob for `search-ranking`, this package is auto-discovered by the same glob — no
extra step. Otherwise add it once:

```php
$coreTranslationFilePathPatterns[] = APPLICATION_VENDOR_DIR . '/spryker-community/*/data/translation/Zed/[a-z][a-z]_[A-Z][A-Z].csv';
```

### 6. Build (transfers, Propel tables, caches)

This package ships a Propel schema for two tables (`spy_search_ranking_calibration`,
`spy_search_ranking_calibration_search_term`). Generate transfers and install the schema:

```bash
vendor/bin/console transfer:generate
vendor/bin/console propel:install       # creates the calibration tables + builds ORM classes
vendor/bin/console router:cache:warm-up:backoffice
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

- **`SearchRankingOptimizer`** (Client/Zed/Shared) — the calibration business logic, persistence,
  console command, Zed GUI (Calibration + Apply controllers), and the raw-Elastica search component.

## Roadmap

Calibration is the first piece of a larger tuning layer. Designed, not yet built:

- **SRP weight-slider live preview** — an admin-only panel on the storefront results page: one slider per
  metric plus the relevance/business blend weight, live client-side re-ranking of a buffered result set,
  and a "fetch with these settings" button for a real, verified re-rank.
- **Tier-2/tier-3 propose → review → apply workflow** — admins submit weight proposals against a search
  term; a reviewer checks/unchecks proposals and applies a learn-rate blend, saved as a named, restorable
  checkpoint.
- **Offline relevance evaluation** — judgment capture (rate products relevant/irrelevant for a query,
  directly on the live SRP) plus a `_rank_eval` (nDCG) scoring pass, so a tuning change can be measured
  against a real objective instead of judged by eye. This extends Calibration.
- **Monthly auto-tune job** — per metric, check whether its live formula still fits the data; on a drop
  below a configurable threshold, propose (or, if enabled, apply) a refit and notify the configured admins.
  `search-ranking` already carries the config fields (threshold, notify toggle) and the ACL role this job
  will read.
- **Automated weight search** — once evaluation exists, search the blend weight plus per-metric weights
  against the judgment set algorithmically (e.g. Bayesian optimization) rather than only via human
  proposals.

## Testing and CI

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

Codeception suites live under `tests/` — the CSV search-term parser, the score calibrator, the statistics
calculator, the raw-relevance-score extractor, and the persistence mapper. From a shop that has the
package installed:

```bash
vendor/bin/codecept build -c packages/spryker-community/search-ranking-optimizer/tests/SprykerCommunityTest/Zed/SearchRankingOptimizer
vendor/bin/codecept run   -c packages/spryker-community/search-ranking-optimizer/tests/SprykerCommunityTest/Zed/SearchRankingOptimizer
```

## License

MIT — see [LICENSE](LICENSE).
