# Store/locale scoping in search-ranking-optimizer

Every table this package owns carries a real `store_name`/`locale_name` pair — unlike `search-ranking`,
where a metric's `isLocaleScoped` flag can make formula/isActive/shape/weight genuinely store-wide, nothing
in this package has an equivalent "store-wide by default" behavior of its own. Every page, run, checkpoint,
and config row here is scoped to exactly the one (store, locale) it was created for, full stop — the only
place `isLocaleScoped` fan-out reasoning enters this package at all is when it reads or writes through
`search-ranking`'s own facade, which then applies *that* package's own fan-out rules on its own tables (see
[search-ranking's SCOPING.md](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking/blob/main/SCOPING.md)
for that mechanism). This file is the single place that answers "what scope is this feature at, and does it
fan out anywhere" for everything this package touches. [README.md](README.md) explains what each feature
*does*; this explains *where it lives and whether a save here ever touches a sibling scope*.

## Every feature, and its own scope

### 1. Saturation Point Calibration (`spy_search_ranking_saturation_point_calibration`)

**Scope:** store+locale, picked explicitly at upload time (no implicit "current store" in Zed). A run
samples one store/locale's own real search traffic (or an uploaded CSV) and produces one suggested `k` for
that scope alone.

**Fan-out: none, ever.** Applying a calibration run's suggested `k` writes into `search-ranking`'s own
`relevanceSaturationPoint`/`specificitySaturationPoint` setting for exactly the run's own (store, locale) —
these two settings have no `isLocaleScoped`-equivalent flag on `search-ranking`'s side at all (see that
package's own SCOPING.md, step 2/3: settings are always store+locale, no fan-out mechanism exists for them
full stop). A store with 3 real locales that all want a calibrated `k` needs 3 independent runs, one per
locale — there is no "calibrate once, apply everywhere" shortcut here, by design: an accurate `k` depends on
that locale's own real score distribution, which genuinely differs by language/vocabulary even when the
catalog is identical. The console command that calculates a run (`search-ranking-optimizer:calibrate`)
itself operates system-wide (picks up the newest `uploaded` run across every store/locale), but that's a
*scheduling* detail, not a scoping one — each run it processes still only ever affects its own single scope.

### 2. SRP relevance ratings (`spy_search_ranking_query`, `spy_search_ranking_query_rating`)

**Scope:** store+locale, at the query level — `spy_search_ranking_query` carries its own store/locale
columns, and every rating hangs off one query. A query typed in DE/de_DE and the "same" query typed in
DE/en_US are two entirely independent rows with independent ratings, even if the text is identical, since
buyer intent for the same string can genuinely differ by language/market.

**Fan-out: none.** Ratings only ever accumulate for the exact (query, store, locale) they were given
against. There is no mechanism to seed one locale's ratings from another's.

### 3. Rank evaluation (`spy_search_ranking_evaluation`)

**Scope:** store+locale, picked explicitly on the Test Current Evaluation page — derived entirely from
that scope's own rated queries (feature 2 above), so its scope is inherited, not independently chosen.

**Fan-out: none.** An evaluation score for one (store, locale) says nothing about another's — it's a
read-only aggregate over that scope's own ratings, never written elsewhere.

### 4. Weight checkpoints (`spy_search_ranking_weight_checkpoint`)

**Scope:** store+locale, both for what's captured (a snapshot of `search-ranking`'s live `relevanceWeight`,
metric weights, and specificity knobs *at that one scope*) and for Restore's target.

**Fan-out: at Restore time only, and only because `search-ranking` itself fans out.** A checkpoint itself
never fans out on save — "Take checkpoint now" writes exactly one row for the currently selected scope.
Restoring one, though, writes back through `search-ranking`'s own `saveMetricWeight()` — so if a checkpoint
includes a metric that's `isLocaleScoped=false` in the currently selected target store, that ONE metric's
weight fans out to every real locale of that store on restore, the same as any other edit to it would (see
`search-ranking`'s SCOPING.md step 5). This package doesn't introduce that fan-out; it inherits it, and
discloses it on the page before Restore is clicked (see README).

### 5. Auto-Tune config (`spy_search_ranking_auto_tune_metric_config`)

**Scope:** store+locale, one row per (metric, store, locale) — the same grain
`spy_search_ranking_metric_store_config` on `search-ranking`'s own side settled on.

**Fan-out: yes, mirroring `search-ranking`'s own `isLocaleScoped` flag exactly.**
`AutoTuneMetricConfigWriterInterface::save()` calls `search-ranking`'s own
`resolveEffectiveWeightLocales()` to decide the real footprint of a save — for a store-wide metric
(`isLocaleScoped=false`, the common case), saving the threshold/auto-update/notify settings at ANY one
locale fans them out to every real locale of that store; for a genuinely locale-scoped metric, only the one
locale saved is touched, and an un-configured sibling locale simply has no row (treated as opted out, same
"absence means opted out" contract `auto_tune_threshold=null` already has per-metric). This is the one place
in this package where a save's blast radius depends on a fact that lives entirely on `search-ranking`'s
side, not on anything local — see README's [Auto-tune](README.md#auto-tune--a-monthly-fit-quality-check-per-metric)
section for the full mechanics and the worked example below for what this means in practice.

### 6. Automated weight optimization (`spy_search_ranking_optimizer_run`)

**Scope:** store+locale, picked explicitly when a run is queued. The run's own rank-evaluation objective
(feature 3) is scoped the same way, so a run genuinely optimizes for one scope's own real traffic/ratings.

**Fan-out: at Apply time only, same shape as Weight checkpoints (feature 4).** Applying a run's winning
candidate writes `relevanceWeight`, metric weights, and specificity knobs through `search-ranking`'s own
facade for the run's own (store, locale) — any store-wide metric among those weights fans out to every real
locale of that store, the same inherited (not introduced) fan-out feature 4 has, disclosed the same way
before Apply is clicked.

## Quick reference

| Feature | Scope | Fans out on its own? |
|---|---|---|
| Saturation Point Calibration run | Store+locale | Never — no fan-out mechanism exists for `search-ranking`'s own k/settings at all |
| SRP relevance ratings | Store+locale (per query) | Never |
| Rank evaluation | Store+locale (inherited from ratings) | Never |
| Weight checkpoint (take) | Store+locale | Never |
| Weight checkpoint (restore) | Store+locale (target scope, independent of source) | Yes, for any store-wide (`isLocaleScoped=false`) metric among the checkpoint's own weights — inherited from `search-ranking` |
| Auto-Tune config | Store+locale | Yes, mirrors `search-ranking`'s own `isLocaleScoped` per metric exactly |
| Automated weight optimization (apply) | Store+locale | Yes, same as Weight checkpoint restore — inherited from `search-ranking` |

## Worked example, and its known exceptions

See the README's own [Bootstrapping one store/locale, then fanning out everywhere](README.md#bootstrapping-one-storelocale-then-fanning-out-everywhere)
section for the full walkthrough of using this package plus `search-ranking`'s Scope Copy/Lock to tune one
(store, locale) and roll it out — including the exceptions this file's own per-feature breakdown above
already implies: Saturation Point Calibration (feature 1) and SRP ratings/rank evaluation (features 2/3)
never fan out at all, no matter what `search-ranking`'s Scope Copy/Lock does on its own side.
