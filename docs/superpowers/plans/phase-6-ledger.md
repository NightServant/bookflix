# Phase 6 execution ledger (append-only)

Plan: `docs/superpowers/plans/2026-10-23-phase-6-recommendation-mvp.md` on the plans branch, read together with its "Revision 2026-11-04", which wins where they disagree. Branch: `phase-6-recommendations`, cut from `main` at `fa8a4b3`.

## Scope and gate
- Batch A: 6.0 (intelligence schema, model versions, `ml_job_runs`, `media_popularity`, enums, models, factories, seeder, config, `MlClient::startJob`/`job`, contract fixtures, `expected_columns.json`) and revision item 8 (CI `ml` job gets Postgres and `ML_TEST_DATABASE_URL`; a pytest `db` marker).
- Later tasks (6.1 to 6.10) are other batches. The UI part of 6.8 waits for the design gate (revision item 9).

## Rulings (with the cost if wrong)
- R1. Hard rule, as in Phases 2, 4 and 5: no `migrate:fresh`, `db:wipe`, `migrate:refresh` or `--env=testing`. Tests run with `env -u AI_AGENT -u CLAUDECODE composer test`.
- R2. Migrations are dated `2026_11_05_*` (revision item 7), not the plan's `2026_11_02_00000x`.

## Tasks
| Task | Commits | Status | Open findings |
|---|---|---|---|
| 6.0 | (see below) | DONE: `model_versions`, `media_features`, `media_lsa_vectors` (HNSW), `media_similarities`, `recommendations`, `recommendation_events`, `ml_job_runs`, the `media_popularity` materialized view, four enums, five models and factories, `ModelVersionSeeder` (in `DatabaseSeeder`), config weights, `MlClient::startJob`/`job`, `contract()` Pest helper, three contract fixtures, `ml/tests/expected_columns.json`. Revision item 8 done: the CI `ml` job has a `pgvector/pgvector:pg17` service and `ML_TEST_DATABASE_URL`; a pytest `db` marker is skipped when the variable is unset | F1: `scopeTrending` is untouched (6.3). F2: the view's `popularity_percentile` ranks `interactions_30d + media.ratings_count` as the plan says; 6.3 must not use it alone (revision item 4) |

### Accepted deviations (6.0)
- Enum-like columns are strings, not DB enums (like `user_interactions.event`), so later values (`popular_with_followed`, `llm`) need no migration. `ModelKind` has no `llm` yet (Phase 11).
- `ml_job_runs.started_at` defaults to the creation time (a queued run has no separate start); no timestamps columns.
- `media_similarities` has a CHECK that a media is never its own neighbor and an extra index on `similar_media_id` (cascade deletes).
- The seeder skips a name that already has any version, so a re-seed never resets edited or replaced weights. `interaction-weights` stores the rating formula as `rating_midpoint` 5.5 and `rating_scale` 1.2, plus `half_life_days` 90.
- `startJob` sends `{"params": {}}` (an object, never a list) and `MlClient` run ids are URL-encoded.
- The plan's test for `contract()` named `ml/tests/expected_columns.json` columns `published_on`, `alternative_titles` etc.; `merged_into_id` was added to `media` so Python can skip merged media like the view does.
- Local setup note: `public/build` is git-ignored, so a fresh worktree needs it copied from the main checkout before the full PHP suite passes. `composer test` also needs `COMPOSER_PROCESS_TIMEOUT=0` here: the suite takes over 300 s.
