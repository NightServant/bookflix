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
| 6.0 | `93d4883` | DONE: `model_versions`, `media_features`, `media_lsa_vectors` (HNSW), `media_similarities`, `recommendations`, `recommendation_events`, `ml_job_runs`, the `media_popularity` materialized view, four enums, five models and factories, `ModelVersionSeeder` (in `DatabaseSeeder`), config weights, `MlClient::startJob`/`job`, `contract()` Pest helper, three contract fixtures, `ml/tests/expected_columns.json`. Revision item 8 done: the CI `ml` job has a `pgvector/pgvector:pg17` service and `ML_TEST_DATABASE_URL`; a pytest `db` marker is skipped when the variable is unset | F1: `scopeTrending` is untouched (6.3). F2: the view's `popularity_percentile` ranks `interactions_30d + media.ratings_count` as the plan says; 6.3 must not use it alone (revision item 4) |
| 6.1 | `49ba22a` | DONE: `preprocess`, `lsa` (TF-IDF + TruncatedSVD, zero-padded to 200, L2-normalized), `registry`, `db`, `artifacts` (S3 only when `ARTIFACT_BUCKET` is set, boto3 imported lazily; else `ARTIFACT_DIR`), `jobs` (`JobRunner`, `JOBS`, `train-lsa`), `POST /v1/jobs/{name}` and `GET /v1/jobs/{run_id}`, `tests/schema.sql`, corpus fixture. The `train-lsa` run commits a `training` version first, then writes vectors, features and the `candidate` status in one transaction (chunks of 1000); any failure rolls back and leaves a `retired` version with `metrics.error`. The active `lsa` version is never touched | F3: `build-similarities`, `refresh-popularity`, `evaluate` are not in `JOBS` yet, so Laravel gets 404 for them until 6.3/6.5. F4: a failed run leaves its artifact file behind (orphan, harmless) |
| 6.2 | `9f1a1bd` | DONE: `item_cf.interaction_scores`/`item_neighbors`, `db.fetch_interactions` (active, personalization on or no profile, media live and not merged), `db.fetch_interaction_weights`, `train-item-cf` job (candidate `item-cf` version, joblib neighbors; `media_similarities` is written in 6.3). Review Focus #2 covered by db tests: deactivated, opted-out, and pre-opt-out interactions never reach training | F5: `media_popularity` and the export share the reader filter by copy, not by a shared object; 6.3 must keep them in step |
| 6.3 | HASH | DONE: `similarities.top_k_cosine`/`write`, `build-similarities` (lsa from the newest candidate-or-active `lsa` version's stored vectors, item_cf from that version's artifact; a method with no version is skipped, neighbors of non-live media dropped) and `refresh-popularity` (autocommit for `refresh ... concurrently`) registered in `JOBS`; new migration `2026_11_05_000004` adds `trending_percentile` to `media_popularity`; `Media::scopeTrending` = `popularity_score + trending_weight × trending_percentile` (left join), `bookflix.recommendations.trending_weight` 0.05; `TrendingScopeTest` (7 tests). No existing test expectation changed: HomeShelves, OnboardingPicks, LandingShowcase and PopularityScorerTest keep their order (zero interactions = today's order) | F6: `media_similarities` has no version in its primary key, so `write` upserts and a pair stored by another version of the same method is taken over by the newer one; rows of a pair the new version no longer has stay until their version is removed. F7: the view and `ml/tests/schema.sql` carry the same definition, change both. The Python reader filter was not touched |

### Accepted deviations (6.0)
- Enum-like columns are strings, not DB enums (like `user_interactions.event`), so later values (`popular_with_followed`, `llm`) need no migration. `ModelKind` has no `llm` yet (Phase 11).
- `ml_job_runs.started_at` defaults to the creation time (a queued run has no separate start); no timestamps columns.
- `media_similarities` has a CHECK that a media is never its own neighbor and an extra index on `similar_media_id` (cascade deletes).
- The seeder skips a name that already has any version, so a re-seed never resets edited or replaced weights. `interaction-weights` stores the rating formula as `rating_midpoint` 5.5 and `rating_scale` 1.2, plus `half_life_days` 90.
- `startJob` sends `{"params": {}}` (an object, never a list) and `MlClient` run ids are URL-encoded.
- The plan's test for `contract()` named `ml/tests/expected_columns.json` columns `published_on`, `alternative_titles` etc.; `merged_into_id` was added to `media` so Python can skip merged media like the view does.
- Local setup note: `public/build` is git-ignored, so a fresh worktree needs it copied from the main checkout before the full PHP suite passes. `composer test` also needs `COMPOSER_PROCESS_TIMEOUT=0` here: the suite takes over 300 s.

### Accepted deviations (6.1, 6.2)
- Ruff's `E501` is ignored in `ml/pyproject.toml` (SQL strings and docstrings); `ruff format` still wraps code. mypy now checks `bookflix_ml` too, with `ignore_missing_imports` for sklearn, implicit, scipy, joblib, boto3 and pgvector (no stub packages added).
- `implicit.nearest_neighbours.bm25_weight` gives an item read by most users an idf of 0 and then NaN similarities on small data, so `item_cf` applies its own BM25 (same K1 and B, idf `log(1 + (N - df + 0.5) / (df + 0.5))`) before `CosineRecommender`.
- The interaction weights use the keys 6.0 stored (`rating_midpoint`, `rating_scale`, `reading_progress` 0.05 per point, `half_life_days`), not the plan's `reading_progress_per_percent`/`rating_center`; `DEFAULT_WEIGHTS` mirrors the config and fills any missing key.
- `train_lsa` retries with `min_df=1, max_df=1.0` when the plan's `min_df=2, max_df=0.6` leaves no vocabulary (two-document corpora); no usable text raises `NotEnoughData`.
- `JobRunner(inline=True)` exists for tests; `JOBS` holds only registered names, so a name the plan lists but later tasks add answers 404.
- Version strings are UTC timestamps with microseconds (`20261104T120000123456Z`); `registry.*` never commits, the job decides the transaction. `ml/Dockerfile` now copies `bookflix_ml`. No PHP changes.

### Accepted deviations (6.3)
- Revision item 4 overrides the plan's ordering. The blend is `popularity_score + w × trending_percentile`, `w` = 0.05 (config), with `trending_percentile` the `cume_dist()` (not `percent_rank()`) of `trending_score` among media with interactions in the last 30 days, 0 for the rest: `percent_rank` gives the least active of them 0, the same as no activity, so a single view would not count. Tie-breakers stay `ratings_count`, `published_on`, `id`.
- The percentile is a new column of the view (new migration that recreates it) rather than a window over all media per request. The scope adds no `select`, so models loaded through it carry the view's columns as extra attributes.
- `build-similarities` takes the item_cf neighbors from the stored `item-cf` artifact instead of recomputing from `fetch_interactions`.
