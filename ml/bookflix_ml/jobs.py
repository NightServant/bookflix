"""The job registry, an in-memory runner for it, and the training jobs themselves."""

import threading
import uuid
from collections.abc import Callable
from concurrent.futures import ThreadPoolExecutor
from datetime import UTC, datetime
from typing import Any

from psycopg.types.json import Jsonb

from app.settings import get_settings
from bookflix_ml import artifacts, db, evaluate, item_cf, lsa, popularity, registry, similarities
from bookflix_ml.db import Conn

CHUNK = 1000
KEEP_RUNS = 500


class UnknownJob(Exception):
    """No job is registered under that name."""


def _iso() -> str:
    return datetime.now(UTC).isoformat(timespec="seconds")


class JobRunner:
    """Runs registered jobs one at a time on a worker thread and remembers their outcome in memory.

    Laravel keeps the durable record (`ml_job_runs`); this only answers its polling, so a restart losing the runs is fine.
    `inline` runs the job in the caller's thread, for tests.
    """

    def __init__(self, inline: bool = False) -> None:
        self._inline = inline
        self._pool = ThreadPoolExecutor(max_workers=1, thread_name_prefix="ml-job")
        self._runs: dict[str, dict[str, Any]] = {}
        self._lock = threading.Lock()

    def submit(self, name: str, params: dict[str, Any]) -> str:
        job = JOBS.get(name)
        if job is None:
            raise UnknownJob(name)

        run_id = str(uuid.uuid4())
        with self._lock:
            self._runs[run_id] = {
                "status": "queued",
                "started_at": _iso(),
                "finished_at": None,
                "metrics": None,
                "error": None,
            }
            for old in list(self._runs)[:-KEEP_RUNS]:
                del self._runs[old]

        if self._inline:
            self._run(run_id, job, params)
        else:
            self._pool.submit(self._run, run_id, job, params)

        return run_id

    def get(self, run_id: str) -> dict[str, Any] | None:
        with self._lock:
            run = self._runs.get(run_id)
            return None if run is None else dict(run)

    def _update(self, run_id: str, **fields: Any) -> None:
        with self._lock:
            self._runs[run_id].update(fields)

    def _run(
        self, run_id: str, job: Callable[[dict[str, Any]], dict[str, Any]], params: dict[str, Any]
    ) -> None:
        self._update(run_id, status="running")
        try:
            metrics = job(params)
        except Exception as e:  # noqa: BLE001 - whatever a job raises is reported, never lost on the worker thread
            self._update(
                run_id, status="failed", finished_at=_iso(), error=str(e) or type(e).__name__
            )
        else:
            self._update(run_id, status="succeeded", finished_at=_iso(), metrics=metrics)


def run_train_lsa(conn: Conn, params: dict[str, Any]) -> dict[str, Any]:
    """Train TF-IDF + LSA on the live media and store the vectors under a new `candidate` version.

    The version row is committed first as `training`, so a failure anywhere is recorded as `retired`. Everything the reader-facing tables
    get (vectors, features, the version turning `candidate`) goes in one transaction in chunks, so a failure leaves none of it visible.
    Other versions, the active one included, are never touched.
    """
    settings = get_settings()
    version_id, version = registry.create_version(
        conn, "lsa", "lsa", {"components": settings.lsa_components}, "media:live"
    )
    conn.commit()

    try:
        result = lsa.train_lsa(db.fetch_corpus(conn), settings.lsa_components)
        uri = artifacts.save({"vectorizer": result.vectorizer, "svd": result.svd}, "lsa", version)
        metrics: dict[str, Any] = {
            "vocab_size": result.vocab_size,
            "explained_variance": result.explained_variance,
            "n_docs": len(result.media_ids),
        }

        with conn.cursor() as cur:
            for start in range(0, len(result.media_ids), CHUNK):
                ids = result.media_ids[start : start + CHUNK]
                cur.executemany(
                    "insert into media_lsa_vectors (media_id, model_version_id, vector) values (%s, %s, %s)",
                    [(m, version_id, result.vectors[start + i]) for i, m in enumerate(ids)],
                )
                cur.executemany(
                    "insert into media_features (media_id, model_version_id, top_terms, computed_at) values (%s, %s, %s, now())",
                    [(m, version_id, Jsonb(result.top_terms[m])) for m in ids],
                )

        registry.finish_version(conn, version_id, "candidate", metrics, uri)
        conn.commit()
    except BaseException as e:
        conn.rollback()
        registry.finish_version(conn, version_id, "retired", {"error": str(e) or type(e).__name__})
        conn.commit()
        raise

    return metrics


