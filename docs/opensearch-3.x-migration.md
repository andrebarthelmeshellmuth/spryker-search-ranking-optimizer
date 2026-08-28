# Migrating to OpenSearch 3.x

Verified live end-to-end: a Spryker demoshop upgraded from **OpenSearch 1.3.4 to 3.5.0** (Lucene 10.3.2),
full re-export/reindex, Saturation Point Calibration and a rank evaluation run re-executed against the
live 3.5 cluster, `search-ranking-optimizer:check-installation` re-run.

**This package needs no code change for OpenSearch 3.x.**

## Why it carries across unchanged

This package builds no ranking query of its own. It reaches the engine through two long-stable surfaces:

- **`_rank_eval`** — the objective score behind Rank evaluation, Auto-tune and Automated weight
  optimization. Present and behaviour-stable on OpenSearch 1.3.4 → 3.5 and Elasticsearch 8.x; the
  `metric_score` per rated request and the nDCG computation are unchanged.
- **A reconstructed `function_score` / `script_score` (painless)** — rebuilt identically to what
  `spryker-community/search-ranking` applies live, so calibration and every evaluation run measure the
  real formula. Byte-identical `_score` across the same engine range (see search-ranking's own
  engine-compatibility verification).

## `evaluate-hybrid` and k-NN

`search-ranking-optimizer:evaluate-hybrid` is the one path that issues a raw `knn` query — and only when
you pass a non-`1.0` alpha, against a page index that carries a `knn_vector` field (the optional
semantic-blend feature). If you run that path on OpenSearch 3.x, the k-NN engine notes from
`spryker-community/search-ranking`'s migration guide apply: `nmslib` was removed in OpenSearch 3.0
(use `engine: lucene` or `faiss`), and `index.knn` is a static setting that breaks a re-run `search:setup`
against an existing index (add it to your project's
`SearchElasticsearchConfig::getStaticIndexSettings()`). With `alpha = 1.0` (the default), `evaluate-hybrid`
issues no `knn` query and none of this applies.

## Capability delta 1.3.x → 3.5

Probed directly against a live OpenSearch 1.3.14 and a live 3.5.0. The genuine additions are the `hybrid`
query (neural-search plugin, OpenSearch ≥ 2.10) and `_search/pipeline` (OpenSearch ≥ 2.8) — this package
uses neither. `_plugins/_ml` (ML Commons) was already present on 1.3.x; 3.x adds in-cluster model serving.
`pinned` and `_plugins/_ltr` are in neither stock image. `spryker-community/search-ranking`'s migration
guide has the full table and the neural-search `SemanticMappingTransformer` empty-`"properties"` trap that
any Spryker shop hits during the upgrade itself (independent of this package).
