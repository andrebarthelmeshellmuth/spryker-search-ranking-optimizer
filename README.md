# Spryker Search Ranking Optimizer

Deciding *what* the ranking weights should be, on top of
[spryker-community/search-ranking](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking),
which provides the mechanism to *use* them (business-signal metrics, formulas, `function_score` ranking,
manual weight/parameter editing).

## Status

Early scaffold — nothing built yet. This package exists to hold the tuning layer that used to be planned
as part of search-ranking itself, split out because it's a different install commitment (a data-science-
adjacent workflow, not a "plug in a few business signals" one) and a different maturity level (still being
actively designed, where search-ranking's own mechanism is stable, tested, v1.0).

## Relationship to search-ranking

A real, one-directional dependency: this package requires `spryker-community/search-ranking` and writes
into its tables (metric weights, settings) via its facade — search-ranking has no knowledge of this
package and works completely standalone without it.

## Planned scope

- **SRP weight-slider live preview** — an admin-only panel on the storefront search results page: one
  slider per metric plus the relevance/business blend weight, live client-side re-ranking of a buffered
  result set, and a "fetch with these settings" button for a real, verified re-rank.
- **Tier-2/tier-3 propose → review → apply workflow** — admins submit weight proposals against a search
  term they tuned against; a reviewer checks/unchecks proposals and applies a learn-rate blend, with the
  result saved as a named, restorable checkpoint.
- **Offline relevance evaluation** — judgment capture (rate products relevant/irrelevant for a query,
  directly on the live SRP) plus a `_rank_eval` (nDCG) scoring pass, so a tuning change can be measured
  against a real objective instead of judged by eye.
- **Monthly auto-tune job** — per metric, checks whether its live formula still fits the data well; on a
  drop below a configurable threshold, proposes (or, if enabled, applies) a refit and notifies the
  configured admins. search-ranking already carries the config fields (threshold, notify toggle) and the
  ACL role this job will read — see its own README's roadmap section.
- **Automated weight search** — once evaluation exists, search θ (the blend weight plus per-metric
  weights) against the judgment set algorithmically rather than only via human-submitted proposals.

## License

MIT — see [LICENSE](LICENSE).
