# Terminology

The vocabulary this package uses, and how each term maps to the code.

## Terminology

A quick reference for terms this README reuses across many sections — a lookup index, not a replacement
for the fuller explanation given where each is first introduced in context. `search-ranking`'s own README
has its own [Terminology](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking#terminology)
section for the terms it owns (metric, weight, relevanceWeight, relevanceSaturationPoint, digest, signal,
raw/normalized value) — not repeated here.

For the full store/locale scoping picture across every feature this package owns — including exactly which
ones fan a save out to sibling locales and which never do — see [SCOPING.md](../SCOPING.md); it builds on
`search-ranking`'s own [SCOPING.md](https://github.com/andrebarthelmeshellmuth/spryker-search-ranking/blob/main/SCOPING.md).

### rating / judgment

A customer's heart/check/X click on one product for one search term, captured by the SRP widget. The raw
material every other piece in this README is built on top of. See
[SRP relevance rating](../README.md#srp-relevance-rating--capturing-real-query-product-judgments).

### query (rated query)

One distinct (search term, store, locale) triple that has accumulated at least one rating —
`spy_search_ranking_query`'s own row, with an editable `importanceWeight` curators can use to make some
queries count more than others in the aggregate rank_eval score. See
[SRP relevance rating](../README.md#srp-relevance-rating--capturing-real-query-product-judgments).

### rank_eval score

The single number Elasticsearch/OpenSearch's `_rank_eval` API returns for a set of rated queries against a
given ranking configuration — an nDCG-style, importance-weighted aggregate. The objective function every
other piece here (calibration excluded) is ultimately trying to move. See
[Rank evaluation](../README.md#rank-evaluation--a-real-objective-score-not-averaged-opinion).

### weight checkpoint

A snapshot of every tunable weight (`relevanceWeight` + every metric weight) taken automatically before an
apply action changes them — the "undo" a human or an automated run can restore. See
[Weight checkpoints](../README.md#weight-checkpoints--a-way-back-before-changing-anything-by-hand).

### z-space / softmax reparametrization

The unconstrained real-valued vector an optimization algorithm actually searches in, and the transform
(`SimplexSoftmaxReparametrization`) that converts it to and from a valid, sum-to-one set of metric weights.
Never surfaced in the GUI — an implementation detail of
[automated weight optimization](../README.md#automated-weight-optimization--searching-relevanceweight-and-metric-weights-algorithmically).

### trust region

The bounded neighborhood around the live `relevanceWeight` (`±0.15` by default) an optimization run is
allowed to search within, so one run can't propose a wildly untested value in a single shot. See
[automated weight optimization](../README.md#automated-weight-optimization--searching-relevanceweight-and-metric-weights-algorithmically).
