# Phase 6 — Recommendation MVP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A personalized, explainable home: offline Python jobs (TF-IDF/LSA, item-CF, similarities, popularity, evaluation) feed Postgres; Laravel ranks precomputed candidates per request with hybrid scoring, diversity and exploration, and logs what it showed. This completes the MVP (master plan A17 exit test).

**Architecture:** Master plan A8/A11/A12. Python (`ml/`) trains and writes `media_lsa_vectors`, `media_similarities`, `model_versions` straight to Postgres via `DATABASE_URL` (Neon direct URL) and refreshes `media_popularity`; Laravel starts jobs through `MlClient`, tracks them in `ml_job_runs`, and online ranks `CandidateSource` output with `HybridRanker` → `Diversifier` → `ShelfBuilder`, cached 10 min per user.

**Tech Stack:** Python 3.12, FastAPI, scikit-learn, NumPy, SciPy, pandas, `implicit`, psycopg[binary], pgvector, joblib, boto3; pytest, ruff, mypy; Laravel 13, Pest on Postgres, Livewire 4.

**Spec:** `/Users/gabe/Documents/Bookflix Documentation/Bookflix_Specification.md` §22–§24, §27–§29, §31–§35, §45, §63. **Master plan:** A6 intelligence block, A7, A8, A11, A12, D-15, Part E Phase 6.

> **Before starting:** `backend-architect` agent reviews A12 and this plan (master plan B4/B6). F4 personalized-home screens signed off (F6) before the UI part of 6.8.

**Depends on:** Phase 1 (`MlClient`, `MlServiceUnavailable`, `ml/` skeleton, `tests/Fixtures/ml-contracts/`, `User::active()`, `personalizationEnabled()`), Phase 2–3 (`Media`, `DeweyHierarchy`), Phase 4 (`Shelf`, `HomeShelves`, `Media::scopeTrending` interim, components `<x-shelf>`, `<x-cover-tile :reason>`), Phase 5 (`user_interactions`, `LibraryEntry`, `Rating`, `LibraryChanged`, `ContinueReading`, `UserPreference`, editorial `collections`).

## Global Constraints

