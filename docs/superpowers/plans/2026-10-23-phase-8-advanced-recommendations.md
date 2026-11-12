# Phase 8 — Advanced Recommendations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Refresh against the code at phase start.** This plan is at task level (Interfaces + test names with key assertions). Re-read Phases 1–7 as built, expand each task to step level with `superpowers:writing-plans`, and reconcile names with the code.

**Goal:** Improve ranking with ALS collaborative filtering, learning-to-rank on real impression data, a data-gated exploration upgrade, richer evaluation, and an admin view to compare model versions over time.

**Architecture:** Same offline/online split as Phase 6 (master plan A12). New Python jobs write candidates and models to Postgres under the existing `model_versions` + promotion gate; Laravel keeps `HybridRanker` as the always-available fallback. Nothing here is mandatory for the product to work: every task is gated by data volume or an evaluation win.

**Tech Stack:** `implicit` (ALS), LightGBM (LambdaMART), pandas, scikit-learn metrics, Chart.js (MIT, admin charts only), Pest, pytest.

**Spec:** `/Users/gabe/Documents/Bookflix Documentation/Bookflix_Specification.md` §27, §30, §31, §32, §45. **Master plan:** A4 (lightgbm, chart.js), A8, A12, Part E Phase 8.

**Depends on:** Phase 6 (`evaluate.py` + `should_promote`, `registry.promote`, `build-similarities`, `RunMlJob`, `RankingWeights`, `HybridRanker`, `ShelfBuilder`, `recommendations` incl. `reason_payload.features`, `recommendation_events`, `media_popularity`), Phase 7 (embedding similarities), Phase 5 (`user_interactions`).

## Revision 2026-11-12 (against `phase-7-semantic` with 7.1 in, after Phase 6 and #27–#30)

Where the plan below disagrees with this section, **this section wins**. Branch `phase-8-advanced` off the tip of `phase-7-semantic`; its PR opens only after Phase 7 merges (`git rebase --onto main <old base tip>`). Ledger `docs/superpowers/plans/phase-8-ledger.md`. Migrations are dated after Phase 7's.

**Rules for every task**
- Every listing goes through `Media::visibleTo`; type filters use `Media::ofTypes()`; raw `cover_url` reads go through `Media::displayCover()`. Never weaken `ProhibitedContent` or the mature gate.
- Expensive ML never runs in a web request. Training, scoring and the candidate export are queued or Python batch jobs; a request only reads tables and caches.
- Below its gate, or with no active version, every feature gives exactly the Phase 6 (and Phase 7) result. Each task proves this with a test on fixtures; there is no real impression data.
- Dependencies: `implicit`, `pandas` and `scikit-learn` are already installed. `lightgbm` (8.2) and `chart.js` (8.4) are the only new ones.
- P8-R1. A gate that is not met is not a failure. The gate runs before `create_version`; the train job succeeds with `metrics.skipped = {reason, have, need}` and creates no version, and `RunMlJob` ends the pipeline there without a failed row. Gate numbers are job parameters with the plan's values as defaults, held in one place on the PHP side so the readiness command and the job read the same number. Hyper-parameters are Python defaults recorded in `parameters` (no `recommendations.als.*` config block: `PIPELINES` is a constant).
- Every new pipeline goes in `RunMlJob::PIPELINES`, `TRAIN_STEPS` where it trains, the schedule, and the admin models page labels and "Run now" copy. The service runs one job at a time, so new slots must not overlap the taken ones (item-cf 03:00, LSA Sunday 02:00, popularity hourly at :00, embed hourly at :30, embedding similarities nightly). The slots chosen go in the ledger.
- `ml/tests/schema.sql` and `expected_columns.json` gain `recommendations`, `recommendation_events` and each new table.

**8.1 ALS**
- `build-similarities` and `evaluate` take the method from a method→name map (`item_cf`, `lsa`, `embedding`, `als`) instead of hard-coded lists. `similarities.top_k_cosine` is reused; `similarities.py` needs nothing else.
- `evaluate` with `kind: "als"` refits in memory on the leave-last-out split, as item-cf does. Reading stored rows would leak the held-out items.
- Promotion follows the settled rule, thin evaluation data included.
- `AlsSource` goes in the provider's tag list and `CandidatePool::PERSONALIZED`. "Only when active" means the source returns empty with no active `als` version; the pool has no active check.
- P8-R4. `HybridRanker`'s collaborative feature is the larger of the `item_cf` and `als` neighbour scores. With no active `als` version the ranking is unchanged (tested against the Phase 6 expectations).

