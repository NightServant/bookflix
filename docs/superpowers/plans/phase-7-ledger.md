# Phase 7 execution ledger (append-only)

Plan: `docs/superpowers/plans/2026-10-23-phase-7-nlp-semantic.md` on the plans branch (PR #3), read with its "Revision 2026-11-11", which wins where they disagree. Branch: `phase-7-semantic`, cut from `main` at `d649bfd`. The architect owns this file; workers report their rows.

## Conflict scan (2026-11-11, against `d649bfd`)
- **`MlClient`:** has `health`, `models`, `startJob`, `job` only. Its timeout is cast to int, so 0.3 s would become 0. `models()` returns no revision.
- **Versions:** Python creates `training` versions, finishes them as `candidate` and promotes; the unique index is one active version per name; `version` is a timestamp with `unique(name, version)`. The plan's PHP-created `active` version and `"{model}@{revision}"` version string don't fit.
- **Jobs:** `JOBS` has 5 jobs; `RunMlJob::TRAIN_STEPS` and `PIPELINES` and the admin page labels must gain any new step; steps have a 2 h limit; `_prune` only knows `lsa`.
- **Search:** `HybridSearch` takes `KeywordLeg` and `SubjectLeg` by constructor (no legs list); `hasMore` is true when any leg returns 100; the box searches on a 300 ms debounce; `$filters->forViewer($user)` carries the mature gate into `apply()`.
- **Vocabulary:** `App\Catalog\Taxonomy\Vocabulary` exists; there is no `politics` or `philosophy` theme (there is `political-intrigue`, `dark-fantasy`, `existentialism`); Dewey 900 is "History & Places"; `SearchFilters.deweyCode` is one string; chips and removal already exist in `FilteredPage`; `search_queries.intent` exists but is never written.
- **Summaries:** `media_features.summary` exists but its rows are keyed by LSA version and pruned weekly; there is no creator page; story and series pages already clamp long text with `<x-clamped-text>`.
- **Recommendations:** `HybridRanker` already reads `embedding` neighbors; `ContentSimilarSource` reads `lsa` only; a new source must join `CandidatePool::PERSONALIZED`.
- **Classification:** unknown codes, manual and decided rows are already protected; `DeweyValidator` ranks by confidence, so a classifier score above 0.85 would become primary; #28 removed literature guesses for prose with no genres and a classifier trained on accepted 813s would add them back.
- **Environment:** pgvector is 0.6 locally and 0.8 in CI; the newest migrations are `2026_11_09_*`; CI's `ml` job runs `uv sync --frozen`.

## Rulings (with the cost if wrong)
- **R1. The search query embedding is the one ML call in a web request** (300 ms timeout, cached 24 h, keyword-only on any failure, the leg skipped for 30 s after a failure). Under Herd this call crashes PHP-FPM, which is an environment bug the user checks under `php artisan serve`. *If wrong:* embed only from the cache and queue the misses; about a day in `SemanticLeg`.
- **R2. `sentence-transformers` and CPU torch are an optional `embed` extra**, installed only in the Docker image; tests use a fake embedder. *If wrong:* the real model is exercised only by an opt-in slow test, so a model-specific bug shows first on the user's machine.
- **R3. Python owns embeddings** (`embed-media`, incremental or full with promote-at-end); no per-import PHP job. *If wrong:* a new title is keyword-searchable at once but semantically searchable only after the hourly run; adding a per-import job later is additive.
- **R4. Summaries live in `media_summaries`, written by a Python batch job; no `/v1/summarize` endpoint.** *If wrong:* Phase 11 adds the endpoint (about half a day); nothing built here is thrown away.
- **R5. Classifier confidence is capped at 0.79 and the #28 rule applies to its output.** *If wrong:* fewer classifications go public automatically; lifting the cap is one config value.
- **R6. Hard DB rule, as in every phase:** no `migrate:fresh`, `db:wipe`, `migrate:refresh` or `--env=testing`. Each worker tests on its own database (`DB_DATABASE=bookflix_test_<task>`).

## Deferred
- `creators.bio_summary` (no creator page exists) and `series.description_summary` (the series page already clamps): not built.
- The parser does not fill tropes or demographics.
- Tuning `semantic_min_similarity` and the zero-result comparison need the real model; listed as a user step unless a cloud run proves possible.
- Docker image size (R11): no Docker in the cloud session; a user step.

## Tasks
| Task | Commits | Status | Deviations and open findings |
|---|---|---|---|
