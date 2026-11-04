"""The job registry, an in-memory runner for it, and the training jobs themselves."""

import threading
import uuid
from collections.abc import Callable
from concurrent.futures import ThreadPoolExecutor
from datetime import UTC, datetime
from typing import Any

from psycopg.types.json import Jsonb

from app.settings import get_settings
from bookflix_ml import artifacts, db, item_cf, lsa, registry
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
}
