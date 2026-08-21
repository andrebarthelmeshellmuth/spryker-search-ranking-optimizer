# Testing and CI

How this package is tested, which suites need a host shop, and what CI runs.

## Testing and CI

### Automated checks

`.github/workflows/ci.yml` runs on every push and pull request, the same set of checks as its siblings:

| check | what it protects |
|---|---|
| `composer validate` | the manifest stays well-formed |
| `phpcs` (PHP 8.3, 8.4) | coding standard, via this package's own `phpcs.xml` |
| `composer check-floors` (PHP 8.3, 8.4) | the declared dependency floors are real |
| `rector` dry-run (PHP 8.3, 8.4) | no unapplied Rector rule set drifts in |
| `phpmd` (`phpmd.xml` + `phpmd-public-methods.xml`) | complexity / method- and class-length limits, run as two separate invocations (PHPMD merges every ruleset's `exclude-pattern` into one global list per run, and only the public-method-count rule should skip Facades/Factories) |
| `phpstan` (PHP 8.3, 8.4) | static analysis, level 8, standalone CI variant — see "Static analysis" below |
| `portable tests` (PHP 8.3, 8.4) | this package's own `@group Portable` test subset actually passes — see "Test suite" below |

`check-floors` resolves every declared constraint to its **oldest** allowed version
(`composer update --prefer-lowest --prefer-stable --no-dev`) and then asserts every vendor symbol used in
`src/` exists in that tree — the standard community-package guard against a floor that is too low or a
dependency that is undeclared entirely. Run it locally the same way:

```bash
composer check-floors
```

### Test suite

Every test class carries a portability `@group`, so `codecept run -g <tag>` tells you what a given test
actually needs:

| tag | needs | where it runs |
|---|---|---|
| `Portable` | nothing beyond `Generated\Shared\Transfer\*` | standalone — CI runs exactly this, see below |
| `NeedsDatabase` | a real Propel connection | host shop only |
| `NeedsSearch` | a real Elasticsearch/OpenSearch | host shop only |
| `NeedsProject` | this package's own installation diagnostics, deliberately coupled — see their own docblocks | host shop only |

`Portable` tests run standalone in CI on every push, via `tests/codeception.portable.yml` +
`tests/_ci-standalone/` — no host shop, no live database, no search engine. The recipe: a direct
`TransferBusinessFactory` call generates `Generated\Shared\Transfer\*`, and a direct
`spryker/search-elasticsearch` `IndexMapGenerator` call generates `Generated\Shared\Search\PageIndexMap`
from that package's own default `page` mapping — both into `src/Generated/` (gitignored, exactly like a
real project already gitignores its own — regenerated every run, never committed). Run it yourself the
same way CI does:

```bash
composer install
php tests/_ci-standalone/generate-transfers.php
php tests/_ci-standalone/generate-index-map.php
vendor/bin/codecept run -c tests/codeception.portable.yml -g Portable
```

