# Spryker Search Ranking Optimizer

[![CI](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking-optimizer/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking-optimizer/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4)](composer.json)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-2a6b2a)](phpstan.neon)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

Deciding *what* the ranking weights and parameters should be, on top of
[spryker-community/search-ranking](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking),
which provides the mechanism to *use* them (business-signal metrics, formulas, `function_score` ranking,
manual weight/parameter editing).

This package is a real, one-directional dependent of `search-ranking`: it reads and writes that package's
tuning parameters through that package's own facade. `search-ranking` has no knowledge of this package and
installs and runs completely standalone without it (see [Relationship to search-ranking](docs/architecture.md#relationship-to-search-ranking)).

*Part of the [Search Relevance](https://search-relevance.dev/) project — explore the interactive ranking-formula walkthrough there.*

> **Not an official Spryker project.** `spryker-community/*` is an independent, community-built
> package namespace with no affiliation to, sponsorship by, or endorsement from Spryker Systems GmbH.
> The name describes what these packages are (community contributions for Spryker Commerce OS), not who
> maintains them. The matching Packagist namespace is held by an unrelated GitHub organization, which is
> why installation goes through a VCS repository entry rather than a plain `composer require` — see
> [Installation](#installation).

## Contents

- [Status](#status)
- [Before you start: this needs real relevance ratings](#before-you-start-this-needs-real-relevance-ratings)
- [What it does today](#what-it-does-today)
- [Requirements](#requirements)
- [Installation](#installation)
- [Modules](#modules)
- [Limitations](#limitations)
- [License](#license)
- [Acknowledgements](#acknowledgements)
- [Documentation](#documentation)

## Status

**Saturation Point Calibration, the SRP relevance-rating widget, the Zed Assess Rated Queries page, offline `rank_eval`
evaluation, weight checkpoint/rollback, the monthly auto-tune job, and automated weight optimization
(CMA-ES, the Rechenberg/Schwefel ES, or differential evolution against the rank-evaluation score, including
`search-ranking`'s specificity-aware relevance weighting knobs) are all built, tested, and shipping.**

Verified live end-to-end in a real browser (not just the automated test suite — see
[Testing and CI](docs/testing.md) for why that alone wouldn't have been enough): a customer clicks a rating
button on the storefront, the judgment round-trips through the Yves→Zed gateway with a server-side
permission re-check, and lands correctly in the database.

![The SRP relevance-rating widget: heart/check/X buttons below each product tile](docs/screenshots/srp-rating-widget.png)

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

![The Saturation Point Calibration page: the current live saturation point (k) for both signals — "no calibration run has finished yet" until the first one calculates — and a form to start a new run against a chosen calibration type/store/locale, sampling either organically rated search terms or an uploaded CSV](docs/screenshots/saturation-point-calibration.png)

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

![The Test Current Evaluation page: a store/locale picker with an "Evaluate now" button, the latest weighted nDCG@10 result, and a history of past evaluations for that store/locale](docs/screenshots/test-current-evaluation.png)

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

![The Weight Checkpoints page: the current live relevanceWeight, specificity knobs, and per-metric weights, a "Take checkpoint now" button, and a history of past checkpoints each with its own Restore action](docs/screenshots/weight-checkpoints.png)

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

![The Auto-Tune Settings page: one row per active metric, showing its current fit (R²) and its own threshold/auto-update/auto-update-scope/notify-by-email settings](docs/screenshots/auto-tune-settings.png)

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
arbitrary generation-count guess. As of `blackbox-optimizer` 4.2, there's also `RestartingOptimizerDecorator`:
when a run stops early on a genuine fitness plateau (not converged, not diverged — just stuck), it restarts
from a fresh random point with a **doubled** population rather than accepting whichever local optimum that
one random initialization happened to land in. And as of `blackbox-optimizer` 5.0, the two compose:
`RestartingOptimizerDecorator::trustRestartBudget()` gives every restart the same generous
safety-ceiling-sized room `trustTerminationCriteria()` gives a single run, instead of a shrinking
`maxGenerations` share.

That's three independent library-level capabilities, but only four of the eight boolean combinations they'd
naively suggest are actually meaningful (`trustTerminationCriteria()` only exists on a plain algorithm,
`trustRestartBudget()` only exists once you've already wrapped it in `RestartingOptimizerDecorator`).
Rather than expose three checkboxes and reject the other four combinations at runtime, the **Automated
Weight Optimization** form carries a single **Termination mode** choice, and `OptimizationRunner` passes
the chosen mode straight through to `AlgorithmFactory::create()`, which `match`es it to the right call
sequence:

- **Fixed budget** (the default) — a run stops at the fixed `getOptimizationMaxGenerations()` budget (150),
  which is predictable and bounds how long a run can take.
- **Trusted single run** — the generation cap is replaced by the algorithm's own convergence test, so a run
  stops when it stops improving rather than when it runs out of budget: better optima on problems that need
  more generations, and less wasted work on ones that converge early, at the cost of a run whose length you
  cannot predict up front.
- **Restart on plateau** — wraps the algorithm in `RestartingOptimizerDecorator`. On a genuine plateau, it
  restarts from a fresh point with a doubled population, within the exact same total evaluation budget the
  run already had (`populationSize * maxGenerations`) — never more evaluations, just spent differently
  across restarts instead of one longer run.
- **Restart on plateau, trusted budget** — the same restart mechanism, but every restart also gets
  `trustRestartBudget()`'s generous safety-ceiling-sized room instead of a shrinking share of the fixed
  budget. In practice a restart that also plateaus still exits in roughly the same generation count as the
  original run — the real effect is mostly removing the *artificial* truncation where the shrinking
  fixed-budget share would otherwise cut a restart off before it reached its own natural plateau. Actually
  burning a large share of the safety ceiling needs a restart landing in a genuinely slow-converging
  regime — a different fitness shape than "plateau again" — so the much larger worst-case total this mode
  allows for is a safety margin, not the typical realized cost.

This exists because CMA-ES's own early-termination criteria (see above) converge fast on this package's
low-dimensional, discretely multi-modal `rank_eval` objective and then stop wherever that landed — a plain
fixed-budget run has real, measured odds of settling for a mediocre local optimum instead of the best one
available (see [Limitations](#limitations) below for the numbers). A run's own restart history (population
size, generations used, why it stopped, and its own best score per restart) is shown on the run detail page
once a restart-enabled run completes.

![The Automated Weight Optimization page: the latest run's baseline vs. winning nDCG@10 score, the winning relevanceWeight and per-metric weights, a restart-on-plateau run's own restart history (population/generations/why it stopped/best score per restart), when it was applied, and a form to queue a new run against a chosen store/locale/algorithm/termination mode](docs/screenshots/automated-weight-optimization.png)

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

## Requirements

- PHP >= 8.3
- Spryker (kernel/gui/catalog/store/locale/propel-orm/search-elasticsearch/permission/permission-extension/
  company-user/acl/symfony-mailer — see `composer.json` for floors, verified by `composer check-floors`)
- A running Elasticsearch/OpenSearch catalog search (calibration fires real queries against it)
- **`spryker-community/search-ranking` installed and wired** — a real `require`; the Apply step writes
  into its `relevanceSaturationPoint`/`specificitySaturationPoint` settings via its facade, and the
  auto-tune job writes into its metric formulas the same way
- **`andrebarthelmeshellmuth/blackbox-optimizer`** — also a real `require` (`^4.1.0`); not on Packagist, so
  it needs the same repository-entry treatment as `search-ranking` below (see
  [Installation](#installation)). Provides the actual CMA-ES/Rechenberg-Schwefel-ES/Differential-Evolution
  algorithms the automated weight optimization feature searches with. The `4.1.0` floor is deliberate,
  not housekeeping: before it, Differential Evolution could stop early on a plateaued best value while
  its population was still spread out, ending a run at a materially worse optimum and silently reporting
  it as finished. Runs driven from here are affected directly, since they hand the algorithm a fixed
  generation budget and take whatever it returns. CMA-ES (the default) was never affected.
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

Not yet published on Packagist under the `spryker-community`/`andrebarthelmeshellmuth` vendor namespaces.
The `spryker-community` namespace and its GitHub org (`github.com/spryker-community`) are maintained by
Spryker's own community program — we're in contact with them about bringing these packages in properly
(their `dummy-module` template is the onboarding path). Until that lands, install from VCS repositories
instead. Its 2 real, non-Spryker `require`s (`spryker-community/search-ranking` and
`andrebarthelmeshellmuth/blackbox-optimizer`) need their own `vcs` repository entries too (skip whichever
you already have — `search-ranking`'s is often already present if it's separately installed):

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

- **A single run without restart-on-plateau can converge to a real but low-quality local optimum.**
  Measured on the metric-weight ground truth suite (`tests/SprykerCommunityTest/GroundTruth/SearchRankingOptimizer/`):
  a clear-cut, unambiguous ground truth was only correctly discovered by ~10% of independent runs (2/20 in
  one measured batch) WITHOUT restart-on-plateau, because CMA-ES's own early-termination criteria
  (TolX/TolFun, see `andrebarthelmeshellmuth/blackbox-optimizer`'s `CmaEsAlgorithm`) converge fast on a
  low-dimensional, discretely multi-modal `rank_eval` objective and then stop, wherever that landed. **Fixed**
  by enabling restart-on-plateau (see [Automated weight optimization](#automated-weight-optimization--searching-relevanceweight-and-metric-weights-algorithmically)
  above, and `andrebarthelmeshellmuth/blackbox-optimizer`'s own `RestartingOptimizerDecorator`): the SAME
  scenario, restart-on-plateau enabled, cleared the threshold on 20/20 individual runs across two independent
  10-run batches (`RelevanceWeightAndMetricWeightGroundTruthTest::testRestartOnPlateauRaisesSingleRunHitRateForMetricWeight()`).
  Restart-on-plateau is opt-in, not the default (mutually exclusive with "trust termination criteria" — see
  above), so a run queued without it still has the original ~10% single-run odds; enabling it is the fix, not
  automatic.
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

## Documentation

Reference material lives in [`docs/`](docs/) so this page stays focused on deciding whether to use
the package and getting it installed:

| Document | What's in it |
|---|---|
| [Architecture](docs/architecture.md) | How this package sits on top of search-ranking, and how one tuned store/locale fans out to the rest. |
| [Terminology](docs/terminology.md) | The vocabulary this package uses and how each term maps to the code. |
| [Calling Client\Catalog / Client\Search from Zed](docs/zed-search-access.md) | Why those clients fail outside an HTTP context, and the two supported ways around it. |
| [Testing and CI](docs/testing.md) | How this package is tested, which suites need a host shop, and what CI runs. |

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
