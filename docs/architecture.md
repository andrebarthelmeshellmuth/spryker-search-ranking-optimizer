# Architecture

How this package sits on top of search-ranking, and how a single tuned store/locale fans out to the rest.

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
   [SCOPING.md](../SCOPING.md)) and Apply, so the relevance-score curve is on a sane footing before optimizing
   against it.
3. **Let the black-box optimizer tune `relevanceWeight` and every metric weight for that one scope** —
   queue an [Automated weight optimization](../README.md#automated-weight-optimization--searching-relevanceweight-and-metric-weights-algorithmically)
   run against `DE`/`de_DE`'s own real ratings, review the winning candidate against baseline, and Apply.
4. **Fan the result out via `search-ranking`'s own Scope Copy/Lock** — from `search-ranking`'s Scope Copy
   page, copy (or Lock, for an ongoing daily resync) `DE`/`de_DE`'s now-tuned weight, the 6 settings
   (`relevanceWeight`/`relevanceSaturationPoint`/4 specificity knobs), and formula/isActive/shape out to
   every other real store and locale. One Lock covers everything Scope Copy's combined action copies — see
   that package's own [SCOPING.md](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking/blob/main/SCOPING.md#quick-reference).

That's the whole loop: tune once for real, replicate everywhere else. It's not free of manual steps, though
— the known exceptions, all things Scope Copy/Lock simply cannot touch because they're not part of
`search-ranking`'s own copyable configuration at all:

- **Saturation Point Calibration itself doesn't fan out** (see [SCOPING.md](../SCOPING.md), feature 1) — a
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
