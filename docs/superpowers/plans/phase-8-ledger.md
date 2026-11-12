# Phase 8 ledger (advanced recommendations)

Plan: `2026-10-23-phase-8-advanced-recommendations.md` on `claude/phase-plans-7d02cb`, with the **Revision 2026-11-12** at its top (it wins over older text). Branch `phase-8-advanced`, cut from the tip of `phase-7-semantic`; its PR opens after Phase 7 merges.

## Conflict scan (against `phase-7-semantic` at bf74fbc; the branch was cut at 4ce0baa)

- **Gate failure.** The plan says a job below its gate raises `NotEnoughData` and registers no version. The train jobs create the version first and retire it on failure, and `RunMlJob` records a failed row when a train step returns no `version_id`. A nightly job below its gate would show a red run every night.
- **Config.** `recommendations.als.*` in `config/bookflix.php` cannot reach Python: `PIPELINES` is a constant, and item-cf keeps its settings in Python and `parameters`.
- **Similarities and evaluation.** `build-similarities` and `evaluate` hard-code their methods (`item_cf`, `lsa`). `evaluate` refuses a version with no similarity rows, so LTR needs its own branch.
- **Logs.** `recommendations` has no position column. `recommendation_events` holds impression, click, dismiss and library_add; `reading_start` is in `user_interactions`. Impressions are one event per page view on rows cached for 10 minutes. Rows older than 90 days survive only with a dismissal.
- **LTR features.** The top 300 hybrid candidates and their features are computed in PHP, so Python cannot build them for scoring.
- **Ranker.** `ShelfBuilder` injects `HybridRanker` directly; rows are logged under the `hybrid-weights` version; `HybridRanker`'s collaborative feature reads only `item_cf`.
- **Metrics.** `metrics_at_k` already reports `diversity` and `novelty`.
- **Bandit.** Only candidate shelves are logged; collection shelves, Continue Reading and Explore by Subject write no rows. Shelf order is set in `ShelfBuilder::forHome` and in the Home page's `layout()`, which places staff picks and Your Collections by a signed design decision.
- **Admin.** Admin routes are all `Route::livewire`; there are no admin controllers. `trained_at` is null on versions that failed. Vite has fixed inputs.
- **Dependencies.** `implicit`, `pandas`, `scikit-learn` are installed. New: `lightgbm`, `chart.js` (both named by the plan, A4).

## Rulings

| # | Ruling | Cost if wrong |
|---|---|---|
| P8-R1 | A gate not met is a successful run with `metrics.skipped = {reason, have, need}` and no version; `RunMlJob` ends the pipeline there. Gate numbers are job parameters held in one place in PHP. | A gate that never opens stays quiet. The readiness command and the run's skip reason on the models page show it. |
| P8-R2 | LTR trains on the features logged at serving time. For scoring, a queued PHP job writes each active reader's top 300 hybrid candidates and features to `ltr_candidates`; Python scores them into `ltr_scores`. | One more table and job; scores are up to a day old. Titles with no score keep their hybrid order. |
| P8-R3 | LTR is promoted only on a positive NDCG@10 delta against the hybrid order on held-out impressions; thin data retires the candidate. | A good LTR waits for the next weekly run. Hybrid keeps serving. |
| P8-R4 | `HybridRanker`'s collaborative feature is the larger of the `item_cf` and `als` neighbour scores. | A weak ALS lifts poor titles into Recommended; bounded by its promotion gate, and retiring the version restores Phase 6 ranking. |
| P8-R5 | A labeled impression is one impressed `recommendations` row; unimpressed rows are left out; a dismissal is grade 0; the export reads the last 90 days only and skips rows younger than 7 days. | The 50,000 gate is reached later than an event count would reach it. |
| P8-R6 | Rows served in LTR order are logged under the LTR version id. | None found: it only adds information. |
| P8-R7 | The bandit reads only the hourly stats cache and samples once per shelf-cache fill. | Shelf order follows a click-rate change up to an hour and ten minutes late. |
| P8-R8 | The bandit reorders `similar-to`, `popular-in-genres` and `discover-new` only. Collections, staff picks and Continue Reading keep their places. | Collection shelves never get exploration. |
| P8-R9 | No `ModelMetricsController`; the Livewire page passes the series. Chart.js loads by dynamic import; the table always renders. | None found. |

## Deferred

- LTR's first real training run must use logs from after Phase 7's 7.5 only (embedding neighbours change `content_similarity`).
- Rows logged before `recommendations.position` exists are not used for training.

## Tasks

| Task | Commits | Status | Deviations and open findings |
|---|---|---|---|