def run_train_item_cf(conn: Conn, params: dict[str, Any]) -> dict[str, Any]:
    """Fit item-item neighbors on the eligible readers' decayed interactions and store them as a `candidate` `item-cf` version.

    Only the artifact is written here; the neighbors reach `media_similarities` in `build-similarities`. A failure retires the version.
    """
    k = int(params.get("k", 50))
    weights = db.fetch_interaction_weights(conn)
    version_id, version = registry.create_version(
        conn, "item-cf", "item_cf", {"weights": weights, "k": k}, "user_interactions:eligible"
    )
    conn.commit()

    try:
        scores = item_cf.interaction_scores(db.fetch_interactions(conn), weights, datetime.now(UTC))
        neighbors = item_cf.item_neighbors(scores, k)
        uri = artifacts.save({"neighbors": neighbors, "k": k}, "item-cf", version)
        counts = {
            "n_users": len({u for u, _ in scores}),
            "n_items": len({i for _, i in scores}),
        }
        registry.finish_version(
            conn,
            version_id,
            "candidate",
            {**counts, "n_neighbors": sum(map(len, neighbors.values()))},
            uri,
            counts,
        )
        conn.commit()
    except BaseException as e:
        conn.rollback()
        registry.finish_version(conn, version_id, "retired", {"error": str(e) or type(e).__name__})
        conn.commit()
        raise

    return {**counts, "n_items_with_neighbors": len(neighbors)}


def run_build_similarities(conn: Conn, params: dict[str, Any]) -> dict[str, Any]:
    """Write the top-50 neighbors of the newest candidate-or-active `lsa` and `item-cf` versions to `media_similarities`.

    Each method goes in its own transaction under its own version id, so one failing leaves the other's rows and the old rows of the
    failing one untouched. A method with no version yet is skipped. Neighbors of media that are no longer live are dropped.
    """
    methods = list(params.get("methods") or ["lsa", "item_cf"])
    unknown = sorted(set(methods) - {"lsa", "item_cf"})
    if unknown:
        raise ValueError(f"Unknown similarity method: {', '.join(unknown)}")

    metrics: dict[str, Any] = {"written": {}, "skipped": []}
    for method in methods:
        found = registry.latest_version(conn, "lsa" if method == "lsa" else "item-cf")
        if found is None:
            metrics["skipped"].append(method)
            continue
        version_id, uri = found

        try:
            if method == "lsa":
                ids, vectors = db.fetch_lsa_vectors(conn, version_id)
                neighbors = similarities.top_k_cosine(ids, vectors, 50)
            else:
                if uri is None:
                    raise RuntimeError("The item-cf version has no artifact.")
                live = db.fetch_live_media_ids(conn)
                stored = artifacts.load(uri)["neighbors"]
                neighbors = {
                    m: [(n, s) for n, s in pairs if n in live]
                    for m, pairs in stored.items()
                    if m in live
                }
            metrics["written"][method] = similarities.write(conn, method, version_id, neighbors)
            conn.commit()
        except BaseException:
            conn.rollback()
            raise

    return metrics


def run_refresh_popularity(conn: Conn, params: dict[str, Any]) -> dict[str, Any]:
    popularity.refresh(conn)
    row = conn.execute("select count(*) from media_popularity").fetchone()

    return {"media": int(row[0]) if row else 0}


EVAL_KINDS = {"item_cf": "item-cf", "lsa": "lsa"}
EVAL_K = 10
# A single held-out reader says nothing about a model, so below this the evaluation records "not enough data" and promotes nothing.
MIN_EVAL_USERS = 2


