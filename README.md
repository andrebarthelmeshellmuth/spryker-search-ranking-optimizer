# Spryker Search Ranking Optimizer

Deciding *what* the ranking weights and parameters should be, on top of
[spryker-community/search-ranking](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking),
which provides the mechanism to *use* them (business-signal metrics, formulas, `function_score` ranking,
manual weight/parameter editing).

This package is a real, one-directional dependent of `search-ranking`: it reads and writes that package's
tuning parameters through that package's own facade. `search-ranking` has no knowledge of this package and
installs and runs completely standalone without it (see [Relationship to search-ranking](#relationship-to-search-ranking)).

*Part of the [Search Relevance](https://search-relevance.dev/) project — explore the interactive ranking-formula walkthrough there.*

## Contents

- [Terminology](#terminology)
- [Status](#status)
- [Before you start: this needs real relevance ratings](#before-you-start-this-needs-real-relevance-ratings)
- [What it does today](#what-it-does-today)
  - [Saturation Point Calibration — empirically sampling `relevanceSaturationPoint` (k)](#saturation-point-calibration--empirically-sampling-relevancesaturationpoint-k)
  - [SRP relevance rating — capturing real (query, product) judgments](#srp-relevance-rating--capturing-real-query-product-judgments)
  - [Rank evaluation — a real objective score, not averaged opinion](#rank-evaluation--a-real-objective-score-not-averaged-opinion)
  - [Weight checkpoints — a way back before changing anything by hand](#weight-checkpoints--a-way-back-before-changing-anything-by-hand)
  - [Auto-tune — a monthly fit-quality check per metric](#auto-tune--a-monthly-fit-quality-check-per-metric)
  - [Automated weight optimization — searching relevanceWeight and metric weights algorithmically](#automated-weight-optimization--searching-relevanceweight-and-metric-weights-algorithmically)
- [Relationship to search-ranking](#relationship-to-search-ranking)
- [Bootstrapping one store/locale, then fanning out everywhere](#bootstrapping-one-storelocale-then-fanning-out-everywhere)
- [Requirements](#requirements)
- [Installation](#installation)
  - [1. Install the package](#1-install-the-package)
  - [2. Register the core namespace](#2-register-the-core-namespace)
  - [3. Register the console command](#3-register-the-console-command)
  - [3a. Register the permission plugins (required for the SRP rating widget)](#3a-register-the-permission-plugins-required-for-the-srp-rating-widget)
  - [3b. Register the Yves widget plugins](#3b-register-the-yves-widget-plugins)
  - [4. Register the Zed navigation entry](#4-register-the-zed-navigation-entry)
  - [5. Translations](#5-translations)
  - [6. Build (transfers, Propel tables, caches)](#6-build-transfers-propel-tables-caches)
  - [7. Schedule the calibration, auto-tune, and optimize crons](#7-schedule-the-calibration-auto-tune-and-optimize-crons)
  - [8. Verify the installation](#8-verify-the-installation)
- [Calling `Client\Catalog`/`Client\Search` from Zed or console (optional)](#calling-clientcatalogclientsearch-from-zed-or-console-optional)
- [Modules](#modules)
- [Limitations](#limitations)
- [Testing and CI](#testing-and-ci)
  - [Automated checks](#automated-checks)
  - [Test suite](#test-suite)
  - [Opt-in ground-truth suite (not part of the default test run)](#opt-in-ground-truth-suite-not-part-of-the-default-test-run)
- [License](#license)
- [Acknowledgements](#acknowledgements)

## Terminology

A quick reference for terms this README reuses across many sections — a lookup index, not a replacement
for the fuller explanation given where each is first introduced in context. `search-ranking`'s own README
has its own [Terminology](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking#terminology)
section for the terms it owns (metric, weight, relevanceWeight, relevanceSaturationPoint, digest, signal,
raw/normalized value) — not repeated here.

For the full store/locale scoping picture across every feature this package owns — including exactly which
ones fan a save out to sibling locales and which never do — see [SCOPING.md](SCOPING.md); it builds on
`search-ranking`'s own [SCOPING.md](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking/blob/main/SCOPING.md).

### rating / judgment

A customer's heart/check/X click on one product for one search term, captured by the SRP widget. The raw
material every other piece in this README is built on top of. See
[SRP relevance rating](#srp-relevance-rating--capturing-real-query-product-judgments).

### query (rated query)

One distinct (search term, store, locale) triple that has accumulated at least one rating —
`spy_search_ranking_query`'s own row, with an editable `importanceWeight` curators can use to make some
queries count more than others in the aggregate rank_eval score. See
[SRP relevance rating](#srp-relevance-rating--capturing-real-query-product-judgments).

### rank_eval score

The single number Elasticsearch/OpenSearch's `_rank_eval` API returns for a set of rated queries against a
given ranking configuration — an nDCG-style, importance-weighted aggregate. The objective function every
other piece here (calibration excluded) is ultimately trying to move. See
[Rank evaluation](#rank-evaluation--a-real-objective-score-not-averaged-opinion).

### weight checkpoint

A snapshot of every tunable weight (`relevanceWeight` + every metric weight) taken automatically before an
apply action changes them — the "undo" a human or an automated run can restore. See
[Weight checkpoints](#weight-checkpoints--a-way-back-before-changing-anything-by-hand).

### z-space / softmax reparametrization

The unconstrained real-valued vector an optimization algorithm actually searches in, and the transform
(`SimplexSoftmaxReparametrization`) that converts it to and from a valid, sum-to-one set of metric weights.
Never surfaced in the GUI — an implementation detail of
[automated weight optimization](#automated-weight-optimization--searching-relevanceweight-and-metric-weights-algorithmically).

### trust region

The bounded neighborhood around the live `relevanceWeight` (`±0.15` by default) an optimization run is
allowed to search within, so one run can't propose a wildly untested value in a single shot. See
[automated weight optimization](#automated-weight-optimization--searching-relevanceweight-and-metric-weights-algorithmically).

## Status

**Saturation Point Calibration, the SRP relevance-rating widget, the Zed Assess Rated Queries page, offline `rank_eval`
evaluation, weight checkpoint/rollback, the monthly auto-tune job, and automated weight optimization
(CMA-ES, the Rechenberg/Schwefel ES, or differential evolution against the rank-evaluation score, including
`search-ranking`'s specificity-aware relevance weighting knobs) are all built, tested, and shipping.**

Verified live end-to-end in a real browser (not just the automated test suite — see
[Testing and CI](#testing-and-ci) for why that alone wouldn't have been enough): a customer clicks a rating
button on the storefront, the judgment round-trips through the Yves→Zed gateway with a server-side
permission re-check, and lands correctly in the database.

## Before you start: this needs real relevance ratings

A fresh install has zero rows in `spy_search_ranking_query_rating`. Someone has to actually click
heart/check/X on real search results — via the storefront rating widget, as a customer holding the
**Relevance Rater** permission — before most of what this package does can run at all. Unlike
`spryker-community/search-ranking`'s silent no-op degradation on missing business-signal data (see that
package's own README), the features here fail loudly and explicitly rather than quietly doing nothing:

- **`RankEvaluationRunner::computeWeightedAggregateFor()`** returns `null`, not `0.0` or `NaN`, the moment
  either no rated queries exist or no ratings exist for this store/locale — a clean "not evaluable" signal
  every caller checks explicitly, never a silently-wrong score.
- **Automated weight optimization refuses to even start** without a baseline: `OptimizationRunner::process()`
  fails the run immediately with `"No rated query with at least one rated product exists for this
  store/locale yet -- nothing to evaluate."` (and, separately, `"No active metrics exist -- nothing to
  optimize."` if `search-ranking` itself has no active metrics either) — visible in the run's status in the
  Zed GUI, not a run that silently completes having changed nothing.
- **The Zed "Assess Rated Queries" and offline `rank_eval` evaluation pages** have nothing to display until
  ratings exist — there is no fallback dataset, synthetic or otherwise.
- **Saturation Point Calibration** is the one feature that does *not* strictly require ratings — its
  default search-term source is accumulated ratings, but `SaturationPointCalibrationUploadForm` also
  accepts a manually uploaded CSV of search terms, so calibration alone can bootstrap without a single
  click on the widget.

**In short:** register the permission plugin (`RateSearchRelevancePermissionPlugin`) and the Yves widget
plugins, grant the **Relevance Rater** permission to at least one real B2B `CompanyUser`, and have that
user actually rate a handful of (query, product) pairs on the storefront — *then* Saturation Point
Calibration's rating-based source, rank_eval, weight checkpoints' evaluation context, auto-tune, and
automated weight optimization all have something real to work from. Before that, expect explicit
"nothing to evaluate" failures, not incorrect results.

## What it does today

### Saturation Point Calibration — empirically sampling `relevanceSaturationPoint`/`specificitySaturationPoint` (k)

`search-ranking` blends text relevance with business signals using a saturating transform of an unbounded
raw value: `raw / (raw + k)`, where `k` is the raw value at which the normalized result contributes
exactly 0.5. Two separate signals use this exact same shape and each needs its OWN `k`, sampled from real
data, not guessed:

- **`relevance_score`** — `k` is `relevanceSaturationPoint`, the raw Elasticsearch/OpenSearch `_score` at
  which text relevance contributes 0.5. Sampled from real per-product `_score` values via a live catalog
  query per search term.
- **`specificity`** — `k` is `specificitySaturationPoint`, the raw blended-idf specificity value (see
  `search-ranking`'s own README) at which normalized specificity reaches 0.5. Sampled from one raw
  specificity value per search term via a lightweight `_termvectors` probe — **no catalog query at all**,
  cheaper than the `relevance_score` path.

Both constants have no universal correct value — they depend entirely on a shop's own field boosts, catalog
size, and typical query shapes. Saturation Point Calibration is the one tool, with a type selector, that samples either.

The workflow, all from the **Search Ranking Optimizer → Saturation Point Calibration** Zed page, which has
its own **Viewing** Store+Locale picker at the top — independent of the "Start New Calibration Run"
form's own store/locale fields below (bootstrapping AT while still reviewing DE's own latest run is a
normal, supported thing to do). Everything else on the page is scoped to whatever the Viewing picker is
currently set to: the two live `k` values, which calibration run (if any) counts as "latest", and which
in-progress run (if any) the progress poll reports — a run for a different store/locale never leaks into
either:

1. **Start a run.** Pick the **calibration type** (`Relevance score` or `Specificity`), the store and
   locale to run against (Zed has no implicit current store, so both are picked explicitly — independent of
   the Viewing picker above), and the number of top results per term to sample (X) — ignored for
   `Specificity`, which always samples exactly one value per term regardless of X. By default, search terms
   come from the distinct queries already organically rated via the SRP widget below for that store/locale
   — no upload needed. Check **"Bootstrap from CSV upload instead"** to bypass those and provide a CSV (one
   term per line) instead — useful to bootstrap calibration before real ratings exist, or for testing.
   Either way, the run is persisted in status `uploaded`.
2. **Calculate.** The `search-ranking-optimizer:calibrate` console command (run on a cron, or by hand)
   picks up the newest `uploaded` run SYSTEM-WIDE (across every store/locale, not just the one being
   viewed), marks any older uploaded runs `skipped`, and — branching on the run's own `calibrationType` —
   either fires the **live catalog search-string query** for each term (`relevance_score`) or a single
   lightweight `_termvectors` probe per term (`specificity`), pools the sampled values across all terms,
   and computes a suggested `k` from that pool. The run moves to `calculated` (or `failed`, with a stored
   error message). While it's running, the Saturation Point Calibration page shows a live "X / Y search
   terms processed" counter in its own box — but only when the in-progress run's own store/locale matches
   the Viewing picker; a run for a different scope is still processing, just not shown on this page while
   you're looking at a different one.
3. **Apply.** Back on the Saturation Point Calibration page's own **"Latest Calibration Run"** box (kept
   deliberately separate from the **"Current Saturation Point (k)"** box above it, so the live values and
   the specific run being offered for Apply are never visually conflated), review the suggested `k` against
   the current live value and click **Apply** to write it into `search-ranking`'s `relevanceSaturationPoint`
   or `specificitySaturationPoint` setting (routed by the run's own `calibrationType`) — through
   `search-ranking`'s own facade, which republishes the ranking configuration exactly as a manual edit on
   its Settings page would. Applying is a deliberate, separate step: calibration *suggests*, a human
   *decides*.

The console prints, e.g.:

```
Saturation Point Calibration #7 done: sampled 214 value(s) across 12 search term(s), computed k = 6.4180.
```

Firing the `relevance_score` query (or the `specificity` probe) from Zed reuses `search-ranking`'s solved
raw-Elastica bypass pattern (the standard `Client\Search` stack assumes a Yves request context that
doesn't exist in a console/Zed process), shipped here as the `Client\SearchRankingOptimizer\Search`
component.

### SRP relevance rating — capturing real (query, product) judgments

Saturation Point Calibration answers "what should `relevanceSaturationPoint` be" from a *sample* of search terms. Longer
term, tuning any part of the ranking formula needs a real, organically-grown judgment set — actual people
saying "this product was/wasn't a good result for this query." The rating widget is how that judgment set
gets built, one click at a time, directly on the storefront search results page (SRP).

- **Heart / check / X buttons** render below every product tile on the SRP, for any customer holding the
  **Relevance Rater** permission (`RateSearchRelevancePermissionPlugin`) — heart = highly relevant, check =
  acceptably relevant, X = not relevant. Default grey, colorized on click (heart/X share a red-family
  accent, check is green); only one button is ever pressed per (customer, product) pair on a given SRP.
  Clicking the already-pressed button unselects it, deleting the underlying rating row — the one case
  where a click means "remove my judgment" rather than "set it."
- **One row per (query, customer, product).** The canonical search term (trimmed, lowercased,
  whitespace-collapsed — deliberately *not* tokenized, so two genuinely different queries never get merged)
  is stored once in `spy_search_ranking_query`; each rating is its own row in
  `spy_search_ranking_query_rating`, unique on `(query, customer_reference, product_abstract)`. The same
  customer re-rating the same pair upserts in place; **different customers rating the same (query, product)
  each keep their own row** — disagreement between raters is a signal to preserve, not average away at
  write time. A brand-new search term is a genuine find-or-create race the moment two raters rate it within
  the same instant: the DB's own unique `(search_term, store_name, locale_name)` constraint lets exactly one
  insert win, and the loser recovers by re-fetching the winner's row rather than losing that rater's
  judgment entirely.
- **`importance_weight`** on `spy_search_ranking_query` (default `1`) lets a **Query Curator** mark some
  queries as mattering more than others once they've accumulated ratings — a deliberately separate skill
  from rating relevance itself. Edited from the **Search Ranking Optimizer → Queries** Zed page: every
  rated query, newest-activity-first, with an "Edit importance" action per row (a plain,
  paginated/sortable/searchable `Gui` table — the same component `spryker-community/search-ranking`'s own
  Metrics page uses). Gated by standard Zed ACL only — a Zed backoffice action, not the customer-facing
  Permission system the widget below uses, so there's no separate fine-grained permission to register for
  it, just the usual ACL group access every other Zed page in this package needs.
- **Server-side authorization, not just a hidden button.** The Yves widget only *renders* for a permitted
  customer, but the write itself goes through a Zed `GatewayController` that independently re-checks the
  permission via the customer's active `CompanyUser` (never trusts the Yves-side check alone) before
  persisting anything.
- **Rejects fabricated (query, product) pairs.** Before persisting a submitted judgment,
  `ProductRelevanceJudgmentWriter` re-runs the same live catalog search Saturation Point Calibration/rank_eval use
  (`ProductSearchMatchVerifier`, narrowed to the one candidate document) and confirms the product is
  actually among the *current* real search results for that term — a request claiming an unrelated product
  matched some search term is rejected outright, never silently trusted from the client.
- **CSRF-protected.** The widget's submit/clear endpoints are plain POST controllers, not bound to a
  Symfony Form, so they'd otherwise carry none of the CSRF protection every Form-backed POST in this
  project gets automatically. A token is generated per page render via
  `searchRankingOptimizerRatingCsrfToken()` (`SearchRankingOptimizerWidgetTwigPlugin`, the same
  `CsrfTokenManagerInterface` mechanism `spryker/multi-factor-auth`'s own Yves module uses for its own
  non-Form AJAX actions), rendered onto the widget as a data attribute, and sent back with every submit/
  clear request — re-validated server-side before anything else runs.

This is also Saturation Point Calibration's default search-term source (see above) — accumulated ratings feed straight into
the next calibration run with no export/import step. The ratings are also the direct input to rank_eval
evaluation, below.

### Rank evaluation — a real objective score, not averaged opinion

A weight-slider preview or a propose/review/apply workflow alone can't answer "did that change make
search better?" without something to measure against. Rank evaluation turns the ratings the widget above
already collects into a real nDCG (Normalized Discounted Cumulative Gain) score via OpenSearch/
Elasticsearch's `_rank_eval` API — a genuine information-retrieval metric, not human opinion averaged
together.

The workflow, from the **Search Ranking Optimizer → Test Current Evaluation** Zed page:

1. **Pick a store and locale** and click **Evaluate now**. Unlike Saturation Point Calibration's upload-then-cron-then-poll
   flow, `_rank_eval` fires as a single batched HTTP request covering every rated query at once — fast
   enough to run synchronously, so there's no progress counter or polling needed here.
2. Every individual rating for that store/locale is grouped into a mean gain per (query, product) pair
   (heart/check/x → a configurable numeric gain, default 3/1/0 —
   `SearchRankingOptimizerConfig::getRelevanceJudgmentGainMap()`; a query rated by multiple admins is
   averaged, never overwritten, the same disagreement-preserving design the rating widget itself uses).
3. One `_rank_eval` request per query is built from the exact same live catalog query Saturation Point Calibration fires
   (shared via `LiveCatalogSearchQueryBuilder`), paired with that query's rated products as judgments.
   `metric.dcg.normalize=true` computes nDCG@10 (cutoff configurable —
   `SearchRankingOptimizerConfig::getRankEvalCutoff()`).
4. A **query-importance-weighted aggregate** is computed in PHP from each query's own nDCG score — rank_eval's
   own top-level `metric_score` is a plain *unweighted* mean across queries, confirmed unusable directly.
   The result is persisted (score, query count, timestamp) and the page shows both the latest run and a
   short history, so the score can be tracked over time as ratings accumulate.

```
Evaluated 12 rated queries: weighted nDCG@10 = 0.7123.
```

Firing the query and the `_rank_eval` call both reuse the same raw-Elastica bypass pattern Saturation Point Calibration
established (`Client\SearchRankingOptimizer\Search` component), verified live against this shop's real
OpenSearch index and real catalog products.

### Weight checkpoints — a way back before changing anything by hand

Every tuning knob this package will eventually set automatically (weight-slider preview, propose/review/
apply, auto-tune) is still, today, something an admin edits directly on `search-ranking`'s own Settings
page. A checkpoint is a point-in-time snapshot of every one of those knobs, so a manual edit — or a future
automated one — is always reversible.

From the **Search Ranking Optimizer → Weight Checkpoints** Zed page, which — like every other scoped page
in this package — has its own **Store + Locale selector** at the top:

1. **Current State** shows exactly what `search-ranking` is using right now **for the selected scope**,
   read live off its own facade: `relevanceWeight`, every metric's own weight, the 4 specificity-weighting
   knobs (blend weight, weight exponent, weight shift magnitude, curve exponent), and whether specificity
   weighting is currently enabled at the code level. Deliberately excluded: `relevanceSaturationPoint`/
   `specificitySaturationPoint` (k), which already have their own versioning story via Saturation Point Calibration and
   stay out of checkpoint scope.
2. **Take checkpoint now** persists that current state as a new row **tagged with the selected (store,
   locale)** — a manual snapshot, before hand-editing anything.
3. **History** lists **every** checkpoint newest-first across every scope (with its own Store/Locale
   columns, not filtered to the currently selected one), each with a **Restore** button. Restoring writes
   that checkpoint's `relevanceWeight`, metric weights, and 4 specificity knobs back through
   `search-ranking`'s own facade **for the currently selected scope** — independent of whichever scope the
   checkpoint itself was originally recorded for, so a DE checkpoint can deliberately be restored into AT
   if that's genuinely what's wanted (a metric that no longer exists is skipped silently — a safe,
   best-effort restore, not an all-or-nothing transaction) — then immediately records the resulting state
   as a **new** checkpoint of its own, for that same target scope. Restoring IS applying, not a special
   "undo" mechanism — there is always a way back from a restore too. If any metric in a checkpoint is
   store-wide (`isLocaleScoped=false`) in the currently selected target store, restoring fans that metric's
   weight out to every real locale of that store, not just the selected one — the same fan-out
   `search-ranking`'s own `saveMetricWeight()` always does. The page names exactly which metric and which
   sibling locales are affected next to that Restore button before it's clicked.

`isSpecificityWeightingEnabled` is captured on every checkpoint for historical transparency but is
**never** written back by a restore — it is a pure code-level project flag
(`Pyz\Shared\SearchRanking\SearchRankingConfig::isSpecificityWeightingEnabled()` in a host shop), with no
corresponding save method on `search-ranking`'s facade, deliberately out of scope for anything
database-driven.

### Auto-tune — a monthly fit-quality check per metric

Weight checkpoints above cover `relevanceWeight`/metric weights/specificity knobs — but a metric's own
normalization **formula** (does `pdp_impressions` still fit an `atan` curve, or has the underlying data
drifted enough that a different shape now fits better?) is a completely separate axis, with its own
audit trail already built into `search-ranking` itself (`spy_search_ranking_metric_history`, see that
package's own README). Auto-tune is the monthly job that watches that axis and, per metric, proposes or
applies a refit once the fit degrades — it never touches `relevanceWeight`, metric weight, or the
specificity knobs, so it has no reason to write a weight checkpoint of its own.

**Auto-tune runs independently for every real configured store, and — for a genuinely locale-scoped
metric — independently for every real locale of that store too.** `search-ranking` itself supports a
metric whose formula genuinely differs per locale for a metric explicitly flagged `isLocaleScoped=true`
(rare — most metrics stay store-wide; see that package's own README and `SCOPING.md`). For a store-wide
metric (the common case), this job checks/refits only the store's own default locale — a refit/apply for
it fans out to every real locale of the store on `search-ranking`'s own side regardless of which one
locale triggered it, so checking every locale independently would be redundant AND actively wrong: an
independent refit per locale would refit against each locale's own digest and re-fan-out on every
iteration, leaving whichever locale was processed last to silently overwrite every earlier one. For a
genuinely locale-scoped metric, this job instead checks/refits/applies EVERY real locale of the store
fully independently, since a save for one locale never touches another there.

Auto-Tune's own settings table (`spy_search_ranking_auto_tune_metric_config`) is store+locale scoped, the
same grain `search-ranking`'s own formula/isActive/shape settled on — an earlier version of this table was
deliberately store-only ("what auto-tune tunes is locale-scoped, but its own threshold doesn't need to
be"), but that stopped being coherent once a metric's formula can genuinely diverge per locale: a curator
tuning `de_DE` and `en_US` independently has no way to give them independent thresholds if the config
itself can't tell the two apart. `AutoTuneMetricConfigWriterInterface::save()` reuses `search-ranking`'s
own `resolveEffectiveWeightLocales()` to decide the real footprint of a save, exactly mirroring how that
package's own formula/weight writes work: for a store-wide metric, saving at ANY one locale of a store
fans the same threshold/auto-update/notify settings out to every real locale of it, so it doesn't matter
which locale was selected when you saved; for a genuinely locale-scoped metric, only the one locale
selected is touched, and a sibling locale never explicitly configured simply has no row and is treated as
opted out for itself — same "absence means opted out" contract `autoTuneThreshold=null` already has, now
applied per locale too, not just per metric. A store that has never had `search-ranking` configured for it
is skipped entirely, never evaluated against empty/default state.

For a store-wide metric, this job also surfaces the evidence for *whether* it should become locale-scoped
in the first place: its one result line is followed, when the spread across its real locales exceeds
`SearchRankingOptimizerConfig::getLocaleFitDivergenceWarningThreshold()` (0.1 by default), by a warning
line showing every real locale's own current fit (purely informational — nothing acts on it
automatically; a genuinely locale-scoped metric never gets this warning, since it already gets its own
full result line per locale instead):

```
[DE/de_DE] top_seller: fit still adequate (R² = 0.9883), no change.
[DE/de_DE] top_seller:   ⚠ fit varies by locale (spread 0.3861): de_DE=0.9883, en_US=0.6022 — this store-wide formula may not fit every locale equally well.
```

From the **Search Ranking Optimizer → Auto-Tune Settings** Zed page — like every other scoped page in
this package, with its own **Store + Locale selector** at the top — per active metric:

- **Auto-tune threshold (R²)** — left blank by default, meaning the metric is opted OUT of auto-tune
  entirely. Setting a value opts it in: the monthly job compares the metric's CURRENT fit (evaluated
  fresh every run, no side effect) against this floor.
- **Auto-update** (on/off) and **notify by email** (on/off) are independent toggles — all four
  combinations are meaningful: neither (log only, via an `isChange=false` row in `search-ranking`'s own
  history table), auto-update only (silent refit — a bit of a leap of faith, but supported), notify only
  (a proposal email, formula left untouched until a human applies it via `search-ranking`'s own Edit
  page), or both (refit applied AND a summary email sent).
- **Auto-update scope**, once auto-update is on: **"Keep current shape (parameters only)"** re-fits only
  the metric's own current curve family's parameter (e.g. keep `atan`, just recompute its `k`); **"Program's
  choice (may switch shape)"** takes the overall best-fitting candidate across every shape
  `search-ranking`'s own curve fitter offers, even if that means switching families entirely. A metric
  with no known `shape` yet (a freeform/custom formula) always gets program's-choice behavior regardless
  of this setting — there's no "current shape" to stay within.

Running `vendor/bin/console search-ranking-optimizer:auto-tune` (intended for the monthly cron, see
[Installation](#installation)):

```
[DE/de_DE] pdp_impressions: fit still adequate (R² = 0.9883), no change.
[DE/de_DE] random: fit dropped to R² = -1.0634 (below threshold) — skipped, no refit: formula is non-deterministic.
[AT/de_DE] pdp_impressions: fit dropped to R² = 0.6021 (below threshold) — proposed atan(x / 4.1) (R² = 0.9412).
Notified 0 admin(s) by email.
```

Each line is prefixed with the store and locale it applies to — a multi-store run checks the same metric
name once per store (each at its own default locale, unless it's genuinely locale-scoped — see below), so
the prefix is what tells two "pdp_impressions" lines apart.

A metric whose formula calls a non-deterministic function (`random()` is the one that ships in
`search-ranking`'s own formula DSL today — see
[Automated weight optimization](#automated-weight-optimization--searching-relevanceweight-and-metric-weights-algorithmically)
below for the other place this same concept applies) is deliberately never refit, even with auto-update on
and even though its fit is genuinely, persistently bad: fitting a "better" curve to noise would just overfit
to whatever randomness happened to be in that one digest snapshot, then silently swap in a formula that
*looks* like a real fit but carries no more signal than `random()` did. It's still checked and shows up in
history/the summary email with its real fit — that observation is legitimate, only auto-*applying* a refit
for one isn't.

An unexpected failure while checking one metric (a transient Elasticsearch/database error, say) never
aborts the rest of the run — every other metric with a threshold set still gets checked in the same pass.
The failed metric shows up instead with its error, both in the console output and, if notify is on for it,
in the summary email, rather than silently vanishing or taking every other metric's check down with it:

```
[DE/de_DE] pdp_impressions: fit still adequate (R² = 0.9883), no change.
[DE/de_DE] top_seller: FAILED to check — Elasticsearch unreachable.
Notified 1 admin(s) by email.
```

Exactly **one** combined before/after summary email is sent per run — never one per metric, and never
one per store — covering every metric (across every store, and across every real locale for a
locale-scoped one) that crossed its threshold with notify on, to every admin holding an ACL role named
`search-score-admin` (every member of every ACL group holding that role; see [Requirements](#requirements)).
The summary email's table has its own Store and Locale columns for the same reason the console output has
its `[DE/de_DE]`/`[AT/de_DE]` prefix.
A run that needs to notify but finds no admin holding that role yet simply sends to nobody
(`notifiedEmailCount = 0`), logged rather than treated as an error — the same posture the weight-checkpoint
restore path takes toward a metric deleted since a checkpoint was taken.

### Automated weight optimization — searching `relevanceWeight` and metric weights algorithmically

Weight checkpoints let a human propose new weights and roll them back; auto-tune keeps a metric's own
normalization formula honest. Neither one actually *searches* for a better `relevanceWeight`/metric-weight
combination — that's what this piece does, using rank evaluation's nDCG-style score (see
[Rank evaluation](#rank-evaluation--a-real-objective-score-not-averaged-opinion) above) as the objective
function for a real black-box optimizer, rather than only via human proposals.

The search space is `search-ranking`'s actual constraint shape: `relevanceWeight` clamped to a trust region
around its current live value (`SearchRankingOptimizerConfig::getRelevanceWeightTrustRegionMaxDistance()`,
default `±0.15`, so a run can't wander off into an untested part of the space in one shot), and every
active metric's weight constrained to a simplex (all metric weights `>= 0` and summing to `1`). The simplex
is handled via a softmax reparametrization (`SimplexSoftmaxReparametrization`): the optimizer itself only
ever sees an unconstrained real-valued vector (one free coordinate pinned to remove the redundant
shift-invariant direction softmax would otherwise introduce), and `ParameterVectorMapper` converts it to and
from a real `SearchRankingConfigurationStorageTransfer` — so every candidate the optimizer proposes is a
valid, real configuration by construction, with no rejection/repair step needed.

Alongside `relevanceWeight` and the metric-weight simplex, the search also covers `search-ranking`'s 4
specificity-aware relevance weighting parameters — `specificityWeightExponent`,
`specificityWeightShiftMagnitude`, `specificityBlendWeight`, and `specificityCurveExponent` (see
`search-ranking`'s own README for what these do: shifting `relevanceWeight` per query based on how specific
that query's own text is — a rare term like a SKU vs. only common words; `specificityCurveExponent`
specifically controls how sharply the `[0;1[`-normalized specificity value transitions around the
calibrated saturation point). Each gets its own independent trust region around its current live value, the
same "can't wander off in one shot" shape as `relevanceWeight`'s own trust region.
`specificitySaturationPoint` is deliberately NOT one of these dimensions — like `relevanceSaturationPoint`,
it's Saturation-Point-Calibration-tunable only, the same precedent `ParameterVectorMapper`'s own docblock already documents
for the text-relevance side. This closes what would otherwise be a real gap: `search-ranking`'s evaluation
path builds its own `function_score` query directly rather than going through the live storefront's
query-expander plugin stack, so without this, a candidate's specificity settings would silently never be
exercised at all during optimization, no matter how they were configured live.

These 4 dimensions are only ever searched at all when `search-ranking`'s
`SearchRankingConfig::isSpecificityWeightingEnabled()` is on — a project-level code flag, off by default,
the same gate `SearchRankingFunctionScoreQueryExpanderPlugin` itself checks before ever firing the live
probe. When it's off, this package respects that at every layer: evaluation never applies the shift
regardless of what a candidate's own specificity fields say, and the 4 dimensions are omitted from the
search vector entirely rather than merely held fixed like an excluded metric — a disabled feature has no
live effect for the optimizer to spend search budget improving.

Any active metric whose own formula calls a non-deterministic function (`random()` — see
[Auto-tune](#auto-tune--a-monthly-fit-quality-check-per-metric) above for the same concept applied there)
is excluded from the search entirely rather than folded into the simplex: `FormulaDeterminismChecker`
flags it, and its weight is held **fixed at its current live value** for the whole run — searching a weight
against pure noise would be meaningless. Excluding it isn't just "drop it from the simplex," though:
`ParameterVectorMapper` reserves that metric's exact weight as a fixed budget up front, and scales the
*optimizable* metrics' own simplex to fill only what's left, so the full set (optimizable + fixed) still
sums to `1` on every candidate this mapper produces — a naive filter would either silently zero the
excluded metric's weight on apply, or let the other metrics quietly absorb its whole share.

A metric with `isLocaleScoped=false` (a store-wide fact — see `search-ranking`'s own
[SCOPING.md](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking/blob/main/SCOPING.md)) is
searched and proposed exactly like any other deterministic metric — this run's own (store, locale) reads
and writes it the same way a human editing it from the Metrics page in any one locale already does today,
via `search-ranking`'s own `saveMetricWeight()`, which fans that write out to every real locale of the
store. Excluding it would have been solving a problem that doesn't exist: the fan-out isn't a new risk the
optimizer introduces, it's the existing, accepted semantics of `isLocaleScoped=false`. The Automated Weight
Optimization page still discloses the blast radius before Apply — via
`resolveEffectiveWeightLocales()`, the same call the Weight Checkpoints restore warning uses — so a human
approving Apply always sees which sibling locales a store-wide metric's proposed weight will also land on.

The actual black-box optimization — the algorithms, their generic `Parameter`/`ProblemInterface`
vocabulary, and the objective-function contract — lives in a separate, Spryker-agnostic package,
[andrebarthelmeshellmuth/blackbox-optimizer](https://github.com/andrebarthelmeshellmuth/blackbox-optimizer),
a real `require` of this one. `ParameterVectorMapper` and `SimplexSoftmaxReparametrization` are this
package's own side of that boundary — the domain-specific glue translating `search-ranking`'s real
configuration to and from the unconstrained vectors the generic optimizer works with.

Three black-box algorithms ship, selectable per run:

- **CMA-ES** (Covariance Matrix Adaptation Evolution Strategy) — the default. Adapts both a step size and a
  full covariance matrix from generation to generation, so it learns the search space's actual shape
  (correlated weights, differing sensitivities) rather than searching each dimension independently.
- **Rechenberg/Schwefel ES** — CMA-ES's own historical predecessor: isotropic Gaussian mutation and
  plus-selection, with step size adapted by Rechenberg's classic 1/5 success rule instead of a learned
  covariance matrix. Meaningfully simpler than CMA-ES, at the cost of not learning correlations between
  weights.
- **Differential evolution** — deliberately simpler still (mutate-crossover-select against the current
  population, no covariance adaptation at all), included as a baseline "the thing to beat" rather than
  because it's expected to win.

As of `blackbox-optimizer` 1.2, all three also stop before `maxGenerations` on their own once they've
converged, diverged, or plateaued (each algorithm's own criteria — see that package's own README for the
per-algorithm detail), and expose a `trustTerminationCriteria()` escape hatch to trust that over an
arbitrary generation-count guess. `OptimizationRunner` doesn't call it yet — every run here still uses the
fixed `getOptimizationMaxGenerations()` budget (150) as its own stopping point; wiring up
`trustTerminationCriteria()` as a run option is a natural, still-open follow-up, not done as part of this
bump.

The workflow, from the **Search Ranking Optimizer → Automated Weight Optimization** Zed page:

1. **Run now.** Pick the store, locale, and algorithm. This queues a run and immediately processes it
   in-request (small population/generation counts keep a run to a handful of seconds against this demoshop's
   own judgment set); a real shop with a much larger judgment set would run this via
   `vendor/bin/console search-ranking-optimizer:optimize` on a cron instead, one run at a time (FIFO —
   oldest queued run first), and let the page's poll pick up the result once it lands.
2. **Compare.** Once done, the page shows the baseline score (the live configuration's own rank-evaluation
   score) against the winning candidate's score, plus the concrete `relevanceWeight`, per-metric weight, and
   specificity-knob values that produced it — never applied automatically.
3. **Apply**, only if the comparison looks like a real improvement. Applying writes the winning
   `relevanceWeight`, metric weights, and specificity knobs through `search-ranking`'s own facade, records an
   optimizer-sourced weight checkpoint first (so it's one click back to the prior state via the
   [Weight checkpoints](#weight-checkpoints--a-way-back-before-changing-anything-by-hand) page), and
   republishes the live storefront configuration — the same "write through facade, checkpoint first,
   publish explicitly" shape every other apply action in this package follows.

## Relationship to search-ranking

- **One-directional dependency.** This package depends on `search-ranking`; `search-ranking` never depends
  on this one. That keeps `search-ranking`'s scope to "use business signals to rank" and this package's to
  "decide what the parameters should be."
- **A real `require` (`^1.1`).** `search-ranking` is public, so a hard `require` resolves cleanly in CI too
  — no `suggest`-plus-runtime-note workaround needed. The coupling goes beyond the Zed dependency bridges'
  own docblock `@param`/`@var` type hints (Spryker's standard untyped-bridge convention): the Client layer
  (`RankEvalRunner`) also imports `search-ranking`'s `FunctionScoreBuilder`/`QuerySpecificityCalculator`
  directly, to apply the exact same ranking formula and specificity-aware relevance-weight shift a real
  storefront search would, rather than reimplementing either (only the `_termvectors` IO itself is
  reimplemented, for the same Zed/console execution-context reasons documented in `RankEvalRunner`'s own
  docblock).
- **`search-ranking`'s metric formula/active-flag/shape are (store, locale)-scoped**, same tier as weight
  (see that package's own README) — but for the common `isLocaleScoped=false` metric, a save at any one
  locale fans out to every real locale of the store, so it's effectively still store-wide in outcome. The
  bridge methods this package calls through
  (`SearchRankingOptimizerToSearchRankingFacadeInterface::getActiveMetrics()`/`findMetricDetail()`/
  `saveMetricFormula()`) all require explicit `$storeName`/`$localeName` accordingly. Automated weight
  optimization (a real per-`spy_search_ranking_optimizer_run` store/locale) passes its own run's real
  scope; Auto-tune (genuinely per-store, AND per-locale for a genuinely locale-scoped metric — see above)
  passes each real locale it's actually checking, derived from `evaluateCurrentMetricFitAcrossLocales()`'s
  own return map rather than re-deriving locales any other way.
- **`search-ranking`'s per-metric `isLocaleScoped` flag** (see that package's own
  [SCOPING.md](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking/blob/main/SCOPING.md)) is
  a store-wide-vs-per-locale fact this package respects end to end for weight, not just reads:
  `getActiveMetrics()`/`getMetricWeights()`/`findMetricDetail()` all surface it, and
  `resolveEffectiveWeightLocales()` — a thin passthrough to `search-ranking`'s own method of the same name
  (which now also governs formula/active/shape fan-out on that side, not just weight) — lets this package
  ask which locales a given weight write would actually touch before committing one. Two independent
  callers use it for the same purpose: the Weight Checkpoints restore warning, and the Automated Weight
  Optimization Apply disclosure (see above) — both surface the real blast radius of a store-wide metric's
  write before a human clicks the button, rather than treating `OptimizationRunner` as needing to exclude
  such a metric at all.

## Bootstrapping one store/locale, then fanning out everywhere

A common way to roll this package out across a multi-store, multi-locale shop: do the real tuning work
once, against one (store, locale), then let `search-ranking`'s own Scope Copy/Lock carry the *result* to
every other scope — rather than repeating ratings/calibration/optimization independently per scope, most of
which would just re-derive the same answer at real cost (query traffic, admin rating effort, optimizer
runs).

1. **Rate and gather data for exactly one (store, locale)** — e.g. `DE`/`de_DE`. Collect real SRP
   heart/check/x ratings via the storefront widget, and make sure `search-ranking`'s own product-metric raw
   values are imported for that scope (see that package's README).
2. **Calibrate `k` for that one scope** — run Saturation Point Calibration (feature 1 in
   [SCOPING.md](SCOPING.md)) and Apply, so the relevance-score curve is on a sane footing before optimizing
   against it.
3. **Let the black-box optimizer tune `relevanceWeight` and every metric weight for that one scope** —
   queue an [Automated weight optimization](#automated-weight-optimization--searching-relevanceweight-and-metric-weights-algorithmically)
   run against `DE`/`de_DE`'s own real ratings, review the winning candidate against baseline, and Apply.
4. **Fan the result out via `search-ranking`'s own Scope Copy/Lock** — from `search-ranking`'s Scope Copy
   page, copy (or Lock, for an ongoing daily resync) `DE`/`de_DE`'s now-tuned weight, the 6 settings
   (`relevanceWeight`/`relevanceSaturationPoint`/4 specificity knobs), and formula/isActive/shape out to
   every other real store and locale. One Lock covers everything Scope Copy's combined action copies — see
   that package's own [SCOPING.md](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking/blob/main/SCOPING.md#quick-reference).

That's the whole loop: tune once for real, replicate everywhere else. It's not free of manual steps, though
— the known exceptions, all things Scope Copy/Lock simply cannot touch because they're not part of
`search-ranking`'s own copyable configuration at all:

- **Saturation Point Calibration itself doesn't fan out** (see [SCOPING.md](SCOPING.md), feature 1) — a
  copied/locked `k` value is exactly as good as the scope it was calibrated for, but if a target locale's
  own score distribution genuinely differs (a real risk once vocabulary/catalog density diverges enough),
  its `k` is now a copy of a value calibrated for different data. Locked scopes don't re-calibrate
  themselves; recalibrating a specific target scope once real traffic exists there is a manual, deliberate
  follow-up, not something this workflow does automatically.
- **SRP ratings and rank evaluation never fan out** (SCOPING.md, features 2/3) — a target scope with real
  customers of its own will need its own real ratings to eventually evaluate or re-optimize against; copied
  weights don't come with copied ratings, by design (ratings reflect real buyer judgment for that scope,
  which can't legitimately be assumed identical elsewhere).
- **Auto-Tune's own config only fans out by `isLocaleScoped`, not by Scope Copy/Lock** (SCOPING.md, feature
  5) — turning on auto-tune's threshold/notify settings for `DE`/`de_DE` does NOT get copied to `AT`/`de_DE`
  by Scope Copy or Lock; that's a separate, independent save (though for a store-wide metric, saving it once
  per *store* still fans across that store's own locales the normal way — see SCOPING.md).
- **`search-ranking`'s own metric `name`/`isHigherBetter`/`isLocaleScoped` are global already** — nothing to
  copy there; they're the same everywhere a metric exists at all, by definition (see that package's own
  SCOPING.md step 4/5).
- **One index per store, not per locale, is Spryker's own real ES/OpenSearch convention** — this package
  and `search-ranking` don't control or enforce it, but it means specificity/idf-style signals that read off
  term statistics are computed from an index shared across every locale of a store, not isolated per locale
  — a real, accepted blending limitation worth knowing about when interpreting a specificity-related
  calibration or optimization result for a multi-locale store (see `SpecificitySearcher`/`RankEvalRunner`'s
  own docblocks in `search-ranking` for where this is documented at the code level).

## Requirements

- PHP >= 8.3
- Spryker (kernel/gui/catalog/store/locale/propel-orm/search-elasticsearch/permission/permission-extension/
  company-user/acl/symfony-mailer — see `composer.json` for floors, verified by `composer check-floors`)
- A running Elasticsearch/OpenSearch catalog search (calibration fires real queries against it)
- **`spryker-community/search-ranking` installed and wired** — a real `require`; the Apply step writes
  into its `relevanceSaturationPoint`/`specificitySaturationPoint` settings via its facade, and the
  auto-tune job writes into its metric formulas the same way
- **`andrebarthelmeshellmuth/blackbox-optimizer`** — also a real `require` (`^4.0.1`); not on Packagist, so
  it needs the same repository-entry treatment as `search-ranking` below (see
  [Installation](#installation)). Provides the actual CMA-ES/Rechenberg-Schwefel-ES/Differential-Evolution
  algorithms the automated weight optimization feature searches with.
- **B2B company-user accounts** — the rating widget resolves "is this customer allowed to rate" via their
  active `CompanyUser`, the same permission-granting mechanism the rest of a B2B shop already uses. A B2C-only
  shop with no `CompanyUser` module has nothing to grant the Relevance Rater/Query Curator permissions to.
- **A working `spryker/mail`/`spryker/symfony-mailer` SMTP setup** — only needed if you actually enable a
  metric's "notify by email" auto-tune toggle; everything else in this package works without it.
- **An ACL role named `search-score-admin`** — only needed for the same reason, to resolve who receives
  the auto-tune notification email (every member of every ACL group holding that role). Create it via the
  Zed ACL Gui or your own `data:import acl-role`-style fixture; nothing here creates it for you. Creating
  the role is not enough on its own — it also has to be assigned to a group that has users in it, or the
  email still resolves to nobody. `search-ranking-optimizer:check-installation`
  ([step 8](#8-verify-the-installation)) warns about both cases once any metric has notification enabled.

## Installation

If `search-ranking` is already installed in your project, steps 2 and 5 are already done — this package
shares its `SprykerCommunity` core namespace and its `spryker-community/*` translation glob.

### 1. Install the package

Not published on Packagist under the `spryker-community`/`andrebarthelmeshellmuth` vendor namespaces —
install from VCS repositories instead. Its 2 real, non-Spryker `require`s
(`spryker-community/search-ranking` and `andrebarthelmeshellmuth/blackbox-optimizer`) need their own `vcs`
repository entries too (skip whichever you already have — `search-ranking`'s is often already present if
it's separately installed):

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/andrebarthelmeshellmuth/spryker-search-ranking-optimizer"
    },
    {
        "type": "vcs",
        "url": "https://github.com/andrebarthelmeshellmuth/spryker-search-ranking"
    },
    {
        "type": "vcs",
        "url": "https://github.com/andrebarthelmeshellmuth/blackbox-optimizer"
    }
]
```

```bash
composer require spryker-community/search-ranking-optimizer:^1.0
```

Working inside this demoshop's own monorepo instead of a separate project? Use a `path` repository for
this package itself (keep the two sibling `vcs` entries above as-is) and `:@dev`, so edits are picked up
without a round trip through GitHub:

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
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerAutoTuneConsole;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerCalibrateConsole;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerCheckInstallationConsole;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerOptimizeConsole;

new SearchRankingOptimizerCalibrateConsole(),
new SearchRankingOptimizerAutoTuneConsole(),
new SearchRankingOptimizerOptimizeConsole(),
new SearchRankingOptimizerCheckInstallationConsole(),
```

### 3a. Register the permission plugin (required for the SRP rating widget)

In **both** `Pyz\Zed\Permission\PermissionDependencyProvider::getPermissionPlugins()` and
`Pyz\Client\Permission\PermissionDependencyProvider::getPermissionPlugins()`:

```php
use SprykerCommunity\Shared\SearchRankingOptimizer\Plugin\RateSearchRelevancePermissionPlugin;

new RateSearchRelevancePermissionPlugin(),
```

The Query Curator page (editing a query's importance weight) needs no separate registration here — it's
a Zed backoffice action gated by standard Zed ACL, not the customer-facing Permission system.

Registering the plugin only makes the permission *grantable* — the widget stays invisible until a
customer's company role is actually given `RateSearchRelevancePermissionPlugin` (via your project's own
company-role/permission fixture data, e.g. `company_role_permission.csv` if you use the standard
`CompanyRoleDataImport`). A customer already logged in when the grant is added needs to log out and back
in for the permission to take effect in their session.

**A freshly-registered permission plugin also needs a one-time DB sync before it's usable anywhere** —
`spryker/permission`'s own `spy_permission` table is the source of truth the Company Role edit GUI's
checkbox list (and step 8's `check-installation` command) both read against, and nothing populates a new
row for it automatically. Until that sync runs, granting or even *checking* the permission throws
`Undefined array key "RateSearchRelevancePermissionPlugin"` deep inside
`PermissionFacade::findMergedRegisteredNonInfrastructuralPermissions()` — not a message that points back
to this step. The sync itself (`PermissionFacade::syncPermissionPlugins()`) runs automatically the moment
anyone loads `spryker/permission`'s own Zed landing page (`Spryker\Zed\Permission\Communication\Controller\IndexController`)
— visit it once in Zed (wire up its route/navigation entry if your project doesn't have one already) and
every registered-but-unsynced permission plugin, this one included, gets its row.

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
{# Once per page, BEFORE the product loop. #}
{% set canRateSearchRelevanceValue = canRateSearchRelevance() %}
{% set searchRelevanceRatings = canRateSearchRelevanceValue
    ? getSearchRelevanceRatings(data.searchString, data.products | default([]) | map((product) => product.id_product_abstract))
    : {} %}

{# Once per product, INSIDE the loop. #}
{% include molecule('search-ranking-optimizer-product-rating', 'SearchRankingOptimizerWidget') with {
    data: {
        canRate: canRateSearchRelevanceValue,
        searchTerm: data.searchString,
        idProductAbstract: product.id_product_abstract,
        csrfToken: searchRankingOptimizerRatingCsrfToken(),
        activeRatingType: searchRelevanceRatings[product.id_product_abstract] | default(null),
    }
} only %}
```

`searchRankingOptimizerRatingCsrfToken()` comes from the same Twig plugin registered above — the widget's
submit/clear actions are plain POST controllers, not bound to a Symfony Form, so without this field they'd
carry none of the CSRF protection every Form-backed POST in this project gets automatically.

`getSearchRelevanceRatings(searchTerm, idProductAbstracts)` comes from that same plugin and is **not
optional if you want prior judgments to show up**: it returns this customer's already-persisted rating type
per product abstract, and the widget's `activeRatingType` is what turns the corresponding button's
`aria-pressed` on at render time. Omit it and every button renders unpressed on every page load — ratings
are still submitted and stored correctly, they just silently never appear again after a reload, which reads
as "the rating didn't save". Call it **once per page** with the whole result page's product ids (one
batched Zed round trip), never once per product.

Compute `canRateSearchRelevance()` **once per page** too, not once per product, and pass the same value
into every product's include. If your SRP template also renders `spryker-community/search-debug`'s overlay in a
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

#### 3c. Optional but recommended: the Yves installation-check page

Everything in 3b fails **silently**. A missing `activeRatingType`, an unimported glossary and an unbuilt
frontend all leave a storefront that renders perfectly and simply never reflects a stored judgment — there
is no error anywhere to notice, and the symptom (a rating that "didn't save") points at the wrong layer.

Set the flag in a development-tier config (e.g. `config/Shared/config_default-development.php`):

```php
use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConstants;

$config[SearchRankingOptimizerConstants::IS_CHECK_INSTALLATION_PAGE_ENABLED] = true;
```

Then visit `/search-ranking-optimizer-widget/check-installation` as a customer holding
`RateSearchRelevancePermissionPlugin`. It reports on the Twig helper functions, the submit/clear routes,
whether the stored-judgment lookup actually completes against Zed, whether the glossary was imported, and
whether the frontend build picked this package's components up — each with the exact remedy.

The flag defaults to `false`, so the route does not exist at all until a project opts in; the URL 404s
rather than existing-but-denied. It complements `vendor/bin/console
search-ranking-optimizer:check-installation` (Propel tables, console-command registration, the permission
plugin, Zed translations) — Zed never bootstraps the
Yves DI container, so neither can see the other's half.

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

Because a missing entry never errors — the page simply cannot be reached from the sidebar, and a stale
cache hides a correct copy just as completely — `vendor/bin/console search-ranking-optimizer:check-installation` verifies every one of
this package's page keys against the built navigation cache, reading the expected list from the package's
own `navigation.xml` so it also catches a page added by a later version that your project never copied. It
tells the two failures apart: "not in your navigation.xml" and "in your navigation.xml but not in the
cache" get different remedies.

### 5. Translations

**Zed GUI** (Saturation Point Calibration page): like its siblings, this package ships its Zed strings as
`spryker/translator` CSV catalogs under [`data/translation/Zed/`](data/translation/Zed/) (Zed's `trans`
filter does **not** use the Yves-facing Glossary module). If your project already extended
`Pyz\Zed\Translator\TranslatorConfig::getCoreTranslationFilePathPatterns()` with the
`spryker-community/*` glob for `search-ranking`, this package is auto-discovered by the same glob — no
extra step. Otherwise add it once:

```php
$coreTranslationFilePathPatterns[] = APPLICATION_VENDOR_DIR . '/spryker-community/*/data/translation/Zed/[a-z][a-z]_[A-Z][A-Z].csv';
```

Adding the glob is not enough on its own — Zed's translation catalog is cached. Rebuild it once after
wiring the glob (or after this package is installed at all, if the glob was already present):

```bash
vendor/bin/console translator:clean-cache
vendor/bin/console translator:generate-cache
```

Skipping this makes any of this package's Zed GUI strings — e.g. the "Search Ranking Saturation Point
Calibration" page title — resolve to their raw translation key instead of real text, with no error either
in the console or in the browser.

**Yves widget** (the three button titles): the opposite mechanism — a plain
[`data/glossary.csv`](data/glossary.csv), imported the normal Spryker way (this is the same
Redis-backed Glossary module every Yves-facing string in a Spryker shop already uses):

```bash
vendor/bin/console data:import glossary
```

**If your project's glossary import source is a single merged CSV** (the common demo-data-starter
pattern — one `data/import/common/common/glossary.csv` covering the whole project, rather than a
per-bundle glob), running the command above alone does nothing for this package: it re-imports whatever
your project's own file already contains, and this package's rows in its own `data/glossary.csv` were
never part of that. Copy this package's rows into your project's file first, then import. The three
`search_ranking_optimizer.rate.*` glossary keys and the `permission.name.RateSearchRelevancePermissionPlugin`
row are both shipped in [`data/glossary.csv`](data/glossary.csv) for exactly this copy-paste.

### 6. Build (transfers, Propel tables, caches)

This package ships a Propel schema for **eight** tables: `spy_search_ranking_saturation_point_calibration` +
`spy_search_ranking_saturation_point_calibration_search_term` (Saturation Point Calibration), `spy_search_ranking_query` +
`spy_search_ranking_query_rating` (the SRP rating widget), `spy_search_ranking_evaluation` (rank
evaluation), `spy_search_ranking_weight_checkpoint` (weight checkpoints),
`spy_search_ranking_auto_tune_metric_config` (auto-tune), and `spy_search_ranking_optimizer_run`
(automated weight optimization). Generate transfers and install the schema:

```bash
vendor/bin/console transfer:generate
vendor/bin/console propel:install       # creates all eight tables + builds ORM classes
vendor/bin/console router:cache:warm-up:backoffice
```

If you wired the Yves widget (step 3b), also warm up the **BackendGateway** router — its cache is separate
from the Backoffice one above and is not covered by it, so a fresh install of just this package's Gateway
controller will 404 with "No route found" until this runs too:

```bash
vendor/bin/console router:cache:warm-up:backend-gateway
```

### 7. Schedule the calibration, auto-tune, and optimize crons

E.g. in `Pyz\Zed\SymfonyScheduler\SymfonySchedulerConfig::getCronJobs()`:

```php
'search-ranking-optimizer-calibrate' => [
    'command' => '$PHP_BIN vendor/bin/console search-ranking-optimizer:calibrate',
    'schedule' => '*/5 * * * *',
],
'search-ranking-optimizer-auto-tune' => [
    'command' => '$PHP_BIN vendor/bin/console search-ranking-optimizer:auto-tune',
    'schedule' => '0 6 1 * *',
],
'search-ranking-optimizer-optimize' => [
    'command' => '$PHP_BIN vendor/bin/console search-ranking-optimizer:optimize',
    'schedule' => '*/5 * * * *',
],
```

All three commands are safe no-ops when there's nothing to do (no `uploaded` calibration run; no metric
with an auto-tune threshold set; no queued optimization run), so it's fine to leave them scheduled. `0 6
1 * *` is once a month, the 1st at 06:00 — auto-tune is a drift-detection job, not something that needs
finer granularity. `search-ranking-optimizer:optimize` processes at most one queued run per tick (the
oldest queued run, FIFO); the [Zed page](#automated-weight-optimization--searching-relevanceweight-and-metric-weights-algorithmically)
already processes a run in-request when you click "Run now", so the cron only matters for runs queued some
other way.

Nothing registers a cron job for you: `SymfonySchedulerConfig::getCronJobs()` returns `[]` in Spryker core
and has no plugin stack, so a package cannot contribute an entry even in principle — this is project config
by design, for every package, not just this one. Because skipping it produces no error (just a queued run
that sits in `queued` forever), the [next step](#8-verify-the-installation) verifies these registrations for
you. If your project schedules jobs some other way than `spryker/symfony-scheduler`, that check degrades to
a warning listing what to confirm by hand rather than failing.

### 8. Verify the installation

```bash
vendor/bin/console search-ranking-optimizer:check-installation
```

Most of the steps above fail *silently* when missed — a forgotten DependencyProvider wire-up produces no
error, just a feature that quietly never does anything (a permission nobody can ever be granted, a widget
button with no translated label). This command checks the core namespace registration, that every console
command from step 3 actually registered, that RateSearchRelevancePermissionPlugin (step 3a) is registered
on BOTH Zed and Client — either half missing independently makes the rating widget silently ungrantable or
invisible — that the Yves glossary key and Zed GUI translation catalog (step 5) both resolve, and that all
8 Propel tables this package ships (step 6) exist and are queryable. It exits non-zero and names the exact
remedy for whatever is wrong.

It also checks something no step above can create for you: whether the `search-score-admin` ACL role
([Requirements](#requirements)) actually resolves to anybody. Every way it can fail to is silent — a role
that was never created, a role no ACL group holds, or groups with no users in them all make the auto-tune
summary email go to zero recipients while the run still reports success, and the only surface is a console
line nobody reads, since that job runs under cron. This is reported as a **warning, not a failure**, and
only when at least one metric actually has "notify by email" enabled — the role is genuinely optional for a
shop that never turned notifications on, and the two unstaffed cases are distinguished from the
missing-role case because the remedy differs (staff the role vs. create it).

It also reports whether anybody other than a root-style admin can reach this package's Zed pages. Zed
access is deny-by-default outside a matching ACL rule, and a nav entry the current user has no rule for is
filtered out of the sidebar entirely rather than 403ing — so on a shop with real restricted back-office
roles, "nobody adjusted ACL" looks exactly like "the package was never installed". A default Spryker
install needs nothing done here (`root_role` holds a total wildcard), which is why this is a **warning at
most, never a failure**, and only when restricted roles exist and not one of them has a rule for this
package's module. Restricting these pages to root-style admins is a perfectly ordinary choice; the command
cannot know which roles you meant to grant, so it asks you to confirm rather than telling you to fix.

It is explicit about its own blind spots: running in Zed, it cannot confirm the Yves-side route-provider
and Twig plugin registration (step 3b) is in place, or that the rating widget actually renders below
product tiles and submits successfully on a live storefront page — those need a real browser request, not
a CLI probe.

## Calling `Client\Catalog`/`Client\Search` from Zed or console (optional)

This package's own `SaturationPointCalibrationSearcher`/`RankEvalRunner` don't call `Client\Catalog`/
`Client\Search` at all — they fire a raw `Elastica` query directly, deliberately bypassing this problem
(see [Limitations](#limitations)). This section is for a different, broader need: if *your own* project
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

- [`docs/examples/StoreQueryExpanderPlugin.php`](docs/examples/StoreQueryExpanderPlugin.php)
- [`docs/examples/LocalizedQueryExpanderPlugin.php`](docs/examples/LocalizedQueryExpanderPlugin.php)

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
expanders — see [Limitations](#limitations)) actually skews your own calibration/rank_eval results; for
most shops the bypass's statistical shape is close enough that this isn't worth the parallel implementation
to maintain.

## Modules

- **`SearchRankingOptimizer`** (Client/Zed/Shared) — the calibration, rank_eval evaluation, weight
  checkpoint/rollback, monthly auto-tune, and automated weight optimization business logic, persistence,
  console commands, Zed GUI (Saturation Point Calibration + Apply, Assess Rated Queries listing/edit-importance, Test Current Evaluation, Weight
  Checkpoints, Auto-tune metrics settings, and Automated Weight Optimization + Apply controllers), the raw-Elastica search
  components (shared query builder, saturation point calibration searcher, rank_eval runner), the rated-query data model,
  the simplex softmax reparametrization bridging this package's own weight-simplex constraint to the
  generic optimizer (see below), and the Zed Gateway endpoint that persists a rating.
- **`SearchRankingOptimizerWidget`** (Yves) — the SRP heart/check/X rating widget: controller, router/twig
  plugins, and the TypeScript/SCSS component itself.

## Limitations

- **CMA-ES has no restart strategy, so a single run can converge to a real but low-quality local optimum.**
  Measured on the metric-weight ground truth suite (`tests/SprykerCommunityTest/GroundTruth/SearchRankingOptimizer/`):
  a clear-cut, unambiguous ground truth was only correctly discovered by ~10% of independent runs (2/20 in
  one measured batch), because the algorithm's own early-termination criteria (TolX/TolFun, see
  `andrebarthelmeshellmuth/blackbox-optimizer`'s `CmaEsAlgorithm`) converge fast on a low-dimensional,
  discretely multi-modal `rank_eval` objective and then stop, wherever that landed. A production run is a
  single such attempt, with the same odds. **Intend to fix this soonish** with a restart-on-plateau strategy
  (IPOP-CMA-ES style: on early termination, restart from a fresh point, optionally with a larger population,
  keep the best across restarts) in the algorithm itself, so a single run becomes reliable without the
  caller needing to work around it. Until then, the ground truth test compensates by taking the best of many
  repeated runs (see `RelevanceWeightAndMetricWeightGroundTruthTest::METRIC_WEIGHT_REPEAT_COUNT`) — a
  test-only workaround, not a fix for real "Run now" usage.
- **Optimization runs are local search, not global search.** `relevanceWeight` is bounded to a trust region
  around its current live value (`±0.15` by default) specifically so one run can't propose something wild
  and untested in a single shot — but that means a systematically wrong starting point is never escaped in
  one run; it takes several runs, each re-centering on the previous result, to move far.
- **One run optimizes exactly one judgment set — there is no train/holdout split.** The rank_eval score an
  optimization run maximizes is computed against the SAME rated queries it's scored against; nothing here
  holds out a subset to check the winning weights generalize rather than merely fit that particular set of
  judgments especially well. A judgment set that is small, or skewed toward a handful of heavily-rated
  terms, can produce weights that look like a real improvement on paper without actually generalizing to
  queries no customer has rated yet.
- **One store/locale per run.** Weights found optimal for one store/locale combination are never
  cross-checked against any other; a shop running several stores needs a separate run (and separate
  judgment set) per combination it cares about.
- **Candidate evaluation is serial, not parallel**, within a single run — every shipped algorithm evaluates
  one candidate configuration at a time in the same PHP process ("Run now" in the Zed page or the
  `optimize` console command), even though every candidate in a generation is independent and could in
  principle be evaluated concurrently. A run's total wall-clock time is roughly
  `population size × generations × one rank_eval call's own cost`, so it scales directly with both the
  algorithm's own settings and the judgment set's size.
- **Population/generation counts are tuned for this demoshop's own judgment-set size**, not for a
  production-scale one. A shop with hundreds or thousands of rated queries would need to retune
  `SearchRankingOptimizerConfig::getOptimizationMaxGenerations()` and each algorithm's own population size
  — and, given the previous point, budget proportionally more wall-clock time per run.
- **Saturation Point Calibration and rank_eval both search against a deliberately narrowed live query, not the full one.**
  `LiveCatalogSearchQueryBuilder` reproduces the CORE catalog search-string query shape (base full-text
  query + store/locale/is_active/is_active_in_date_range filters) — real customer-facing search may layer
  further scope narrowing on top of that (customer-group visibility, price-list scoping, pinned category/
  facet filters, any other project-registered query expander), none of which is reproduced here. This is
  an accepted tradeoff, not an oversight: both features exist to approximate *relative* relevance ordering
  for tuning purposes, and closer parity would mean executing the real query expander stack from a Zed/
  console process, which — like `Client\Catalog`/`Client\Search` themselves — isn't reliably possible
  outside a real Yves request context in this shop (see the raw-Elastica-bypass reasoning documented on
  `SaturationPointCalibrationSearcher`/`RankEvalRunner`).

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

**322 tests, 1100 assertions** across three Codeception suites (`Zed/SearchRankingOptimizer`,
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
specificity weighting is force-enabled (it's off by default — see [above](#automated-weight-optimization--searching-relevanceweight-and-metric-weights-algorithmically)),
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
  Checkpoints](#automated-weight-optimization--searching-relevanceweight-and-metric-weights-algorithmically)) —
  so test order never matters and the suite leaves the environment exactly as it found it.
- `tests/SprykerCommunityTest/Yves/SearchRankingOptimizerWidgetPresentation/` (9 tests) — the SRP
  heart/check/X rating widget: renders, colorizes, persists across reload (which only holds once the SRP
  template feeds `activeRatingType` back in — see [3b](#3b-register-the-yves-widget-plugins)), only one
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
verification in [Status](#status) instead of a unit test.

### Static analysis

Static analysis (`phpstan`, level 8) runs in two variants:

- **`composer phpstan-ci`** (config [`phpstan.ci.neon`](phpstan.ci.neon)) — what CI runs on every push,
  standalone. Same transfer/index-map generation recipe as the `Portable` test subset above, and treats two
  categories of class as out of scope rather than faking them: Propel's generated `Orm\Zed\*\Persistence\*`
  entity/query/map classes (need a real schema + database, via `propel:model:build`) and the aggregated
  `Generated\{Zed,Yves,Client,Service}\Ide\AutoCompletion` stub (an aggregate across every module in a real
  project's full dependency graph, via `console dev:ide-auto-completion:generate`).
- **`composer phpstan`** (config [`phpstan.neon`](phpstan.neon), zero errors across all 151 files) — the
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

## License

MIT — see [LICENSE](LICENSE).

## Acknowledgements

Search Ranking Optimizer is an original project, but it reflects more than a decade of building search
solutions for e-commerce. Along the way, I had the privilege of working with engineers whose ideas and
experience shaped my approach to search engineering.

I'd particularly like to thank:

- **Martin Loetsch** — for the architectural ideas behind Contorion's early search platform.
- **Krešimir Slugan** — who handed over Contorion's search implementation to me and demonstrated an
  uncompromising focus on performance.
- **Alberto Reyer** (formerly Assmann) — for sharing the history and rationale behind Spryker Search's
  original design decisions and the engineering trade-offs behind them.

I'd also like to acknowledge the Spryker engineering team for creating an extensible platform that made
community packages like Search Ranking Optimizer possible.

The CMA-ES implementation this package's automated weight optimization depends on credits
**Nikolaus Hansen** directly — see
[andrebarthelmeshellmuth/blackbox-optimizer](https://github.com/andrebarthelmeshellmuth/blackbox-optimizer)'s
own Acknowledgements, since that's where the actual port now lives.

Any mistakes, questionable design decisions or bugs in this project are, of course, entirely my own.