**8.2 Learning to rank**
- A migration adds `recommendations.position` (place within the shelf, from 0), written by `RecommendationLogger::log`. Rows logged before it have null and are not used for training.
- P8-R5. Labels. One labeled impression is one `recommendations` row with at least one `impression` event; repeated events on a row count once; a row never impressed is left out. Grades follow the plan (0, click 1, library_add 3, reading_start 4, the highest applies; `reading_start` comes from `user_interactions`); a dismissed row is grade 0. The export reads only `generated_at >= now − 90 days`, so the old rows that survive pruning because they hold a dismissal don't skew the sample, and skips rows younger than the 7-day window.
- P8-R2. Features. Training reads the features logged at serving time (`reason_payload.features`) and `position`. For scoring, a queued PHP job (scheduled nightly, and doing nothing without an active `ltr` version) writes each active eligible reader's top 300 hybrid candidates with their features to `ltr_candidates`; the Python job `score-ltr` reads rows newer than 36 h, sets position to a constant, and writes `ltr_scores`. Python never recomputes a feature.
- P8-R3. An LTR candidate is promoted only on evidence: a positive NDCG@10 delta against the hybrid order on the held-out impressions. Thin or missing evaluation data retires it. This is the one exception to "thin data promotes the newer version", because Hybrid is always there. `evaluate` gets its own `ltr` branch (the neighbour-based one refuses a version with no similarity rows); `registry._prune` learns `ltr_scores`.
- `LtrRanker` re-scores the hybrid `RankedItem`s (explanations, features and `reasonMediaId` stay) and applies only in `ShelfBuilder::recommended()`. Scores are normalised to [0, 1] before `Diversifier`. No fresh rows, no active version or a retired one gives `null` and the hybrid order.
- P8-R6. Rows served in LTR order are logged under the LTR version id; hybrid rows keep `hybrid-weights`.
- `bookflix:ltr-readiness` prints the labeled-impression count against the gate, using the same definition as the export.

**8.3 Diversity, novelty, bandit**
- `metrics_at_k` already reports `diversity` and `novelty`. Add `genre_diversity` (Jaccard over `media_genres`) and leave the existing keys as they are. `ShelfBuilder` is PHP (the plan lists it under Python).
- P8-R8. The reorderable shelves are `similar-to`, `popular-in-genres` and `discover-new`. `collections` is dropped: collection shelves write no log rows and their place on Home is a signed design decision, as are staff picks. Continue Reading stays first. The order is applied after `withoutRepeats`, so which titles a shelf holds does not change.
- P8-R7. Stats come only from the hourly cache written by `RefreshShelfStats`; a request never aggregates `recommendation_events`. An empty cache gives the default order. The sample is drawn once per shelf-cache fill (10 minutes), not per request, so shelves don't move between reloads. `bandit_enabled` defaults to false.