- **Phase 1 as built (PR #4, authoritative):** `AssignRequestId` and `SetSecurityHeaders` are global middleware; `EnsureAccountIsActive` stays in the `web` group (new middleware is appended in `bootstrap/app.php` accordingly). Primary navigation is defined **once** in `config('bookflix.navigation')` (items `{label, route, active: [routeIs patterns], icon}`); `layouts/app/header.blade.php` and `components/bottom-nav.blade.php` loop over it, so a phase adds nav items to that config, never to the layouts (`primaryNavs()` in `tests/Pest.php` asserts them). `FILESYSTEM_DISK=local`; public-facing files use a dedicated disk setting like `config('bookflix.avatar_disk')`; **Livewire temporary uploads never use a public disk**. Red (Flux `primary`) is for CTAs only: Save/Update buttons in settings and admin use `variant="filled"`; focus rings use `accent-electric`. ML: `ML_SERVICE_TOKEN` ≥ 32 characters, docs endpoints off unless `ML_ENABLE_DOCS=true`, local `ML_SERVICE_URL=http://localhost:8001`, `MlClient` throws `MlServiceUnavailable` (and `health()` is false) when the token is empty. Deferred to deploy setup: `league/flysystem-aws-s3-v3`, Nightwatch, a shared temporary-upload disk. The spec is not in the repo (public repo): read it at the absolute path above.
- Expensive ML never runs in a user request (§61). The request path reads Postgres + cache only; ML calls happen in queued jobs.
- Implicit-feedback weights, in `model_versions` row `name = interaction-weights` (D-15): view 1 · click 1 · recommendation_click 1.5 · library_add 3 · reading_start 4 · reading_progress 0.5 per 10% (0.05 × percent-point `value`) · reading_complete 8 · favorite 6 · rating (score − 5.5) × 1.2 · review 4 · abandon −4 · search 0; decay half-life 90 days.
- Hybrid weights (row `hybrid-weights`): content 0.30, collaborative 0.25, dewey 0.10, popularity 0.10, freshness 0.05, preference 0.15, novelty 0.05, context 0. Every feature normalized to [0, 1]; missing feature = 0; final score in [0, 1].
- LSA: `TruncatedSVD(n_components=200, random_state=42)`, `TfidfVectorizer(sublinear_tf=True, min_df=2, max_df=0.6, ngram_range=(1, 2))`, title ×3, genres/themes ×2; CJK → character 2–3-grams; vectors L2-normalized; stored `vector(200)` + HNSW (`vector_cosine_ops`).
- Top 50 neighbors per method per item in `media_similarities`; neighbors never include the item itself.
- Training/export excludes deactivated users and users with `personalization_enabled = false` (left join on `profiles`; a missing profile row counts as enabled). Python loads only artifacts it wrote.
- Python uses `DATABASE_URL` (direct Neon URL, not the pooler); batches commit in chunks.
- Candidate promotion gate: promote only if NDCG@10 falls ≤ 2% (relative) and coverage does not fall vs the active version; no active version → promote.
- Explanations: "Because you read {title}", "Similar themes to {title}", "Popular with readers who enjoyed {title}", "Matches your interest in {genre}". No reason / missing placeholder → no label.
- Opted-out users get cold-start (non-personalized) shelves only and no `recommendations` rows (D-28).
- Shelf order and copy: master plan A12 table. Trending Now is the **only** gradient shelf. Home ≤ 25 queries.
- Python quality gate per task: `cd ml && uv run ruff check . && uv run mypy . && uv run pytest`; PHP gate: `composer test`.

## Review Focus

1. A user with zero or one interaction (or opted out) gets a non-empty, error-free home. → Task 6.8.
2. Deactivated and opted-out users never reach training/popularity data (including interactions recorded before they opted out). → Tasks 6.2, 6.3.
3. Candidates already in the user's library, dismissed, soft-deleted or from a non-active model version never appear. → Tasks 6.4, 6.8.
4. A failed/timed-out ML job doesn't change the active model: failed run recorded, no half-written vectors visible, no promotion. → Tasks 6.1, 6.9, 6.10.
5. Tiny/degenerate corpora and matrices (fewer than 200 features, empty descriptions, one user, all-zero popularity) train and rank without NaN/errors. → Tasks 6.1–6.3, 6.5.

## File structure

```text
ml/bookflix_ml/{preprocess,lsa,item_cf,similarities,popularity,evaluate,registry,db,artifacts,jobs}.py
ml/app/routers/jobs.py     ml/tests/{schema.sql,conftest additions,test_*.py,fixtures/*.json}
app/Discovery/Recommendations/{CandidateSource,Candidate,CandidatePool,SeedItems,RankingWeights,HybridRanker,RankedItem,Diversifier,Explanation,ExplorationInjector,ShelfBuilder,RecommendationLogger}.php
app/Discovery/Recommendations/Sources/{ContentSimilar,Collaborative,DeweySimilar,Trending,PopularInGenres,NewReleases,Editorial,Exploration}Source.php
app/Enums/{ReasonType,ModelKind,ModelStatus,RecommendationEventType}.php
app/Models/{ModelVersion,MediaSimilarity,Recommendation,RecommendationEvent,MlJobRun}.php   app/Jobs/RunMlJob.php
app/Listeners/ForgetHomeShelves.php   resources/views/pages/admin/models/⚡index.blade.php
```

---

### Task 6.0: Intelligence schema, model versions, ML job contracts

**Files:**
- Create: migrations `2026_11_02_000001_create_intelligence_tables.php` — A6 `model_versions` (unique `(name, version)`, raw partial unique `create unique index model_versions_one_active on model_versions (name) where status = 'active'`), `media_features`, `media_lsa_vectors` (`vector('vector', 200)`, `create index … using hnsw (vector vector_cosine_ops)`), `media_similarities` (PK `(media_id, method, similar_media_id)`, index `(media_id, method, score desc)`), `recommendations`, `recommendation_events` ; `2026_11_02_000002_create_media_popularity_view.php` (materialized view + `create unique index media_popularity_media_id on media_popularity (media_id)`); `2026_11_02_000003_create_ml_job_runs_table.php` (`name`, `run_id` string null, `status` enum(queued, running, succeeded, failed), `params` jsonb, `metrics` jsonb null, `error` text null, `started_at`, `finished_at` null — (D-25) master plan D-25/D-28)
- Create: enums `ModelKind` (Lsa, Embedding, ItemCf, Als, Ltr, DeweyClassifier, HybridWeights, InteractionWeights → snake_case values), `ModelStatus` (Training, Candidate, Active, Retired), `ReasonType` (BecauseYouRead, SimilarThemes, PopularWithSimilarReaders, MatchesInterest, Trending, Editorial, NewRelease, Exploration), `RecommendationEventType` (Impression, Click, Dismiss, LibraryAdd); models `ModelVersion` (`scopeActive(Builder, string $name)`, `static activeFor(string $name): ?self`), `MediaSimilarity`, `Recommendation`, `RecommendationEvent`, `MlJobRun` + factories; `database/seeders/ModelVersionSeeder.php`; `config/bookflix.php` (`interaction_weights` and `hybrid_weights` arrays as in Global Constraints, `recommendations.{cache_minutes=10,cold_start_min_interactions=5,epsilon=0.10}`)
- Modify: `app/Ml/MlClient.php` (`startJob`, `job`), `database/seeders/DatabaseSeeder.php`
- Create fixtures: `tests/Fixtures/ml-contracts/{jobs-start.json,job-succeeded.json,job-failed.json}`
- Test: `tests/Feature/Intelligence/SchemaTest.php`, `tests/Feature/Ml/MlClientJobsTest.php`

**Interfaces:**
- Produces: `MlClient::startJob(string $name, array $params = []): string` (POST `/v1/jobs/{name}` body `{params}`, expects 202 `{run_id}`; throws `MlServiceUnavailable` otherwise) and `MlClient::job(string $runId): array{status: string, started_at: ?string, finished_at: ?string, metrics: ?array, error: ?string}` (A8). `ModelVersion::activeFor('hybrid-weights')->parameters` / `'interaction-weights'`. Seeder creates both rows `status = active`, `version = 'seed'`, `parameters` from config, so a `model_version_id` always exists for logging.
- `media_popularity` view columns: `media_id`, `interactions_7d`, `interactions_30d`, `trending_score` (= `interactions_7d + 0.25 × (interactions_30d − interactions_7d)`), `popularity_percentile` (`percent_rank()` over `interactions_30d + ratings_count`); counts only interactions of **active, non-deactivated** users; excludes soft-deleted media; every non-deleted media has a row.

- [ ] **Step 1: Write the failing tests**

```php
test('only one active model version per name is allowed', function () {
    ModelVersion::factory()->create(['name' => 'lsa', 'version' => 'a', 'status' => 'active']);
    expect(fn () => ModelVersion::factory()->create(['name' => 'lsa', 'version' => 'b', 'status' => 'active']))->toThrow(QueryException::class);
    ModelVersion::factory()->create(['name' => 'lsa', 'version' => 'c', 'status' => 'candidate']); // allowed
});
test('seeded weight versions match config', function () {
    $this->seed(ModelVersionSeeder::class);
    expect(ModelVersion::activeFor('hybrid-weights')->parameters['content'])->toBe(0.30)
        ->and(ModelVersion::activeFor('interaction-weights')->parameters['library_add'])->toBe(3)
        ->and(array_sum(Arr::except(ModelVersion::activeFor('hybrid-weights')->parameters, ['context'])))->toEqualWithDelta(1.0, 1e-9);
});
test('similarities cannot include duplicate neighbors and cascade with media', function () {
    $a = MediaSimilarity::factory()->create();
    expect(fn () => MediaSimilarity::factory()->create(['media_id' => $a->media_id, 'similar_media_id' => $a->similar_media_id, 'method' => $a->method]))->toThrow(QueryException::class);
    Media::find($a->media_id)->forceDelete();
    expect(MediaSimilarity::count())->toBe(0);
});
test('popularity view has a row for every live media and ignores deactivated users', function () {
    [$hot, $cold] = Media::factory()->count(2)->create();
    $active = User::factory()->create(); $gone = User::factory()->create(['deactivated_at' => now()]);
    UserInteraction::factory()->count(3)->create(['user_id' => $active->id, 'media_id' => $hot->id, 'event' => 'view', 'occurred_at' => now()->subDay()]);
    UserInteraction::factory()->count(5)->create(['user_id' => $gone->id, 'media_id' => $cold->id, 'event' => 'view', 'occurred_at' => now()->subDay()]);
    DB::statement('refresh materialized view media_popularity');
    $rows = DB::table('media_popularity')->get()->keyBy('media_id');
    expect($rows)->toHaveCount(2)->and((int) $rows[$hot->id]->interactions_7d)->toBe(3)->and((int) $rows[$cold->id]->interactions_7d)->toBe(0)
        ->and((float) $rows[$hot->id]->popularity_percentile)->toBeGreaterThan((float) $rows[$cold->id]->popularity_percentile);
});
test('refreshing popularity with no interactions does not fail', function () {
    Media::factory()->count(3)->create();
    DB::statement('refresh materialized view concurrently media_popularity');
    expect(DB::table('media_popularity')->count())->toBe(3);
});
test('the columns the python service reads exist', function () {
    $expected = json_decode(file_get_contents(base_path('ml/tests/expected_columns.json')), true); // {"user_interactions": ["user_id", ...], ...}
    foreach ($expected as $table => $columns) {
        expect(Schema::getColumnListing($table))->toContain(...$columns);
    }
});
```
```php
// MlClientJobsTest
test('start job posts params and returns the run id', function () {
    config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => str_repeat('s', 32)]);
    Http::fake(['ml.test/v1/jobs/train-lsa' => Http::response(contract('jobs-start.json'), 202)]);
    expect(app(MlClient::class)->startJob('train-lsa', ['components' => 200]))->toBe(contract('jobs-start.json')['run_id']);
    Http::assertSent(fn ($r) => $r['params'] === ['components' => 200]);
});
test('job status maps the contract and non 2xx throws', function () {
    config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => str_repeat('s', 32)]);
    Http::fake(['ml.test/v1/jobs/abc' => Http::response(contract('job-failed.json'))]);
    expect(app(MlClient::class)->job('abc')['status'])->toBe('failed');
    Http::fake(['ml.test/v1/jobs/*' => Http::response(null, 500)]);
    expect(fn () => app(MlClient::class)->job('abc'))->toThrow(MlServiceUnavailable::class);
});
```
(`contract(name)` Pest helper reads the shared fixture; add `ml/tests/expected_columns.json` listing: `user_interactions[user_id,media_id,event,value,occurred_at]`, `users[id,deactivated_at]`, `profiles[user_id,personalization_enabled]`, `media[id,title,alternative_titles,description,deleted_at,published_on,ratings_count]`, `genres`/`themes`/`tags`/`characters` + pivots, `model_versions[...]`, `media_lsa_vectors[media_id,model_version_id,vector]`, `media_similarities[...]`, `media_features[...]`.)

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Intelligence tests/Feature/Ml` → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: intelligence schema, model versions and ml job client"`

---

### Task 6.1: Preprocessing, TF-IDF and LSA training job (Review Focus #4, #5)

**Files:**
- Modify: `ml/pyproject.toml` (deps: `scikit-learn`, `numpy`, `scipy`, `pandas`, `psycopg[binary]`, `pgvector`, `joblib`, `boto3`; keep `implicit` for 6.2), `ml/.env.example` (`DATABASE_URL`, `ARTIFACT_BUCKET`, `ARTIFACT_DIR`, `LSA_COMPONENTS=200`), `ml/app/settings.py`, `.github/workflows/tests.yml` (job `ml`: add `services.postgres` pgvector + `ML_TEST_DATABASE_URL`)
- Create: `ml/bookflix_ml/{preprocess,lsa,registry,db,artifacts,jobs}.py`, `ml/app/routers/jobs.py` (registered under `/v1` with `require_token`), `ml/tests/schema.sql` (minimal Postgres subset of A6 used by the jobs, including `CREATE EXTENSION vector`), `ml/tests/expected_columns.json`, `ml/tests/fixtures/corpus.json`, `ml/tests/{test_preprocess,test_lsa,test_jobs_api,test_train_lsa_db}.py`

**Interfaces:**
- Produces (Python): `preprocess.normalize(text: str) -> str` (NFKC → strip HTML → lowercase → collapse whitespace); `preprocess.tokenize(text: str) -> list[str]` (English stop words removed; word tokens for Latin; for CJK runs character 2- and 3-grams); `preprocess.build_document(row: dict) -> str` (title ×3, alternative titles, description, genres ×2, themes ×2, tags, characters); `lsa.train_lsa(corpus: list[dict], components: int = 200) -> LsaResult` with `media_ids: list[int]`, `vectors: np.ndarray` shape `(n, 200)` (padded with zeros when fewer components fit: `k = min(components, n_features − 1, n_docs − 1)`, raise `NotEnoughData` if `k < 1`), L2-normalized rows (zero rows stay zero), `top_terms: dict[int, list[str]]` (top 10 per doc), `vocab_size`, `explained_variance`; `registry.create_version(conn, name, kind, parameters, dataset_ref) -> int` (status `training`), `registry.finish_version(conn, id, status, metrics)`; `jobs.JobRunner` (single-worker `ThreadPoolExecutor`, in-memory runs: `submit(name, params) -> run_id`, `get(run_id) -> dict` with the A8 shape; unknown name → `UnknownJob`); registry `JOBS: dict[str, Callable[[dict], dict]]` (names: `train-lsa`, `train-item-cf`, `train-als` (Phase 8, not registered yet), `build-similarities`, `refresh-popularity`, `evaluate`). API: `POST /v1/jobs/{name}` → 202 `{"run_id": str}` (404 unknown name, 422 bad params); `GET /v1/jobs/{run_id}` → `{status, started_at, finished_at, metrics, error}` (404 unknown run).
- Behavior of `train-lsa` job: reads the corpus from Postgres (live media only), trains, writes `media_lsa_vectors` + `media_features(top_terms)` for a new `model_versions` row (`name = lsa`, `kind = lsa`, status `candidate`, `metrics = {vocab_size, explained_variance, n_docs}`) **in one transaction, in chunks of 1000 inside it**, saves the vectorizer/SVD with joblib via `artifacts.save()` (S3 `ml-artifacts/lsa/<version>/` when `ARTIFACT_BUCKET` is set, else `ARTIFACT_DIR`). Failure → version `retired` with `metrics.error`, no vector rows.

- [ ] **Step 1: Write the failing pytest tests**

```python
# test_preprocess.py
def test_normalize_strips_html_and_folds_width():
    assert normalize("<p>Ｈｅｌｌｏ  World</p>") == "hello world"

def test_tokenize_removes_stop_words():
    assert tokenize("The fall of the empire") == ["fall", "empire"]

def test_cjk_runs_become_character_ngrams():
    toks = tokenize("進撃の巨人")
    assert {"進撃", "撃の", "の巨", "巨人", "進撃の"} <= set(toks)

def test_document_repeats_title_and_genres():
    doc = build_document({"title": "Dune", "alternative_titles": [], "description": "desert", "genres": ["scifi"], "themes": [], "tags": [], "characters": []})
    assert doc.split().count("dune") == 3 and doc.split().count("scifi") == 2

# test_lsa.py  (corpus fixture: ids 1-2 space-opera, 3-4 cookbook, 5-8 romance/mystery filler, 12 docs, one with empty description)
def test_space_opera_synopses_are_closer_to_each_other_than_to_a_cookbook(corpus):
    r = train_lsa(corpus)
    sim = r.vectors @ r.vectors.T
    a, b, c = (r.media_ids.index(i) for i in (1, 2, 3))
    assert sim[a, b] > sim[a, c] and sim[a, b] > sim[b, c]

def test_vectors_have_200_dimensions_and_unit_norm(corpus):
    r = train_lsa(corpus)
    assert r.vectors.shape == (len(corpus), 200)
    norms = np.linalg.norm(r.vectors, axis=1)
    assert np.all((np.isclose(norms, 1.0)) | (np.isclose(norms, 0.0)))

def test_training_is_deterministic(corpus):
    assert np.allclose(train_lsa(corpus).vectors, train_lsa(corpus).vectors)

def test_empty_documents_do_not_produce_nan(corpus):
    corpus[0].update(title="", alternative_titles=[], description="", genres=[], themes=[], tags=[], characters=[])
    assert not np.isnan(train_lsa(corpus).vectors).any()

def test_too_little_data_raises_not_enough_data():
    with pytest.raises(NotEnoughData):
        train_lsa([{"id": 1, "title": "only one", ...}])

# test_jobs_api.py
def test_start_job_returns_run_id_and_status_is_pollable(client, auth_headers, contract, fake_jobs):
    r = client.post("/v1/jobs/train-lsa", json={"params": {}}, headers=auth_headers)
    assert r.status_code == 202 and set(r.json()) == set(contract("jobs-start.json"))
    status = client.get(f"/v1/jobs/{r.json()['run_id']}", headers=auth_headers).json()
    assert set(status) == set(contract("job-succeeded.json"))

def test_unknown_job_and_unknown_run_are_404(client, auth_headers):
    assert client.post("/v1/jobs/nope", json={"params": {}}, headers=auth_headers).status_code == 404
    assert client.get("/v1/jobs/missing", headers=auth_headers).status_code == 404

def test_jobs_require_a_token(client):
    assert client.post("/v1/jobs/train-lsa", json={"params": {}}).status_code == 401

def test_a_raising_job_is_reported_as_failed_with_the_error(client, auth_headers, failing_jobs):
    run = client.post("/v1/jobs/train-lsa", json={"params": {}}, headers=auth_headers).json()["run_id"]
    body = client.get(f"/v1/jobs/{run}", headers=auth_headers).json()
    assert body["status"] == "failed" and "boom" in body["error"]

# test_train_lsa_db.py  (marked db; uses schema.sql + ML_TEST_DATABASE_URL)
def test_train_lsa_writes_vectors_and_a_candidate_version(db):
    seed_corpus(db)
    metrics = run_train_lsa(db, {})
    assert count(db, "media_lsa_vectors") == 12 and version_status(db, "lsa") == "candidate"
    assert metrics["n_docs"] == 12

def test_soft_deleted_media_is_excluded(db): ...  # deleted_at not null → absent

def test_a_failure_leaves_no_vectors_and_a_retired_version(db, monkeypatch):
    seed_corpus(db); monkeypatch.setattr("bookflix_ml.lsa.train_lsa", boom)
    with pytest.raises(RuntimeError): run_train_lsa(db, {})
    assert count(db, "media_lsa_vectors") == 0 and version_status(db, "lsa") == "retired"

def test_rerunning_creates_a_new_version_and_keeps_the_old_vectors(db): ...
```
(Write the elided tests fully; `fake_jobs`/`failing_jobs` fixtures monkeypatch `JOBS`. `fixtures/*.json` contract files are the Laravel ones in `tests/Fixtures/ml-contracts/`.)

- [ ] **Step 2: Run** `cd ml && uv sync && uv run pytest` → FAIL (modules missing). **Step 3: Implement** per Interfaces. **Step 4: Run** `uv run ruff check . && uv run mypy . && uv run pytest` → PASS (db tests need `ML_TEST_DATABASE_URL=postgresql://postgres:postgres@localhost:5432/bookflix_ml_test`; create it and load `tests/schema.sql` in the `db` fixture). **Step 5: Commit** — `git commit -m "feat: ml preprocessing, lsa training job and job api"`

---

### Task 6.2: Item-CF job and the training export (Review Focus #2, #5)

**Files:**
- Create: `ml/bookflix_ml/item_cf.py`, `ml/tests/{test_item_cf,test_export_db}.py`
- Modify: `ml/bookflix_ml/db.py` (`fetch_interactions`, `fetch_interaction_weights`), `ml/bookflix_ml/jobs.py` (register `train-item-cf`), `ml/pyproject.toml` (`implicit`)

**Interfaces:**
- Produces: `item_cf.interaction_scores(rows: Iterable[tuple[int, int, str, float | None, datetime]], weights: dict, now: datetime) -> dict[tuple[int, int], float]` — per `(user_id, media_id)` sum of event weights × `0.5 ** (age_days / half_life_days)`; `reading_progress` weight = `reading_progress_per_percent × value`; `rating` weight = `(value − rating_center) × rating_scale`; unknown events ignored; totals ≤ 0 dropped. `item_cf.item_neighbors(scores: dict, k: int = 50) -> dict[int, list[tuple[int, float]]]` — builds a user × item CSR matrix, applies `implicit.nearest_neighbours.bm25_weight`, then `CosineRecommender(K=k)`; returns neighbors by descending score, never the item itself, only items sharing ≥ 1 user. `db.fetch_interactions(conn) -> list[tuple]` with the eligibility SQL: `join users u … left join profiles p on p.user_id = u.id where u.deactivated_at is null and coalesce(p.personalization_enabled, true) and i.media_id is not null and m.deleted_at is null`. `db.fetch_interaction_weights(conn) -> dict` reads the active `interaction-weights` version (falls back to the built-in defaults dict in `item_cf.DEFAULT_WEIGHTS` when absent). Job `train-item-cf`: creates a `model_versions` row `name = item-cf`, `kind = item_cf`, status `candidate`, `parameters = {weights, k, n_users, n_items}`, stores neighbor artifacts (joblib) — neighbors themselves are written in 6.3.

- [ ] **Step 1: Write the failing pytest tests**

```python
NOW = datetime(2026, 11, 1, tzinfo=UTC)
W = DEFAULT_WEIGHTS

def test_events_are_weighted_and_decayed_by_a_90_day_half_life():
    rows = [(1, 10, "library_add", None, NOW), (1, 10, "reading_complete", None, NOW - timedelta(days=90))]
    assert interaction_scores(rows, W, NOW)[(1, 10)] == pytest.approx(3 + 8 * 0.5)

def test_reading_progress_scales_with_percent_points():
    assert interaction_scores([(1, 1, "reading_progress", 30.0, NOW)], W, NOW)[(1, 1)] == pytest.approx(1.5)

def test_ratings_are_centered_on_5_5():
    assert interaction_scores([(1, 1, "rating", 9.0, NOW)], W, NOW)[(1, 1)] == pytest.approx(4.2)

def test_net_negative_items_are_dropped_and_unknown_events_ignored():
    rows = [(1, 1, "abandon", None, NOW), (1, 2, "mystery", None, NOW), (1, 3, "search", None, NOW)]
    assert interaction_scores(rows, W, NOW) == {}

def test_neighbors_prefer_more_shared_readers_and_exclude_self():
    scores = {(1, "A"): 5, (1, "B"): 5, (2, "A"): 5, (2, "B"): 5, (3, "A"): 5, (3, "C"): 5, (4, "D"): 5, (4, "E"): 5}
    nb = item_neighbors({(u, hash_id(i)): s for (u, i), s in scores.items()}, k=50)
    ranked = [i for i, _ in nb[hash_id("A")]]
    assert ranked[:2] == [hash_id("B"), hash_id("C")]
    assert hash_id("A") not in ranked and hash_id("D") not in ranked

def test_a_single_user_or_empty_input_does_not_fail():
    assert item_neighbors({}, k=50) == {}
    assert item_neighbors({(1, 1): 3.0, (1, 2): 3.0}, k=50) is not None

def test_neighbor_scores_are_descending_and_at_most_k():
    nb = item_neighbors(big_random_scores(seed=1), k=5)
    assert all(len(v) <= 5 and [s for _, s in v] == sorted((s for _, s in v), reverse=True) for v in nb.values())
```
```python
# test_export_db.py (db-marked) — Review Focus #2
def test_export_excludes_deactivated_and_opted_out_users(db):
    users = seed_users(db, active=1, deactivated=2, opted_out=3, no_profile=4)  # no profile row counts as enabled
    seed_interactions(db, users, media_id=1)
    got = {row[0] for row in fetch_interactions(db)}
    assert got == {1, 4}

def test_interactions_recorded_before_opting_out_are_excluded(db):
    uid = seed_users(db, active=1)[1]; seed_interactions(db, {1: uid}, media_id=1)
    db.execute("insert into profiles (user_id, personalization_enabled) values (%s, false)", (uid,))
    assert fetch_interactions(db) == []

def test_soft_deleted_media_and_null_media_rows_are_excluded(db): ...
def test_weights_come_from_the_active_model_version_with_a_default_fallback(db): ...
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** the Python gate → PASS. **Step 5: Commit** — `git commit -m "feat: item-cf training job with eligible-user interaction export"`

---

### Task 6.3: Similarities writer, popularity refresh, `Media::scopeTrending`

**Files:**
- Create: `ml/bookflix_ml/{similarities,popularity}.py`, `ml/tests/{test_similarities,test_popularity_db}.py`
- Modify: `ml/bookflix_ml/jobs.py` (register `build-similarities`, `refresh-popularity`), `app/Models/Media.php` (`scopeTrending` redefined), `tests/Feature/Discovery/HomeV1Test.php` expectations if they relied on the Phase 4 interim ordering
- Test (PHP): `tests/Feature/Intelligence/TrendingScopeTest.php`

**Interfaces:**
- Produces: `similarities.top_k_cosine(ids: list[int], vectors: np.ndarray, k: int = 50, block: int = 1024) -> dict[int, list[tuple[int, float]]]` (cosine, blockwise matmul, self excluded, zero vectors yield no neighbors, descending); `similarities.write(conn, method: str, model_version_id: int, neighbors: dict) -> int` — in **one transaction** deletes existing rows for `(method, model_version_id)` then bulk-inserts (`media_similarities`); job `build-similarities` params `{"methods": ["lsa", "item_cf"]}` (default both): `lsa` uses the latest `candidate`-or-`active` `lsa` version's vectors, `item_cf` the neighbors from the latest `item-cf` version (recomputed from `fetch_interactions`), each stored with its own `model_version_id`; `popularity.refresh(conn)` = `REFRESH MATERIALIZED VIEW CONCURRENTLY media_popularity`; job `refresh-popularity`. PHP: `Media::scopeTrending(Builder)` = left join `media_popularity`, order `coalesce(trending_score, 0) desc, ratings_count desc, published_on desc nulls last` (replaces the Phase 4 interim).

- [ ] **Step 1: Write the failing tests**

```python
def test_no_self_neighbors_and_descending_order():
    ids = [1, 2, 3, 4]; v = unit(np.array([[1, 0], [0.9, 0.1], [0, 1], [0.1, 0.9]]))
    nb = top_k_cosine(ids, v, k=3)
    assert all(i not in [n for n, _ in nb[i]] for i in ids)
    assert [n for n, _ in nb[1]][0] == 2 and [s for _, s in nb[1]] == sorted((s for _, s in nb[1]), reverse=True)

def test_k_limits_neighbors_and_blocks_give_the_same_result():
    ids = list(range(200)); v = unit(rng(0).normal(size=(200, 8)))
    assert all(len(x) == 50 for x in top_k_cosine(ids, v, k=50, block=64).values())
    assert top_k_cosine(ids, v, 50, block=64) == top_k_cosine(ids, v, 50, block=1000)

def test_zero_vectors_have_no_neighbors_and_are_nobodys_neighbor():
    nb = top_k_cosine([1, 2, 3], np.array([[1.0, 0], [0, 0], [0.5, 0.5]]), k=5)
    assert nb[2] == [] and 2 not in [n for n, _ in nb[1]]

# db-marked
def test_write_replaces_rows_for_the_same_version_atomically(db): ...   # rerun → no duplicates; old other-version rows untouched
def test_write_failure_rolls_back_and_keeps_old_rows(db): ...
def test_refresh_popularity_job_runs_on_an_empty_database(db): refresh(db)
```
```php
test('trending orders by the view then by ratings count and works without popularity data', function () {
    [$a, $b, $c] = Media::factory()->count(3)->sequence(['ratings_count' => 1], ['ratings_count' => 9], ['ratings_count' => 5])->create();
    DB::statement('refresh materialized view media_popularity');
    expect(Media::trending()->pluck('media.id')->all())->toBe([$b->id, $c->id, $a->id]);   // no interactions yet → ratings_count tiebreak
    UserInteraction::factory()->count(4)->create(['media_id' => $a->id, 'event' => 'view', 'occurred_at' => now()]);
    DB::statement('refresh materialized view media_popularity');
    expect(Media::trending()->first()->id)->toBe($a->id);
});
test('trending excludes soft deleted media', function () { /* delete one, refresh, assert absent */ });
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** Python and PHP gates → PASS. **Step 5: Commit** — `git commit -m "feat: similarity writer, popularity refresh and trending scope"`

---

### Task 6.4: Candidate sources (Review Focus #3)

**Files:**
- Create: `app/Discovery/Recommendations/{CandidateSource,Candidate,CandidatePool,SeedItems}.php`, the eight `Sources/*.php`, `app/Providers/RecommendationServiceProvider.php` (tags the sources `recommendation.sources`; registered in `bootstrap/providers.php`)
- Test: `tests/Unit/Discovery/SeedItemsTest.php` (DB-backed → place under `tests/Feature/Discovery/Recommendations/`), one test file per source

**Interfaces:**
- Produces (A7): `interface CandidateSource { public function key(): string; /** @return Collection<int, Candidate> */ public function candidatesFor(User $user, int $limit): Collection; }`; `final readonly class Candidate { __construct(public int $mediaId, public string $source, public float $score, public ReasonType $reason, public ?int $reasonMediaId = null, public array $payload = []) }` (the optional `payload` carries e.g. `['genre' => 'Fantasy']`; the A7 constructor is extended, not changed). Keys: `content_similar`, `collaborative`, `dewey_similar`, `trending`, `popular_in_genres`, `new_releases`, `editorial`, `exploration`.
- `SeedItems::for(User $user): Collection<int, float>` (mediaId → strength): favorited 1.0; else rating/10; completed 0.8; reading 0.5; else excluded; abandoned and ratings ≤ 4 excluded; strength = max of applicable; `SeedItems::mostRecent(User): ?Media` (latest completed, or rated ≥ 8, by timestamp). Opted-out users: `SeedItems::for` still works (library is the user's own), but `ShelfBuilder` (6.8) does not call personalized sources for them.
- Shared behavior (abstract base `Sources\Source implements CandidateSource`): excludes the user's library media, media the user dismissed (`recommendation_events.event = dismiss` via `recommendations.user_id`), soft-deleted media, and similarities from non-active model versions (join `model_versions.status = 'active'`).
- Behaviors: `ContentSimilarSource` — active `lsa` (+ `embedding` from Phase 7) neighbors of seeds, `score = neighbor.score × seedStrength` (max over seeds), reason `BecauseYouRead`, `reasonMediaId = best seed`. `CollaborativeSource` — active `item_cf` neighbors; score summed over seeds (clamped ≤ 1), reason `PopularWithSimilarReaders`. `DeweySimilarSource` — media whose public primary class shares the seed's **division** (`DeweyHierarchy::similarity ≥ 2/3`), score = similarity × strength × 0.5 + 0.5 × `popularity_percentile`, reason `SimilarThemes`. `TrendingSource` — `Media::trending()`, score = `trending_score` scaled to [0,1] by the max in the set (all-zero → ratings-count rank), reason `Trending`. `PopularInGenresSource` — top 3 affinity genres (preferences weight + genres of seeds weighted by strength), media in those genres by `popularity_percentile`, reason `MatchesInterest` with `payload['genre']`. `NewReleasesSource` — `published_on` within 90 days, limited to preferred media types (`UserPreference` media_type) and `profile->preferred_languages` when set, reason `NewRelease`, score by recency. `EditorialSource` — items from `collections.is_editorial`, by `position`, reason `Editorial`. `ExplorationSource` — random (injected `Random\Randomizer`) well-rated (`average_rating ≥ 7.5` and `ratings_count ≥ 5`, falling back to any rated, then any) media **outside the user's top 3 genres**, reason `Exploration`.

- [ ] **Step 1: Write the failing tests** (one file per source; all share `tests/Pest.php` helpers `activeVersion(string $name): ModelVersion`, `similar(Media $a, Media $b, string $method, float $score, ModelVersion $v)`, `libraryEntry(User, Media, status, ...)`)

```php
// ContentSimilarSourceTest
test('neighbors of seeds are scored by neighbor score times seed strength and attributed to the seed', function () {
    $user = User::factory()->create(); [$seed, $n1, $n2] = Media::factory()->count(3)->create();
    libraryEntry($user, $seed, 'completed'); $v = activeVersion('lsa');
    similar($seed, $n1, 'lsa', 0.8, $v); similar($seed, $n2, 'lsa', 0.5, $v);
    $c = app(ContentSimilarSource::class)->candidatesFor($user, 10);
    expect($c->pluck('mediaId')->all())->toBe([$n1->id, $n2->id])
        ->and($c->first()->score)->toEqualWithDelta(0.8 * 0.8, 1e-9)
        ->and($c->first()->reason)->toBe(ReasonType::BecauseYouRead)->and($c->first()->reasonMediaId)->toBe($seed->id)
        ->and($c->first()->source)->toBe('content_similar');
});
test('library items, dismissed items, deleted items and non active versions are excluded', function () {
    $user = User::factory()->create(); [$seed, $inLib, $dismissed, $deleted, $stale, $ok] = Media::factory()->count(6)->create();
    libraryEntry($user, $seed, 'completed'); libraryEntry($user, $inLib, 'want_to_read'); $v = activeVersion('lsa'); $old = ModelVersion::factory()->create(['name' => 'lsa', 'status' => 'retired']);
    foreach ([$inLib, $dismissed, $deleted, $ok] as $m) { similar($seed, $m, 'lsa', 0.7, $v); } similar($seed, $stale, 'lsa', 0.9, $old);
    dismissed($user, $dismissed); $deleted->delete();
    expect(app(ContentSimilarSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$ok->id]);
});
test('no seeds or no similarity rows give an empty collection and the limit is honoured', function () {
    $user = User::factory()->create();
    expect(app(ContentSimilarSource::class)->candidatesFor($user, 10))->toBeEmpty();
    // 30 neighbors, limit 5 → 5
});
test('abandoned and low rated stories are not seeds', function () { /* SeedItems excludes; no candidates */ });
```
Analogous tests per source:
- `CollaborativeSource`: sums item_cf scores over two seeds sharing a neighbor and clamps at 1; reason `PopularWithSimilarReaders`.
- `DeweySimilarSource`: seed under 813 → candidate under 823 (same division 800? note: 813/823 share only the root; **same division** means 810-sibling e.g. 813 vs 811) — assert a story in 811 appears, one in 510 doesn't; rejected/low-confidence classes ignored.
- `TrendingSource`: scores in [0,1] with zero popularity data still returns results; reason `Trending`.
- `PopularInGenresSource`: payload genre equals the top affinity genre; stories outside those genres absent.
- `NewReleasesSource`: only releases ≤ 90 days; respects preferred type and language; a user with no preferences gets all.
- `EditorialSource`: only `is_editorial` collection items, in position order; private non-editorial collections never leak.
- `ExplorationSource`: `new Randomizer(new Mt19937(42))` is deterministic; none of the returned media shares the user's top-3 genres; falls back when no media is well rated.
- `SeedItems`: favorite 1.0 beats rating; completed 0.8; reading 0.5; abandoned excluded; rating 4 excluded; `mostRecent` picks the latest completed.

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Discovery/Recommendations` → FAIL. **Step 3: Implement** (batch queries; no per-seed query loops — add a test asserting ≤ 6 queries for 20 seeds on `ContentSimilarSource`). **Step 4: Run** → PASS. **Step 5: Commit** per source or per two sources — `git commit -m "feat: candidate sources"`

---

### Task 6.5: `HybridRanker` and `RankingWeights` (Review Focus #5)

**Files:**
- Create: `app/Discovery/Recommendations/{RankingWeights,HybridRanker,RankedItem}.php`
- Test: `tests/Unit/Discovery/RankingWeightsTest.php` (pure), `tests/Feature/Discovery/Recommendations/HybridRankerTest.php`

**Interfaces:**
- Produces: `final readonly class RankingWeights { __construct(public float $content, public float $collaborative, public float $dewey, public float $popularity, public float $freshness, public float $preference, public float $novelty, public float $context = 0.0); static fromArray(array $a): self; static active(): self /* ModelVersion::activeFor('hybrid-weights')->parameters, else config('bookflix.hybrid_weights') */; toArray(): array }`. `final readonly class RankedItem { __construct(public int $mediaId, public float $score, public array $features /* array<string,float> */, public Explanation $explanation) }`. `HybridRanker::rank(User $user, Collection $candidates, RankingWeights $weights): Collection<RankedItem>` (descending by score; stable on ties by `mediaId`). Features (all [0,1]; keys exactly `content_similarity, collaborative_score, dewey_similarity, popularity, freshness, personal_preference, context, novelty`): content = max over seeds of active lsa/embedding neighbor score × strength; collaborative = Σ item_cf neighbor scores × strength / (Σ strengths), clamp ≤ 1; dewey = max `DeweyHierarchy::similarity` between the candidate's and any seed's public primary class (0 if either unclassified); popularity = `popularity_percentile`; freshness = `exp(−age_days/180)` (null date → 0); preference = (genre/theme/type/creator affinity from `user_preferences` weights and seed-weighted genres) ÷ max affinity in the candidate set (0 if none); context = 0; novelty = `1 − popularity`. Duplicate candidates for one media (several sources) merge: `Explanation` comes from the candidate with the highest `score`. Weights map to features as content→`content_similarity`, collaborative→`collaborative_score`, dewey→`dewey_similarity`, popularity→`popularity`, freshness→`freshness`, preference→`personal_preference`, novelty→`novelty`, context→`context`. Batch-loads everything (constant queries).

- [ ] **Step 1: Write the failing tests**

```php
test('score is the weighted sum of normalized features and stays within 0 and 1', function () {
    $w = RankingWeights::fromArray(config('bookflix.hybrid_weights'));
    $ranked = rankFixture($w); // 6 candidates with varied features
    expect($ranked->every(fn ($r) => $r->score >= 0 && $r->score <= 1))->toBeTrue()
        ->and($ranked->every(fn ($r) => collect($r->features)->every(fn ($f) => $f >= 0 && $f <= 1)))->toBeTrue()
        ->and(array_keys($ranked->first()->features))->toBe(['content_similarity', 'collaborative_score', 'dewey_similarity', 'popularity', 'freshness', 'personal_preference', 'context', 'novelty']);
});
test('changing a weight changes the order', function () {
    $contentHeavy = rankFixture(RankingWeights::fromArray(['content' => 1.0] + zeroWeights()));
    $noveltyHeavy = rankFixture(RankingWeights::fromArray(['novelty' => 1.0] + zeroWeights()));
    expect($contentHeavy->pluck('mediaId')->all())->not->toBe($noveltyHeavy->pluck('mediaId')->all());
});
test('a missing feature counts as zero', function () {
    $user = User::factory()->create(); $m = Media::factory()->create(['published_on' => null]);   // no similarities, no popularity row data, no date
    $r = app(HybridRanker::class)->rank($user, collect([new Candidate($m->id, 'trending', 0.5, ReasonType::Trending)]), RankingWeights::active())->first();
    expect($r->features['content_similarity'])->toBe(0.0)->and($r->features['freshness'])->toBe(0.0)->and($r->score)->toBeFinite();
});
test('freshness decays with age', function () {
    [$new, $old] = Media::factory()->count(2)->sequence(['published_on' => now()->subDays(1)], ['published_on' => now()->subYears(3)])->create();
    $r = rankSingleFeature('freshness', [$new, $old]);
    expect($r[$new->id])->toBeGreaterThan(0.99)->and($r[$old->id])->toBeLessThan(0.05);
});
test('novelty is the complement of popularity', function () { /* percentile .9 → novelty .1 */ });
test('dewey similarity uses the deepest shared ancestor', function () { /* 813 vs 811 = 2/3 ; vs 510 = 0 */ });
test('the ranker explanation comes from the highest scoring source and ties are stable', function () {
    // two candidates for the same media: content (0.9, BecauseYouRead) and trending (0.2) → explanation type BecauseYouRead
});
test('weights fall back to config when no version exists and mirror an active version when present', function () {
    ModelVersion::query()->delete();
    expect(RankingWeights::active()->content)->toBe(0.30);
    ModelVersion::factory()->create(['name' => 'hybrid-weights', 'status' => 'active', 'parameters' => ['content' => 0.5] + zeroWeights()]);
    expect(RankingWeights::active()->content)->toBe(0.5);
});
test('an empty candidate set returns empty without queries beyond setup', function () {
    expect(app(HybridRanker::class)->rank(User::factory()->create(), collect(), RankingWeights::active()))->toBeEmpty();
});
test('ranking 100 candidates runs a constant number of queries', function () { /* <= 12 */ });
```
(`zeroWeights()` = all eight keys at 0.0.)
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: hybrid ranker with versioned weights"`

---

### Task 6.6: `Diversifier` (MMR) and exploration injection

**Files:**
- Create: `app/Discovery/Recommendations/{Diversifier,ExplorationInjector}.php`
- Test: `tests/Unit/Discovery/DiversifierTest.php`, `tests/Unit/Discovery/ExplorationInjectorTest.php`

**Interfaces:**
- Produces (A7): `Diversifier::rerank(Collection $ranked, float $lambda = 0.7, int $limit = 20, ?Closure $similarity = null): Collection<RankedItem>` — greedy MMR: pick the highest-score first, then repeatedly the item maximizing `λ·score − (1−λ)·max similarity to already-picked`; `$similarity(int $a, int $b): float` defaults to genre+theme Jaccard loaded once for the candidate ids. `ExplorationInjector::inject(Collection $ranked, Collection $exploration, int $every = 10): Collection<RankedItem>` — returns the list with every `$every`-th position (10th, 20th, …) taken by the next unused exploration item (skipping any already in the list), never exceeding the original length, never duplicating a media. Epsilon note: ε = 0.10 is realized as that fixed one-in-ten slot plus a randomised pool (`ExplorationSource`).

- [ ] **Step 1: Write the failing tests**

```php
test('mmr keeps at most four fantasy items in the top ten for a broad-interest user (spec 31)', function () {
    $items = collect(range(1, 20))->map(fn ($i) => ranked($i, score: 0.9 - $i * 0.01))      // 20 fantasy, high scores
        ->merge(collect(range(21, 32))->map(fn ($i) => ranked($i, score: 0.5 - ($i - 21) * 0.01)));   // 12 others over 6 genres
    $genres = fn (int $id) => $id <= 20 ? ['fantasy'] : ['g'.(($id - 21) % 6)];
    $sim = fn (int $a, int $b) => count(array_intersect($genres($a), $genres($b))) / count(array_unique([...$genres($a), ...$genres($b)]));

    $top = app(Diversifier::class)->rerank($items, 0.7, 10, $sim);

    expect($top)->toHaveCount(10)->and($top->filter(fn ($r) => $r->mediaId <= 20)->count())->toBeLessThanOrEqual(4);
});
test('lambda 1 reduces to plain score order and the limit is respected', function () {
    $items = collect(range(1, 8))->map(fn ($i) => ranked($i, 1 - $i * 0.1));
    expect(app(Diversifier::class)->rerank($items, 1.0, 5, fn () => 1.0)->pluck('mediaId')->all())->toBe([1, 2, 3, 4, 5]);
});
test('the first pick is always the top scored item and fewer items than the limit are all returned', function () {
    expect(app(Diversifier::class)->rerank(collect([ranked(1, .9), ranked(2, .8)]), 0.7, 20, fn () => 0.0)->pluck('mediaId')->all())->toBe([1, 2]);
    expect(app(Diversifier::class)->rerank(collect(), 0.7, 20))->toBeEmpty();
});
test('the default similarity is genre and theme jaccard', function () {
    [$a, $b, $c] = mediaWithGenres(['fantasy'], ['fantasy'], ['romance']);
    $out = app(Diversifier::class)->rerank(collect([ranked($a->id, .9), ranked($b->id, .89), ranked($c->id, .5)]), 0.7, 2);
    expect($out->pluck('mediaId')->all())->toBe([$a->id, $c->id]);
});
```
```php
test('every tenth slot is replaced by an unused exploration item', function () {
    $ranked = collect(range(1, 20))->map(fn ($i) => ranked($i, 1 - $i * 0.01)); $explore = collect([ranked(101, .4), ranked(102, .4), ranked(1, .4)]);
    $out = app(ExplorationInjector::class)->inject($ranked, $explore);
    expect($out)->toHaveCount(20)->and($out[9]->mediaId)->toBe(101)->and($out[19]->mediaId)->toBe(102)
        ->and($out->pluck('mediaId')->unique()->count())->toBe(20);
});
test('short lists and empty exploration pools are left unchanged', function () {
    $short = collect(range(1, 5))->map(fn ($i) => ranked($i, .5));
    expect(app(ExplorationInjector::class)->inject($short, collect([ranked(101, .4)]))->pluck('mediaId')->all())->toBe([1, 2, 3, 4, 5]);
    expect(app(ExplorationInjector::class)->inject(collect(range(1, 12))->map(fn ($i) => ranked($i, .5)), collect())->count())->toBe(12);
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: mmr diversifier and exploration injection"`

---

### Task 6.7: Explanations

**Files:**
- Create: `app/Discovery/Recommendations/Explanation.php`; Modify: `resources/views/components/cover-tile.blade.php` (renders `reason` and a "Why this?" popover via `<x-story-preview>`'s popover primitive showing the label plus the two strongest feature names in plain words)
- Test: `tests/Unit/Discovery/ExplanationTest.php`, `tests/Feature/Components/CoverTileReasonTest.php`

**Interfaces:**
- Produces: `final readonly class Explanation { __construct(public ReasonType $type, public ?string $reasonTitle = null, public ?string $genre = null); static fromCandidate(Candidate $c, ?Media $reasonMedia): self; label(): ?string }`. Templates: `BecauseYouRead` → "Because you read {title}"; `SimilarThemes` → "Similar themes to {title}"; `PopularWithSimilarReaders` → "Popular with readers who enjoyed {title}"; `MatchesInterest` → "Matches your interest in {genre}"; `Trending` → "Trending now"; `Editorial` → "Picked by our editors"; `NewRelease` → "New release"; `Exploration` → "Something different for you". A template needing `{title}`/`{genre}` with that value missing returns `null`.

- [ ] **Step 1: Write the failing tests**

```php
test('each reason type renders its template with titles', function (ReasonType $type, array $args, ?string $expected) {
    expect((new Explanation($type, ...$args))->label())->toBe($expected);
})->with([
    [ReasonType::BecauseYouRead, ['reasonTitle' => 'Dune'], 'Because you read Dune'],
    [ReasonType::SimilarThemes, ['reasonTitle' => 'Dune'], 'Similar themes to Dune'],
    [ReasonType::PopularWithSimilarReaders, ['reasonTitle' => 'Dune'], 'Popular with readers who enjoyed Dune'],
    [ReasonType::MatchesInterest, ['genre' => 'Fantasy'], 'Matches your interest in Fantasy'],
    [ReasonType::Trending, [], 'Trending now'],
    [ReasonType::Editorial, [], 'Picked by our editors'],
    [ReasonType::NewRelease, [], 'New release'],
    [ReasonType::Exploration, [], 'Something different for you'],
]);
test('a missing title or genre yields no label', function (ReasonType $type) {
    expect((new Explanation($type))->label())->toBeNull();
})->with([ReasonType::BecauseYouRead, ReasonType::SimilarThemes, ReasonType::PopularWithSimilarReaders, ReasonType::MatchesInterest]);
test('titles are escaped in the rendered tile', function () {
    $html = Blade::render('<x-cover-tile :media="$m" reason="Because you read <script>x</script>" />', ['m' => Media::factory()->create()]);
    expect($html)->not->toContain('<script>x</script>')->toContain('&lt;script&gt;');
});
test('a tile without a reason renders no label and no why-this control', function () {
    expect(Blade::render('<x-cover-tile :media="$m" />', ['m' => Media::factory()->create()]))->not->toContain('Why this?');
});
test('fromCandidate carries genre payload and the reason media title', function () {
    $m = Media::factory()->create(['title' => 'Dune']);
    $e = Explanation::fromCandidate(new Candidate(1, 'content_similar', .5, ReasonType::BecauseYouRead, $m->id), $m);
    expect($e->label())->toBe('Because you read Dune');
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: recommendation explanations"`

---

### Task 6.8: `ShelfBuilder`, personalized Home, caching, logging (Review Focus #1, #3)

**Files:**
- Create: `app/Discovery/Recommendations/{CandidatePool,ShelfBuilder,RecommendationLogger}.php`, `app/Listeners/ForgetHomeShelves.php` (listens to `LibraryChanged`: `Cache::forget("home:shelves:{$user->id}")`), `app/Http/Controllers/…` none
- Modify: `resources/views/pages/home/⚡index.blade.php` (renders `ShelfBuilder::forHome`, lazy per shelf; tile links add `?rec={recommendation_id}`; impression via `x-intersect`; "Not interested" menu → `dismiss`), `resources/views/pages/stories/⚡show.blade.php` (reads `?rec` → `recommendation_click` interaction + `RecommendationEvent` click), `⚡library-controls.blade.php` (a library add with `rec` in the session logs `library_add`), `app/Discovery/HomeShelves.php` (Phase 4's guest-safe list becomes the cold-start fallback inside `ShelfBuilder`)
- Test: `tests/Feature/Discovery/Recommendations/{ShelfBuilderTest,HomeCachingTest,RecommendationLoggingTest}.php`

**Interfaces:**
- Consumes: Phase 4 `Shelf`, Phase 5 `ContinueReading::shelf`, sources, `HybridRanker`, `Diversifier`, `ExplorationInjector`.
- Produces: `CandidatePool::gather(User $user, int $limit = 200): Collection<Candidate>` (all tagged sources, personalized ones only when the user is personalization-enabled; dedupes; excludes library/dismissed); `ShelfBuilder::forHome(User $user): Collection<Shelf>` — keys/titles in master plan A12 order: `continue-reading` "Continue Reading" · `recommended` "Recommended For You" (rank → diversify to 20 → inject exploration) · `because-you-read` "Because You Read {X}" (X = `SeedItems::mostRecent`) · `new-releases` "New Releases" · `similar-to` "Similar To {Y}" (neighbors of a favorite) · `trending` "Trending Now" (`featured: true`) · `popular-in-genres` "Popular in Your Genres" · `discover-new` "Discover Something New" · `collections` "Your Collections" · `explore-by-subject` "Explore by Subject". **Empty shelves are omitted.** Cold start (< `cold_start_min_interactions` strong seeds, or personalization off): `recommended`, `because-you-read`, `similar-to` are skipped and the remaining shelves fill from popular/trending/editorial/new-release/preference-filtered popularity. Cache key `home:shelves:{user_id}` for `config('bookflix.recommendations.cache_minutes')`; `RecommendationLogger::log(User, Collection<Shelf>, ?ModelVersion): void` writes `recommendations` rows (user, media, shelf key, score, reason_type, reason_media_id, reason_payload — which **always includes `features` (the eight `RankedItem` feature values)** because Phase 8's learning-to-rank needs the features as served — `model_version_id` of the active `hybrid-weights`, generated_at) **only for personalization-enabled users and only on a cache miss**; `RecommendationLogger::event(User, int $recommendationId, RecommendationEventType): void` (ownership enforced: a foreign id is ignored/404).

- [ ] **Step 1: Write the failing tests**

```php
test('a user with zero interactions gets at least four non empty shelves and no errors', function () {
    seedCatalogForHome(); // 40 media with dates/genres, 10 classified, 1 editorial collection with items, popularity refreshed
    $user = User::factory()->create();
    $shelves = app(ShelfBuilder::class)->forHome($user);
    expect($shelves->filter(fn ($s) => $s->items->isNotEmpty()))->toHaveCount($shelves->count())
        ->and($shelves->count())->toBeGreaterThanOrEqual(4)
        ->and($shelves->pluck('key'))->not->toContain('recommended', 'because-you-read');
});
test('a user with a single interaction does not error and gets the same cold start shelves', function () {
    seedCatalogForHome(); $user = User::factory()->create();
    app(AddToLibrary::class)->handle($user, Media::first(), ReadingStatus::Completed);
    expect(fn () => app(ShelfBuilder::class)->forHome($user))->not->toThrow(Throwable::class);
});
test('a rich user gets personalized shelves in master plan order with explanations', function () {
    $user = richUser(); // 6 completed + 2 favorites + ratings, lsa/item_cf similarities for them
    $keys = app(ShelfBuilder::class)->forHome($user)->pluck('key')->all();
    expect(array_values(array_intersect(['continue-reading', 'recommended', 'because-you-read', 'new-releases', 'similar-to', 'trending', 'popular-in-genres', 'discover-new', 'collections', 'explore-by-subject'], $keys)))->toBe($keys);
    expect(app(ShelfBuilder::class)->forHome($user)->firstWhere('key', 'because-you-read')->title)->toStartWith('Because You Read ');
});
test('trending is the only featured shelf', function () {
    expect(app(ShelfBuilder::class)->forHome(richUser())->where('featured', true)->pluck('key')->all())->toBe(['trending']);
});
test('library items never appear in recommended', function () {
    $user = richUser(); $libIds = $user->library()->pluck('media_id');
    $recommended = app(ShelfBuilder::class)->forHome($user)->firstWhere('key', 'recommended')->items->pluck('id');
    expect($recommended->intersect($libIds))->toBeEmpty();
});
test('opted out users get cold start shelves and no recommendation rows', function () {
    $user = richUser(); $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);
    $keys = app(ShelfBuilder::class)->forHome($user)->pluck('key');
    expect($keys)->not->toContain('recommended')->and(Recommendation::count())->toBe(0);
});
test('shelves are cached for ten minutes and cleared when the library changes', function () {
    $user = richUser(); $b = app(ShelfBuilder::class);
    DB::enableQueryLog(); $b->forHome($user); $cold = count(DB::getQueryLog()); DB::flushQueryLog();
    $b->forHome($user); expect(count(DB::getQueryLog()))->toBeLessThan(3);
    app(AddToLibrary::class)->handle($user, Media::factory()->create());   // fires LibraryChanged
    DB::flushQueryLog(); $b->forHome($user); expect(count(DB::getQueryLog()))->toBeGreaterThan(5);
    $this->travel(11)->minutes(); DB::flushQueryLog(); $b->forHome($user); expect(count(DB::getQueryLog()))->toBeGreaterThan(5);
});
test('a rating change also clears the cache', function () { /* RateMedia fires LibraryChanged → forget */ });
test('home runs at most 25 queries on a cold cache and tolerates lazy loading prevention', function () {
    $user = richUser(); $this->actingAs($user); DB::enableQueryLog();
    $this->get(route('home'))->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(25);
});
test('what was shown is stored with reason, features and model version, once per cache fill', function () {
    $user = richUser(); app(ShelfBuilder::class)->forHome($user); $n = Recommendation::count(); app(ShelfBuilder::class)->forHome($user);
    $row = Recommendation::where('shelf', 'because-you-read')->first();
    expect($n)->toBeGreaterThan(0)->and(Recommendation::count())->toBe($n)
        ->and(array_keys($row->reason_payload['features']))->toContain('content_similarity', 'novelty')
        ->and($row->reason_type)->toBe(ReasonType::BecauseYouRead)->and($row->reason_media_id)->not->toBeNull()
        ->and($row->model_version_id)->toBe(ModelVersion::activeFor('hybrid-weights')->id);
});
test('events are recorded for the owner only and a dismiss hides the story from future candidates', function () {
    $user = richUser(); app(ShelfBuilder::class)->forHome($user); $rec = Recommendation::where('user_id', $user->id)->first();
    $other = User::factory()->create();
    app(RecommendationLogger::class)->event($other, $rec->id, RecommendationEventType::Click);
    expect(RecommendationEvent::count())->toBe(0);
    app(RecommendationLogger::class)->event($user, $rec->id, RecommendationEventType::Dismiss);
    Cache::flush();
    expect(app(CandidatePool::class)->gather($user)->pluck('mediaId'))->not->toContain($rec->media_id);
});
test('opening a story from a tile records recommendation_click and a click event', function () {
    $user = richUser(); app(ShelfBuilder::class)->forHome($user); $rec = Recommendation::where('user_id', $user->id)->first();
    $this->actingAs($user)->get(route('stories.show', $rec->media->slug).'?rec='.$rec->id);
    expect(UserInteraction::where('event', 'recommendation_click')->count())->toBe(1)->and(RecommendationEvent::where('event', 'click')->count())->toBe(1);
});
test('deleted media and an empty catalog produce an empty list instead of an error', function () {
    expect(app(ShelfBuilder::class)->forHome(User::factory()->create()))->toBeEmpty();
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (cache the *built* shelves as id lists plus recommendation ids; hydrate with one eager-loaded query; UI follows the F4 frames; `figma:figma-design-to-code`). **Step 4: Run** `php artisan test --compact tests/Feature/Discovery` → PASS. **Step 5: Commit** — `git commit -m "feat: personalized home shelves with caching and recommendation logging"`

---

### Task 6.9: Scheduling, `RunMlJob`, admin model-version page and ML health (Review Focus #4)

**Files:**
- Create: `app/Jobs/RunMlJob.php`, `resources/views/pages/admin/models/⚡index.blade.php`
- Modify: `routes/console.php` (schedule), `routes/admin.php` (`admin.models`), admin index links
- Test: `tests/Feature/Intelligence/{ScheduleTest,RunMlJobTest}.php`, `tests/Feature/Admin/ModelsPageTest.php`

**Interfaces:**
- Produces: `RunMlJob(string $name, array $params = [], ?int $then = null)` — creates `MlJobRun(status queued→running)`, `MlClient::startJob`, then re-releases itself every 30 s calling `MlClient::job($runId)` until `succeeded|failed` (max 2 h), copying `status/metrics/error/finished_at`; on `succeeded` it chains the next pipeline step (below); `MlServiceUnavailable` → `release()` with backoff and, after the last try, marks the run `failed` with the message. Pipelines (all dispatched to queue `default`): hourly `refresh-popularity`; nightly 03:00 `train-item-cf` → `build-similarities` (methods `item_cf`) → `evaluate` (kind `item_cf`); weekly (Sun 02:00) `train-lsa` → `build-similarities` (`lsa`) → `evaluate` (`lsa`). Each step only chains after the previous **succeeded**. Admin page: model versions (name, version, kind, status, trained_at, key metrics) newest first, recent `ml_job_runs` with errors in red text token (`text-ink` + status badge, never red small text), ML health via `MlClient::health()`, queue backlog count, buttons "Run now" per pipeline (admin only).

- [ ] **Step 1: Write the failing tests**

```php
test('the ml pipelines are scheduled at the documented times', function () {
    $events = collect(app(Schedule::class)->events());
    $find = fn (string $needle) => $events->first(fn ($e) => str_contains($e->description ?? $e->command ?? '', $needle));
    expect($find('refresh-popularity')->expression)->toBe('0 * * * *')
        ->and($find('train-item-cf')->expression)->toBe('0 3 * * *')
        ->and($find('train-lsa')->expression)->toBe('0 2 * * 0');
});
test('a successful run is recorded and chains the next step', function () {
    Bus::fake([RunMlJob::class]); mlFake(startRun: 'r1', statuses: ['succeeded']);
    (new RunMlJob('train-item-cf', then: 'build-similarities'))->handle(app(MlClient::class));
    expect(MlJobRun::sole()->status)->toBe('succeeded'); Bus::assertDispatched(RunMlJob::class, fn ($j) => $j->name === 'build-similarities');
});
test('a failed run is stored with the error and does not chain', function () {
    Bus::fake([RunMlJob::class]); mlFake(startRun: 'r1', statuses: ['failed'], error: 'boom');
    (new RunMlJob('train-lsa', then: 'build-similarities'))->handle(app(MlClient::class));
    expect(MlJobRun::sole())->status->toBe('failed')->error->toBe('boom'); Bus::assertNotDispatched(RunMlJob::class, fn ($j) => $j->name === 'build-similarities');
});
test('a running job releases itself and an unreachable service eventually fails the run', function () {
    mlFake(startRun: 'r1', statuses: ['running']); $job = (new RunMlJob('train-lsa'))->withFakeQueueInteractions(); $job->handle(app(MlClient::class)); $job->assertReleased(30);
    Http::fake(['*' => Http::response(null, 503)]); $job2 = (new RunMlJob('train-lsa'))->withFakeQueueInteractions(); $job2->handle(app(MlClient::class)); $job2->assertReleased();
    $job2->failed(new MlServiceUnavailable('down')); expect(MlJobRun::latest('id')->first()->status)->toBe('failed');
});
test('admin page lists versions runs and ml health and shows failures', function () {
    ModelVersion::factory()->create(['name' => 'lsa', 'status' => 'active']); MlJobRun::factory()->create(['name' => 'train-lsa', 'status' => 'failed', 'error' => 'boom']);
    Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.models'))->assertOk()->assertSee('lsa')->assertSee('boom')->assertSee('ML service: healthy');
});
test('the models page is forbidden for non admins and run now is admin only', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.models'))->assertForbidden();
    Bus::fake(); $this->actingAs(User::factory()->admin()->create()); Livewire::test('pages::admin.models.index')->call('runNow', 'item-cf'); Bus::assertDispatched(RunMlJob::class);
});
test('the page copes with an unreachable ml service', function () {
    Http::fake(['*' => Http::response(null, 503)]);
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.models'))->assertOk()->assertSee('ML service: unavailable');
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: ml pipeline scheduling and admin model page"`

---

### Task 6.10: Offline evaluation baseline and promotion gate (Review Focus #4)

**Files:**
- Create: `ml/bookflix_ml/evaluate.py`, `ml/tests/{test_evaluate,test_evaluate_db}.py`
- Modify: `ml/bookflix_ml/{registry,jobs}.py` (`promote`, register `evaluate`)

**Interfaces:**
- Produces: `evaluate.metrics_at_k(recommended: dict[int, list[int]], relevant: dict[int, set[int]], k: int = 10, catalog_size: int = 0, item_pop: dict[int, int] | None = None, item_sim: Callable | None = None) -> dict[str, float]` returning `precision@10, recall@10, ndcg@10, map@10, hit_rate@10, coverage, diversity, novelty` (coverage = distinct recommended ÷ catalog; diversity = 1 − mean pairwise similarity in each list (needs `item_sim`, else 0.0); novelty = mean `−log2(pop_i / n_users)`); `evaluate.leave_last_out(interactions: list[tuple[int,int,datetime]]) -> tuple[dict[int, set[int]], dict[int, int]]` (train items per user, held-out last item; users with < 2 positive items skipped); `evaluate.should_promote(candidate: dict, active: dict | None, max_ndcg_drop: float = 0.02) -> bool`; `registry.promote(conn, version_id)` (one transaction: retire the current `active` of the same name, set the candidate `active`, `trained_at` kept). Job `evaluate` params `{kind: "item_cf"|"lsa"}`: loads the latest candidate version, ranks held-out users by that version's `media_similarities`, computes metrics, writes `model_versions.metrics`, then promotes if `should_promote`; the metrics JSON also records `promoted: bool` and `reason`.

- [ ] **Step 1: Write the failing pytest tests**

```python
# known answers: user 1 relevant {3}, recommended [3, 1, 2]; user 2 relevant {9}, recommended [1, 2, 3]
REC = {1: [3, 1, 2], 2: [1, 2, 3]}; REL = {1: {3}, 2: {9}}

def test_metrics_on_a_fixture_with_known_answers():
    m = metrics_at_k(REC, REL, k=10, catalog_size=10)
    assert m["precision@10"] == pytest.approx((1 / 3 + 0) / 2)
    assert m["recall@10"] == pytest.approx(0.5)
    assert m["hit_rate@10"] == pytest.approx(0.5)
    assert m["map@10"] == pytest.approx(0.5)            # user1 AP = 1.0, user2 = 0
    assert m["ndcg@10"] == pytest.approx(0.5)           # user1 DCG=1/log2(2)=1, IDCG=1
    assert m["coverage"] == pytest.approx(3 / 10)

def test_ndcg_discounts_lower_ranks():
    assert metrics_at_k({1: [1, 2, 3]}, {1: {3}}, 10)["ndcg@10"] == pytest.approx(1 / math.log2(4))

def test_empty_input_and_users_without_relevant_items_do_not_divide_by_zero():
    assert metrics_at_k({}, {}, 10)["ndcg@10"] == 0.0
    assert metrics_at_k({1: [1]}, {1: set()}, 10)["recall@10"] == 0.0

def test_diversity_and_novelty():
    m = metrics_at_k({1: [1, 2]}, {1: {1}}, 10, item_pop={1: 1, 2: 4}, n_users=4, item_sim=lambda a, b: 1.0)
    assert m["diversity"] == pytest.approx(0.0) and m["novelty"] == pytest.approx((2 + 0) / 2)

def test_leave_last_out_splits_by_time_and_skips_single_item_users():
    inter = [(1, 10, t(1)), (1, 11, t(2)), (1, 12, t(3)), (2, 10, t(1))]
    train, held = leave_last_out(inter)
    assert held == {1: 12} and train[1] == {10, 11} and 2 not in held

def test_a_regression_beyond_two_percent_blocks_promotion():
    active = {"ndcg@10": 0.50, "coverage": 0.20}
    assert should_promote({"ndcg@10": 0.49, "coverage": 0.21}, active) is True       # -2.0% exactly allowed
    assert should_promote({"ndcg@10": 0.48, "coverage": 0.30}, active) is False      # -4%
    assert should_promote({"ndcg@10": 0.60, "coverage": 0.19}, active) is False      # coverage fell
    assert should_promote({"ndcg@10": 0.10, "coverage": 0.01}, None) is True         # nothing active yet
    assert should_promote({"ndcg@10": 0.0, "coverage": 0.0}, {"ndcg@10": 0.0, "coverage": 0.0}) is True   # zero baseline

# db-marked
def test_promote_swaps_active_atomically_and_never_leaves_two_active(db): ...
def test_evaluate_job_promotes_a_good_candidate_and_keeps_the_active_when_gate_fails(db): ...
def test_evaluate_writes_metrics_and_reason_even_when_blocked(db): ...
def test_a_failing_evaluation_leaves_versions_untouched(db): ...
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** the Python gate (`uv run ruff check . && uv run mypy . && uv run pytest`) → PASS. **Step 5: Commit** — `git commit -m "feat: offline evaluation baseline and promotion gate"`

---

## Phase 6 exit criteria (= MVP exit)
- [ ] `composer test`, the ML suite, and the browser suite are green; CI `ml` job runs with Postgres.
- [ ] Run the A17 exit test by hand (new user → onboard → search "Berserk" → detail → library → progress → rate → explore 800→810→813 → home shows "Because you read…"; admin syncs, reviews classifications, merges a duplicate).
- [ ] Home ≤ 25 queries; Lighthouse ≥ 90 at 390 px; `ponytail:ponytail-review`, `/code-review`, `design:accessibility-review`, `security-review` on the branch.
- [ ] Baseline `model_versions.metrics` recorded for `lsa` and `item-cf`.
- [ ] Ask the user to run the full `php artisan test --compact`.

## Self-review notes
- Spec coverage: §22–§24 (6.1), §27 (6.2), §28/§29 (6.4–6.5), §31/§32 (6.6), §33 cold start (6.8), §34 (6.7), §35 (6.8), §45 baseline (6.10), §63 versioning (6.0–6.2, 6.10).
- Names match master plan A7/A12 (`CandidateSource`, `Candidate`, `HybridRanker::rank`, `RankingWeights`, `Diversifier::rerank`, `ShelfBuilder::forHome`, `RankedItem`). Additions recorded in master plan decisions D-19–D-28: Task 6.0, `ml_job_runs`, `Candidate::$payload`, `CandidatePool`, `SeedItems`, `RecommendationLogger`, the "one slot in ten" reading of ε-greedy, opted-out users get cold-start only, Python `refresh-popularity` kept per A8.