def _rank_for_user(
    seen: set[int], neighbors: dict[int, list[tuple[int, float]]], k: int
) -> list[int]:
    """The top `k` items by summed similarity to what the user already has, never one they have."""
    scores: dict[int, float] = {}
    for item in seen:
        for similar, score in neighbors.get(item, []):
            if similar not in seen:
                scores[similar] = scores.get(similar, 0.0) + score

    return [m for m, _ in sorted(scores.items(), key=lambda p: (-p[1], p[0]))[:k]]


def run_evaluate(conn: Conn, params: dict[str, Any]) -> dict[str, Any]:
    """Score the newest `candidate` of `kind` against each eligible reader's last positive item and promote it if the gate allows.

    Candidates are ranked from their `media_similarities` rows (so `build-similarities` must have run). Interactions come from the same
    eligible-reader export as training. The metrics, `promoted` and `reason` are stored on the candidate, and the promotion goes in
    that same transaction. Too little data (fewer than two readers with two positive items, no rows) is recorded and promotes nothing. Any failure
    rolls back and re-raises, so no version or row changes; the job runner records the failure.
    """
    kind = str(params.get("kind", ""))
    if kind not in EVAL_KINDS:
        raise ValueError(
            f"Unknown evaluation kind: kind must be one of {', '.join(sorted(EVAL_KINDS))}."
        )

    found = registry.latest_candidate(conn, EVAL_KINDS[kind])
    if found is None:
        return {"promoted": False, "reason": "no candidate"}
    version_id, method = found

    try:
        weights = db.fetch_interaction_weights(conn)
        now = datetime.now(UTC)
        interactions = db.fetch_interactions(conn)
        positive = item_cf.interaction_scores(interactions, weights, now)
        last_seen: dict[tuple[int, int], datetime] = {}
        for user, media, _event, _value, at in interactions:
            if (user, media) in positive:
                last_seen[(user, media)] = max(at, last_seen.get((user, media), at))

        train, held = evaluate.leave_last_out((u, m, at) for (u, m), at in last_seen.items())
        neighbors = db.fetch_similarity_rows(conn, version_id, method)

        outcome: dict[str, Any] = {"n_eval_users": len(held)}
        if len(held) < MIN_EVAL_USERS or not neighbors:
            outcome |= {"promoted": False, "reason": "not enough data"}
            registry.record_metrics(conn, version_id, outcome)
            conn.commit()
            return outcome

        recommended = {u: _rank_for_user(train[u], neighbors, EVAL_K) for u in held}
        item_pop: dict[int, int] = {}
        for _user, media in positive:
            item_pop[media] = item_pop.get(media, 0) + 1
        lookup = {(a, b): s for a, pairs in neighbors.items() for b, s in pairs}

        metrics = evaluate.metrics_at_k(
            recommended,
            {u: {m} for u, m in held.items()},
            EVAL_K,
            catalog_size=len(db.fetch_live_media_ids(conn)),
            item_pop=item_pop,
            item_sim=lambda a, b: max(lookup.get((a, b), 0.0), lookup.get((b, a), 0.0)),
            n_users=len({u for u, _ in positive}),
        )
        active = registry.active_metrics(conn, EVAL_KINDS[kind])
        promote = evaluate.should_promote(metrics, active)
        if promote:
            reason = "no active version" if active is None else "passed the gate"
        else:
            reason = "NDCG@10 fell by more than 2% or coverage fell against the active version"

        outcome |= metrics | {"promoted": promote, "reason": reason}
        registry.record_metrics(conn, version_id, outcome)
        if promote:
            registry.promote(conn, version_id)
        conn.commit()
    except BaseException:
        conn.rollback()
        raise

    return outcome


def _with_connection(
    job: Callable[[Conn, dict[str, Any]], dict[str, Any]],
) -> Callable[[dict[str, Any]], dict[str, Any]]:
    def run(params: dict[str, Any]) -> dict[str, Any]:
        with db.connect() as conn:
            return job(conn, params)

    return run


JOBS: dict[str, Callable[[dict[str, Any]], dict[str, Any]]] = {
    "train-lsa": _with_connection(run_train_lsa),
    "train-item-cf": _with_connection(run_train_item_cf),
    "build-similarities": _with_connection(run_build_similarities),
    "refresh-popularity": _with_connection(run_refresh_popularity),
    "evaluate": _with_connection(run_evaluate),
}