**8.4 Admin comparison**
- No `ModelMetricsController` and no JSON route: admin routes are all `Route::livewire`, so the page passes the series itself. It makes no ML call (the models page's `health()` call is not copied).
- Versions are ordered by `coalesce(trained_at, created_at)`; retired versions are included; the page also shows `promoted` and `reason`.
- Chart.js is loaded with a dynamic `import()`, as three.js is. The table always renders and is the accessible alternative.
- The page is UI and waits for the design sign-off (Figma page "Phase 8 Screens"). The query, route, authorization and their tests are not UI.

**Exit criteria**
- The plan's criteria, with a whole-branch review by a fresh agent in place of `ponytail:ponytail-review`, and the full local test run listed in the PR as a hand check.
- LTR is trained only on logs from after Phase 7's 7.5 (embedding neighbours change `content_similarity`); with no real data this is a note for the first real training run.

**Order:** 8.1, 8.3 and the non-UI part of 8.4 in parallel, then 8.2, then the 8.4 page after sign-off.

## Global Constraints

- **Phase 1 as built (PR #4, authoritative):** `AssignRequestId` and `SetSecurityHeaders` are global middleware; `EnsureAccountIsActive` stays in the `web` group (new middleware is appended in `bootstrap/app.php` accordingly). Primary navigation is defined **once** in `config('bookflix.navigation')` (items `{label, route, active: [routeIs patterns], icon}`); `layouts/app/header.blade.php` and `components/bottom-nav.blade.php` loop over it, so a phase adds nav items to that config, never to the layouts (`primaryNavs()` in `tests/Pest.php` asserts them). `FILESYSTEM_DISK=local`; public-facing files use a dedicated disk setting like `config('bookflix.avatar_disk')`; **Livewire temporary uploads never use a public disk**. Red (Flux `primary`) is for CTAs only: Save/Update buttons in settings and admin use `variant="filled"`; focus rings use `accent-electric`. ML: `ML_SERVICE_TOKEN` ≥ 32 characters, docs endpoints off unless `ML_ENABLE_DOCS=true`, local `ML_SERVICE_URL=http://localhost:8001`, `MlClient` throws `MlServiceUnavailable` (and `health()` is false) when the token is empty. Deferred to deploy setup: `league/flysystem-aws-s3-v3`, Nightwatch, a shared temporary-upload disk. The spec is not in the repo (public repo): read it at the absolute path above.
- Master-plan constraints apply. New dependencies need approval first: `lightgbm` (A4), `chart.js` (A4, optional).
- **Data gates are code, not comments:** ALS requires ≥ 5 000 eligible interactions and ≥ 200 users; LTR requires ≥ 50 000 labeled impressions (impressions with a recorded outcome window of 7 days); the bandit requires ≥ 1 000 impressions per shelf. Below a gate the job raises `NotEnoughData`, registers no version, and Laravel keeps the Phase 6 behaviour.
- Same eligibility as Phase 6: deactivated and opted-out users never enter training (the Phase 6 `fetch_interactions` SQL is the only entry point; add the same predicate to the new impression export).
- A candidate version is promoted only through `should_promote` (NDCG@10 ≤ 2% relative drop, coverage not lower). LTR additionally needs a positive NDCG@10 delta vs the HybridRanker baseline on the held-out impressions.
- LTR scores are precomputed nightly; the request path only reads them. Missing/stale (> 36 h) rows → `HybridRanker`. Python loads only artifacts it wrote.
- Learning-to-rank must never train on its own exposure bias unchecked: log position and use position as a feature only at training, constant at serving (see 8.2 tests).

## Review Focus

1. ALS/LTR on sparse or tiny data must not produce a version, NaNs or crashes; the product silently stays on Phase 6 behaviour. → 8.1, 8.2.
2. A model that looks good only because of position bias or leakage (features computed after the click, held-out users in training). → 8.2.
3. Stale or partial precomputed rankings never block or break the home page (user with no row, row older than 36 h, retired model). → 8.2.
4. Exploration/bandit must not hide Continue Reading or starve any shelf; deterministic under a seeded randomizer. → 8.3.
5. Admin comparison handles versions with missing metrics and a single version. → 8.4.

---

### Task 8.1: ALS model and candidate source

**Files:**
- Create (Python): `ml/bookflix_ml/als.py`, job `train-als` registered in `jobs.py` (A8 list), `ml/tests/{test_als,test_als_db}.py`
- Modify (Python): `similarities.py` (method `als` from item factors, top 50, cosine), `evaluate.py` (job `evaluate` accepts `kind: "als"`), `registry.py`
- Create (PHP): `app/Discovery/Recommendations/Sources/AlsSource.php`
- Modify: `RecommendationServiceProvider` (tag source), `routes/console.php` (nightly `train-als` → `build-similarities` (`als`) → `evaluate`(`als`) after item-CF), `config/bookflix.php` (`recommendations.als.{factors=64,regularization=0.05,iterations=20,min_interactions=5000,min_users=200}`)
- Test: `tests/Feature/Discovery/Recommendations/AlsSourceTest.php`

**Interfaces:**
- Python: `als.train(scores: dict[tuple[int,int], float], factors: int, regularization: float, iterations: int, seed: int = 42) -> AlsModel` (`item_ids`, `item_factors` L2-normalizable); `als.check_gates(n_interactions: int, n_users: int, cfg) -> None` raises `NotEnoughData`. Uses `interaction_scores` (Phase 6, same weights version). Writes a `model_versions` row `name = als`, `kind = als`, status `candidate`, `parameters` = hyper-parameters + counts.
- PHP: `AlsSource` key `als`, reason `ReasonType::PopularWithSimilarReaders`, same contract/exclusions as `CollaborativeSource`, reading `media_similarities` where `method = 'als'` and the version is active; returns empty when no active `als` version.

- [ ] **Tests (key assertions):** pytest — `training is deterministic for a fixed seed` (item factors allclose); `item factors of co-consumed items are closer than unrelated items` on a block-structured fixture; `gates: 4 999 interactions → NotEnoughData and no model_versions row`; `NaN/inf scores rejected`; `a single user fixture does not crash`; `similarities exclude self and are top-50 descending`; `evaluate kind=als promotes only through the gate`. Pest — `AlsSource returns neighbors of seeds scored by neighbor score × strength`; `no active als version → empty collection`; `retired version rows ignored`; `library/dismissed/deleted excluded`; `source is tagged and CandidatePool includes it only when active`.
- [ ] **Commit:** `feat: als training job and candidate source`

---

### Task 8.2: Learning-to-rank (LightGBM LambdaMART) with nightly precomputed rankings (Review Focus #1–#3)

**Files:**
- Create (Python): `ml/bookflix_ml/{ltr,impressions}.py`, job `train-ltr` and job `score-ltr` (registered), `ml/tests/{test_ltr,test_impressions_db}.py`
- Create (PHP): migration `create_ltr_scores_table` (`ltr_scores`: `user_id`, `media_id`, `score real`, `model_version_id`, `generated_at`; PK `(user_id, media_id)`; index `(user_id, score desc)` — (D-25) flagged), `app/Models/LtrScore.php`, `app/Console/Commands/LtrReadiness.php` (`bookflix:ltr-readiness` prints labeled-impression count vs the gate), `app/Discovery/Recommendations/LtrRanker.php`
- Modify: `ShelfBuilder` (use `LtrRanker` for `recommended` when it has fresh scores), `routes/console.php` (weekly `train-ltr` → `evaluate`(`ltr`); nightly `score-ltr`), `HybridRanker` unchanged (fallback)
- Test: `tests/Feature/Discovery/Recommendations/LtrRankerTest.php`

**Interfaces:**
- Python: `impressions.fetch_labeled(conn, window_days: int = 7) -> pd.DataFrame` — joins `recommendations` (features from `reason_payload->'features'`, `shelf`, `position`) with `recommendation_events`/`user_interactions` outcomes within the window; label grades: impression only 0, `click` 1, `library_add` 3, `reading_start` 4 (max applies); **eligible users only** (Phase 6 predicate); excludes impressions younger than the window. `ltr.train(df, seed=42) -> LtrModel` (LambdaMART objective, groups = one impression list per user×shelf×generated_at, time-based split: train on the oldest 80%, validate on the newest 20%, **no user appears in both**), `ltr.check_gates(n_labeled) -> None` raises `NotEnoughData` below 50 000. Position is a training-only feature, set to a constant at scoring (`score-ltr` builds features for the top 300 hybrid candidates per active user — users with a `user_interactions` event in the last 30 days and eligible — and writes `ltr_scores`).
- PHP: `LtrRanker::rank(User $user, Collection $candidates): ?Collection<RankedItem>` — returns `null` when the user has no `ltr_scores` rows newer than 36 h or no active `ltr` version; otherwise ranks candidates by stored score (candidates without a stored score keep their hybrid score scaled below all scored ones). `ShelfBuilder` falls back to `HybridRanker` on `null`.

- [ ] **Tests:** pytest — `gate: 49 999 labeled impressions → NotEnoughData, no version`; `labels follow the grade table and the max rule`; `young impressions (< window) are excluded`; `deactivated and opted-out users excluded`; `split is chronological and users do not leak across train/validation`; `position is constant at scoring time` (feature matrix has identical position column); `model beats the hybrid baseline NDCG@10 on the synthetic fixture or is not promoted`; `NaN feature → row dropped with a counted warning`; `deterministic with a fixed seed`. Pest — `LtrRanker uses fresh scores`; `rows older than 36 h → null → HybridRanker used`; `user without rows → null`; `retired/absent ltr version → null`; `unscored candidates ranked after scored ones`; `home still renders when ltr_scores is empty`; `bookflix:ltr-readiness reports counts and the gate` ; `ShelfBuilder test from Phase 6 still passes unchanged`.
- [ ] **Commit:** `feat: learning to rank with precomputed scores and hybrid fallback`

---

### Task 8.3: Novelty/diversity evaluation and data-gated exploration upgrade (Review Focus #4)

**Files:**
- Create (PHP): `app/Discovery/Recommendations/ShelfBandit.php`, migration none (aggregates computed from `recommendation_events`, cached hourly), `app/Console/Commands/RefreshShelfStats.php`
- Modify (Python): `evaluate.py` (report intra-list diversity by genre Jaccard and novelty as separate metrics in `model_versions.metrics`; `should_promote` unchanged), `ShelfBuilder` (order the *reorderable* shelves — `similar-to`, `popular-in-genres`, `discover-new`, `collections` — by Thompson samples when each has ≥ 1 000 impressions; `continue-reading`, `recommended`, `because-you-read`, `new-releases`, `trending`, `explore-by-subject` keep their A12 positions)
- Test: `tests/Unit/Discovery/ShelfBanditTest.php`, `ml/tests/test_evaluate_diversity.py`

**Interfaces:** `ShelfBandit::order(array $shelfKeys, array $stats /* key => [impressions, clicks] */, Random\Randomizer $r): array` (Beta(1 + clicks, 1 + impressions − clicks) sampling; keys with `impressions < 1000` keep their relative default order after the sampled ones are placed in their original slots; deterministic for a seeded `Randomizer`). `config('bookflix.recommendations.bandit_enabled')` default `false`.

- [ ] **Tests:** `with a seeded randomizer the higher-CTR shelf is first in a clear majority of 200 trials (> 80%)`; `a shelf below 1000 impressions never moves`; `fixed shelves never move`; `stats with zero impressions/zero clicks do not divide by zero`; `disabled flag → default order`; pytest — `diversity of identical items = 0, of disjoint genres = 1`; `novelty higher for long-tail recommendations`; `metrics keys present in model_versions.metrics`.
- [ ] **Commit:** `feat: diversity/novelty metrics and data-gated shelf bandit`

---

### Task 8.4: Admin model comparison

**Files:**
- Create: `resources/views/pages/admin/models/⚡compare.blade.php`, `resources/js/charts.js` (Chart.js, imported only on this page), `app/Http/Controllers/Admin/ModelMetricsController.php` (JSON series)
- Modify: `routes/admin.php` (`admin.models.compare`, `admin.models.metrics`), `package.json` (`chart.js`, MIT — record license in master plan G2), admin models page link
- Test: `tests/Feature/Admin/ModelCompareTest.php`

**Interfaces:** `GET admin/models/{name}/metrics` → `{versions: [{version, status, trained_at, metrics: {ndcg@10, ...}}]}` ordered by `trained_at`; page renders a table first (always), a line chart per metric when ≥ 2 versions; `access-admin` only. Use the `dataviz` skill for colours/axes.

- [ ] **Tests:** `non admins get 403 on page and JSON`; `series is ordered by trained_at and includes retired versions`; `a version with missing metrics shows blanks, no error`; `a single version renders the table without a chart`; `unknown model name → 404`; browser test: page loads, chart canvas present, `assertNoAccessibilityIssues()` and the table is the accessible alternative.
- [ ] **Commit:** `feat: admin model version comparison`

---

## Phase 8 exit criteria
- [ ] Every model-dependent feature degrades to Phase 6 behaviour when its gate is not met (tests above).
- [ ] `composer test`, ML suite, browser suite green; `ponytail:ponytail-review` (flag unused flexibility).
- [ ] Promotions recorded in `model_versions.metrics.reason`; admin comparison shows ≥ 2 versions for `item-cf`.
- [ ] Ask the user to run the full `php artisan test --compact`.
