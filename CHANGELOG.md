# Changelog

All notable changes to this package are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Each version below also has a [GitHub release](../../releases) with the fuller write-up.

## [Unreleased]

### Added
- `checkGlueApiWiring()` in `search-ranking-optimizer:check-installation` — warns (never fails) when
  `Generated\Api\Storefront\SearchRelevanceJudgmentsStorefrontResource` has not been generated
  (`glue api:generate storefront` not re-run → `POST /search-relevance-judgments` 404s).

### Documented
- `extra.dependency-pins` note on why `symfony/security-guard: 5.4.0-BETA1` must stay in `require`
  (a resolution pin, no `src/` usage).
- OpenSearch 3.5 compatibility note; new `docs/opensearch-3.x-migration.md`.

## [2.1.0] - 2026-08-27

### Added
- Glue API (API Platform): `search-relevance-judgments` storefront resource, with a
  Provider/Processor/Mapper trio following the same pattern as `search-feedback`'s own Glue resource.

### Fixed
- Declared 5 phantom dependencies found by a use-statement + FQN audit; declared `spryker/serializer`.
- Pinned `symfony/security-guard` to the one pre-release that supports Symfony 6.4/7 `security-core`,
  unblocking `spryker/api-platform` resolution.
- Standalone `phpstan-ci` now excludes `Generated\Api\*`.
- Applied Rector `IfToNullCoalescingAssignRector` (unpinned dev-tooling drift).

_PR #36 (rank_eval measurement harness: RRF fusion mode + intent-aware alpha) intentionally left
open — not part of this release._

## [2.0.0] - 2026-08-21

### Added
- Opt-in **restart-on-plateau** for Automated Weight Optimization: `RestartingOptimizerDecorator`
  (from `andrebarthelmeshellmuth/blackbox-optimizer ^5.0.0`) restarts a stuck run from a fresh random
  point with a doubled population, within the same evaluation budget.
- A single **Termination mode** choice on the run form, replacing 3 partially-invalid boolean
  checkboxes; per-restart history shown on the run detail page.

### Changed
- **Breaking:** `SearchRankingOptimizerFacadeInterface::queueOptimizationRun()` replaces its
  `bool $isTerminationCriteriaTrusted` / `bool $isRestartOnPlateauEnabled` / `bool $isRestartBudgetTrusted`
  parameters with a single `string $terminationMode`. `SearchRankingOptimizerRunTransfer` and the
  `spy_search_ranking_optimizer_run` table drop the 3 boolean columns for one `termination_mode`
  string — regenerate transfers, run `propel:diff` / `propel:migrate`.

## [1.1.2] - 2026-08-20

### Fixed
- `RankEvalRunner` always reported `nDCG@10 = 0.0` when the configured index name is an alias rather
  than a concrete index — `_rank_eval` matches ratings by `(_index, _id)` and a hit's `_index` is the
  concrete backing index, never the alias. Now resolves the alias once per evaluation call. No-op and
  no new dependency when not using an alias.

### Changed
- Restored README screenshots against a fictional "Feldwerk" demo catalog; retook the Zed screenshots
  tightly cropped.

## [1.1.1] - 2026-08-19

### Changed
- Maintenance / CI.

## [1.1.0] - 2026-08-18

### Added
- Demo-fixture apply script (`fixtures/apply.php`) granting the rating permission to a loggable-in
  customer and installing this package's Yves glossary strings.

### Changed
- Fixed install instructions and stale version references; removed product-screenshot images per the
  Demo Shop license; authenticated CI's composer downloads against `codeload.github.com` rate
  limiting; stopped committing Spryker's generated `PageIndexMap.php`.

## [1.0.2] - 2026-08-13

### Changed
- CI: `phpstan` level 8 gated via a standalone `composer phpstan-ci` variant; now a required check.

### Fixed
- Declared `spryker/company-user`, `spryker/permission`, `spryker/translator` in `require`
  (previously only satisfied transitively).

## [1.0.1] - 2026-08-13

### Changed
- CI: the Codeception "Portable" subset now runs standalone via a `tests/_ci-standalone` bootstrap.

### Fixed
- Dropped two redundant `(float)` casts (no behaviour change).

## [1.0.0] - 2026-08-12

First stable release — the tuning layer for `spryker-community/search-ranking` (deciding what the
ranking parameters *should be*). Requires `spryker-community/search-ranking ^2.2.0`.

### Added
- Automated weight optimization: queue a run, a blackbox optimizer (CMA-ES, Rechenberg–Schwefel ES, or
  Differential Evolution via `andrebarthelmeshellmuth/blackbox-optimizer`) searches the parameter
  space against rated queries and proposes a winner — always propose-only, applied through the same
  write-through-facade + checkpoint pipeline as any other weight change.
- Run parameter checklist (pin `relevanceWeight`, the 4 specificity knobs, or individual metric
  weights instead of searching them).
- Saturation Point Calibration; SRP relevance-rating widget; `rank_eval` evaluation with history;
  weight checkpoints; monthly auto-tune with optional auto-apply and a before/after summary email.
- Store/locale scoping throughout, matching the base package.
- `search-ranking-optimizer:check-installation` diagnostics, including the auto-tune notification role
  and back-office reachability (both fail silently otherwise).

## [0.9.1] - 2026-07-30

### Fixed
- All 12 findings from a full code review: RankEvalRunner probe-score cache leaked stale/wrong-locale
  scores across requests; `OptimizationApplier` is now atomic (checkpoint recorded in the same
  transaction as every weight write); relevance judgments are verified against a real current search
  result before persisting; `AutoTuneRunner` isolates per-metric failures; transactional calibration
  uploads; a find-or-create race in `ProductRelevanceJudgmentWriter`; `EvaluationForm` moved GET → POST
  with CSRF; sum-to-1 invariant fix; CSRF on the Yves rating widget endpoints; and more.
- Corrected an unsatisfiable `spryker-community/search-ranking` constraint (`^1.2.1` → `^1.2.0`) that
  was breaking `composer install` / CI.

## [0.9.0] - 2026-07-30

### Added
- First tagged release: O1–O6 — rated-query data model + SRP rating widget, calibration integration,
  `rank_eval` evaluation, weight checkpoint/rollback, monthly auto-tune job, and automated weight
  optimization (CMA-ES / Differential Evolution, extracted into
  `andrebarthelmeshellmuth/blackbox-optimizer`). Requires `spryker-community/search-ranking ^1.1`.

[Unreleased]: ../../compare/v2.1.0...HEAD
[2.1.0]: ../../releases/tag/v2.1.0
[2.0.0]: ../../releases/tag/v2.0.0
[1.1.2]: ../../releases/tag/v1.1.2
[1.1.1]: ../../releases/tag/v1.1.1
[1.1.0]: ../../releases/tag/v1.1.0
[1.0.2]: ../../releases/tag/v1.0.2
[1.0.1]: ../../releases/tag/v1.0.1
[1.0.0]: ../../releases/tag/v1.0.0
[0.9.1]: ../../releases/tag/v0.9.1
[0.9.0]: ../../releases/tag/v0.9.0