**209 tests, 757 assertions** across three Codeception suites (`Zed/SearchRankingOptimizer`,
`Client/SearchRankingOptimizer`, `Yves/SearchRankingOptimizerWidget`) — down from a prior count that included `CmaEsAlgorithm`/
`DifferentialEvolutionAlgorithm`/`SymmetricEigenDecomposition`'s own tests, which moved along with the code
they cover to [andrebarthelmeshellmuth/blackbox-optimizer](https://github.com/andrebarthelmeshellmuth/blackbox-optimizer)'s
own test suite. From a shop that has the package installed:

```bash
vendor/bin/codecept build -c packages/spryker-community/search-ranking-optimizer/tests/SprykerCommunityTest/Zed/SearchRankingOptimizer
vendor/bin/codecept run   -c packages/spryker-community/search-ranking-optimizer/tests/SprykerCommunityTest/Zed/SearchRankingOptimizer
```

The CSV search-term parser, the score calibrator (skip-older-uploads, failing-term-treated-as-zero,
fail-when-nothing-scored, the vanished-row race), the statistics calculator, the persistence mapper, the
search-term canonicalizer, `ProductRelevanceJudgmentWriter` (canonicalization-before-lookup, creating a
query on first rating, rejecting an unknown rating type before touching persistence), and
`CompanyUserPermissionAuthorizer` (never trusts an identifier from the request itself, always re-resolves via
the CompanyUser facade; grants access if *any* of a customer's active company users holds the permission),
`AutoTuneNotificationRecipientResolver` (no role yet vs. de-duplicating usernames across multiple ACL
groups), `AutoTuneRunner` (skipping a deleted metric or one with no digest yet, the at-or-above-
threshold check-only path, proposing vs. applying a refit, never refitting a non-deterministic formula even
with auto-update on, parameters-only staying within the current shape vs. falling back to program's-choice
for an unknown shape, and the notify batching — exactly one combined email covering every metric that both
crossed its threshold and has notify on), `FormulaDeterminismChecker` (detects a non-deterministic function
call by name, precisely enough not to false-positive on an unrelated function merely sharing a prefix),
`SimplexSoftmaxReparametrization` (round-trips weights through `toFreeZ`/`toSimplex`, the numerically-stable
softmax under an extreme input, the floor that keeps the inverse from taking `log(0)`), `ParameterVectorMapper`
(the trust-region bound around the run's starting `relevanceWeight` and each specificity knob, round-tripping a
configuration through `mapConfigurationToVector`/`mapVectorToConfiguration`, clamping `specificityBlendWeight`
to its own absolute bounds, a fixed metric's weight held exactly constant while
the optimizable metrics' own simplex is scaled to fill only the remaining budget), `OptimizationRunner`
(queues and processes a run, population/generation-count sizing, the objective function's sign flip since
the algorithms minimize but a higher rank-evaluation score is better, always propose-only, a
non-deterministic-formula metric excluded from the search end-to-end, the live specificity knobs seeding the
run's baseline candidate and every subsequent candidate staying within its own trust region), and
`OptimizationApplier` (null when the run doesn't exist or isn't done yet, writing the winning candidate and
specificity knobs through the facade, recording an optimizer-sourced checkpoint, marking the run applied),
`AlgorithmFactory` (the single place `SearchRankingOptimizerConfig::OPTIMIZATION_ALGORITHM_*` values map to
concrete `BlackboxOptimizer\Algorithm\*` classes, including the fallback to CMA-ES for an unrecognized name),
and `AutomatedWeightOptimizationRunForm`'s own `buildAlgorithmChoices()`/`buildAlgorithmHelp()` (invoked
directly via reflection, same approach `RankEvalRunnerTest` already uses for this package's own protected
methods, rather than standing up a full Symfony `FormFactory` for two pure string/array transformations) are
covered as pure unit tests — no database needed.

Real, non-mocked integration tests against this shop's own live Elasticsearch/OpenSearch index prove the
specificity wiring isn't just plumbed through but actually changes behavior:
`RankEvalRunner::applySpecificityWeighting()` shifts `relevanceWeight` for a real query term when
specificity weighting is force-enabled (it's off by default — see [above](../README.md#automated-weight-optimization--searching-relevanceweight-and-metric-weights-algorithmically)),
is a no-op when no query term carries any real corpus evidence, and is *also* a no-op — even with a fully
populated specificity configuration — when the feature flag itself is disabled, which documents this
shop's own real current behavior (specificity weighting is inert end-to-end here today). A synthetic
ground-truth exercise went further still — two throwaway rated queries against this shop's real catalog,
one built from a highly specific search term and one built from an unspecific/browsy one, each rated so
that only the "correct" per-query relevanceWeight would rank the intended product first. A real automated
optimization run (not a toy) found a positive `specificityWeightShiftMagnitude` and reached a perfect
combined score, and — the important part — disabling the specificity shift on the exact same winning
configuration reproduced the specific query's score exactly but dropped the unspecific query's score
substantially, confirming the shift (not just `relevanceWeight` alone) is what makes the difference. Both
throwaway queries, their ratings, and the run itself were deleted afterward; nothing from this exercise is
part of the shipped test suite (it depends on this demoshop's specific catalog content, not something
portable to another shop's data).

**Important limitation of this suite, worth knowing before trusting a green run alone:** none of it renders
real Twig or compiled JS/CSS — it is 100% PHP. The SRP widget's actual on-page behavior (does the button
render at the right size, does the click round-trip actually reach the Gateway route, does the permission
fixture grant what the code expects) can only be confirmed with a real browser against a real running shop.
This is not hypothetical: exactly that class of bug (a wrapper-CSS interaction hiding the widget, a
class-naming mismatch between the Twig and the SCSS/JS, a missing permission fixture row) shipped
undetected in this package's own history despite every automated check passing throughout, and was only
caught by a manual click-through. The Browser (Presentation) suite below closes this gap.

### Browser (Presentation) suite

> **This suite is a development tool for this package's own reference demoshop — it is not something
> to install or run against YOUR shop.** It logs in as `admin@spryker.com` (Zed) and
> `search-admin@test-company.example` (Yves, the one account this demoshop's fixtures grant
> `RateSearchRelevancePermissionPlugin` to), drives the real Zed GUI through a store/locale scope this
> demoshop seeds (`DE`/`de_DE`), and — for the tests that exercise a full calibrate/optimize cycle — runs
> the real `search-ranking-optimizer:calibrate`/`:optimize` console commands directly (this test process
> and `vendor/bin/console` share one working directory), the same commands this shop's own cron ticks
> would run. Point it at a different shop and most of it will simply fail on missing data, not on a real
> defect. It exists to catch UI regressions while developing this package, not as something adopters are
> expected to run.

Two suites, split by layer:

- `tests/SprykerCommunityTest/Zed/SearchRankingOptimizerGuiPresentation/` (15 tests) — all 7 Zed pages
  (Calibration, Assess Rated Queries, Ratings, Test Current Evaluation, Weight Checkpoints, Auto-tune
  metrics settings, Automated Weight Optimization — the labels this package's own `navigation.xml`
  ships, which the sidebar assertions match verbatim). Every
  test that mutates live `search-ranking` config (relevanceWeight, metric weights, relevanceSaturationPoint)
  is fully self-contained — it captures the real value first, mutates, verifies, and restores it again
  before finishing (via a checkpoint restore where checkpoints cover the field, or a direct Settings edit
  for `relevanceSaturationPoint`, which checkpoints deliberately exclude — see [Weight
  Checkpoints](../README.md#automated-weight-optimization--searching-relevanceweight-and-metric-weights-algorithmically)) —
  so test order never matters and the suite leaves the environment exactly as it found it.
- `tests/SprykerCommunityTest/Yves/SearchRankingOptimizerWidgetPresentation/` (9 tests) — the SRP
  heart/check/X rating widget: renders, colorizes, persists across reload (which only holds once the SRP
  template feeds `activeRatingType` back in — see [3b](../README.md#3b-register-the-yves-widget-plugins)), only one
  button active per product, un-rating
  removes the row rather than just deselecting it, coexists with search-debug's own overlay on the same
  tile, and the permission gate (two negative-test accounts).

```bash
vendor/bin/codecept build -c packages/spryker-community/search-ranking-optimizer/tests/SprykerCommunityTest/Zed/SearchRankingOptimizerGuiPresentation
vendor/bin/codecept run   -c packages/spryker-community/search-ranking-optimizer/tests/SprykerCommunityTest/Zed/SearchRankingOptimizerGuiPresentation
vendor/bin/codecept build -c packages/spryker-community/search-ranking-optimizer/tests/SprykerCommunityTest/Yves/SearchRankingOptimizerWidgetPresentation
vendor/bin/codecept run   -c packages/spryker-community/search-ranking-optimizer/tests/SprykerCommunityTest/Yves/SearchRankingOptimizerWidgetPresentation
```

Like the rest of the test suite, neither is part of CI — both need a real running shop plus the Selenium/
chromedriver service already provisioned in this demoshop's `docker-compose.yml`.

### Opt-in ground-truth suite (not part of the default test run)

A third suite, `tests/SprykerCommunityTest/GroundTruth/SearchRankingOptimizer` (its own `codeception.yml`,
deliberately outside the `Zed/SearchRankingOptimizer`/`Client/SearchRankingOptimizer` paths the commands
above and CI actually run), proves the automated optimizer genuinely discovers the right answer rather than
just producing *a* score. Each test constructs a real, live ground truth — a synthetic rated query with an
overwhelming `importanceWeight` so it dominates the aggregate without touching any real query's own weight,
and controlled `scores.*` overrides on 2 real products, backed up and restored in a `finally` block — runs
the REAL automated optimizer end-to-end (this package's own public Facade, the same "Run now" Zed button's
code path), and asserts the winning value moved in the expected direction. No product IDs, search terms, or
metric names are hardcoded anywhere; everything is discovered at runtime from whatever real rated queries
and active metrics the shop running it happens to have.

```bash
composer dump-autoload
vendor/bin/codecept build -c packages/spryker-community/search-ranking-optimizer/tests/SprykerCommunityTest/GroundTruth/SearchRankingOptimizer
vendor/bin/codecept run   -c packages/spryker-community/search-ranking-optimizer/tests/SprykerCommunityTest/GroundTruth/SearchRankingOptimizer
```

Why this isn't a CI gate: each test runs a REAL population × generations optimization (tens of seconds to
a few minutes), and — the more fundamental reason — a single rated pair per query gives `rank_eval`'s nDCG
an almost step-function landscape (flat everywhere except right at the exact parameter value where the 2
rated products' relative order flips), which a population-based search can occasionally fail to climb from
an unlucky random initialization even on an easy, unambiguous ground truth. Confirmed empirically: the same
construction passed on some runs and landed on the *wrong* extreme on others. The fix applied throughout
this suite is exactly what a real optimizer's own randomness calls for — run each scenario 3 times and
compare medians, not single runs — rather than trying to eliminate the randomness itself.

`SearchRankingOptimizerEntityManagerTest` and `SearchRankingOptimizerRepositoryTest` are **real database**
integration tests, not mocked: every method here is a thin Propel read-modify-write, so the one thing
actually worth protecting is that a value round-trips correctly (right FK linkage, right column mapping,
a safe no-op instead of a crash on an id that no longer exists) — a mocked query builder could confirm the
right methods were called but never that. This includes the per-metric auto-tune config's own upsert (a
second save updates the existing row rather than creating a duplicate) and its threshold-set filtering.
One case (`findLatestCalculatedCalibration` returning `null`
when nothing is calculated yet) is exempted from this shop's own suite: this demoshop always has at least
one real calculated saturation point calibration already, so the "nothing calculated yet" branch can't be reached without
deleting real data — covered by inspection instead (a two-line early-return, same shape as the four
sibling not-found guards that *are* exercised).

The `Client/SearchRankingOptimizer` suite lives at `tests/SprykerCommunityTest/Client/SearchRankingOptimizer`.
`SaturationPointCalibrationSearcherTest` is a real integration test, not a unit test: it builds the exact query
`SearchRankingOptimizerFactory::createCalibrationSearcher()` builds in production and fires it at this
shop's own real product-page index (a throwaway fixture index would prove nothing here — this class exists
specifically to sample real relevance scores from the real catalog), asserting a known search term returns
real positive scores and an unmatched term returns none. `RawRelevanceScoreExtractorTest` covers the
explanation-parsing logic itself as a pure unit test against all four known `_explanation` shapes
(function-score-wrapped, unwrapped, nested, the zero-value guard) — `SaturationPointCalibrationSearcher` never wraps its
query in `function_score` (unlike search-ranking's live serving path), so the unwrapped-fallback shape
those unit tests assume is the same shape this shop's real OpenSearch 1.3.4 actually returns.
`NeverInvokedStoreClient` is the one class with no test: a `LogicException`-throwing stub that structurally
satisfies an interface but is documented, by construction, to never actually be called — the same
exemption this project's own audit convention already grants exception/boilerplate classes.

Coverage (Codeception + pcov): 100% of methods/lines on every business-logic class except the two
documented exemptions above (`NeverInvokedStoreClient`, and the one unreachable-in-this-shop branch in
`SearchRankingOptimizerRepository`). `GatewayController::submitProductRelevanceJudgmentAction()` itself is
the one further exemption, same class as those two: it is a thin pass-through to
`CompanyUserPermissionAuthorizer` and `SearchRankingOptimizerFacade` (both independently unit-tested above) and
needs a real HTTP request/response cycle to exercise meaningfully — covered by the live browser
verification in [Status](../README.md#status) instead of a unit test.

### Static analysis

Static analysis (`phpstan`, level 8) runs in two variants:

- **`composer phpstan-ci`** (config [`phpstan.ci.neon`](../phpstan.ci.neon)) — what CI runs on every push,
  standalone. Same transfer/index-map generation recipe as the `Portable` test subset above, and treats two
  categories of class as out of scope rather than faking them: Propel's generated `Orm\Zed\*\Persistence\*`
  entity/query/map classes (need a real schema + database, via `propel:model:build`) and the aggregated
  `Generated\{Zed,Yves,Client,Service}\Ide\AutoCompletion` stub (an aggregate across every module in a real
  project's full dependency graph, via `console dev:ide-auto-completion:generate`).
- **`composer phpstan`** (config [`phpstan.neon`](../phpstan.neon), zero errors across all 151 files) — the
  full check, run from a host shop, same reasoning as the test suite: it needs the generated
  `Generated\Shared\Transfer\*` classes, which only exist once a project has run `transfer:generate`, so it
  stays the authoritative check for adopters even though CI can't run it. **Invoke it via the real
  `packages/` path, not the `vendor/` symlink** — running it against
  `vendor/spryker-community/search-ranking-optimizer/...` produces spurious "return statement is missing"
  errors on every Propel `Query::create()`-returning factory method (a path-resolution artifact of
  analyzing a symlinked package, not a real defect); the identical source analyzed via its real path is
  clean:

```bash
vendor/bin/phpstan clear-result-cache -c packages/spryker-community/search-ranking-optimizer/phpstan.neon
vendor/bin/phpstan analyse -c packages/spryker-community/search-ranking-optimizer/phpstan.neon packages/spryker-community/search-ranking-optimizer/src
```
