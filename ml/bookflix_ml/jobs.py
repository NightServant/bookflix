"""The job registry, an in-memory runner for it, and the training jobs themselves."""

import hashlib
import threading
import uuid
from collections.abc import Callable, Iterator
from concurrent.futures import ThreadPoolExecutor
from datetime import UTC, datetime
from typing import Any

from psycopg.types.json import Jsonb

from app.settings import get_settings
from bookflix_ml import (
    artifacts,
    db,
    embeddings,
    evaluate,
    item_cf,
    lsa,
    popularity,
    registry,
    similarities,
)
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

    return {**metrics, "version_id": version_id}


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

    return {**counts, "n_items_with_neighbors": len(neighbors), "version_id": version_id}


def run_build_similarities(conn: Conn, params: dict[str, Any]) -> dict[str, Any]:
    """Write the top-50 neighbors of the newest candidate-or-active `lsa` and `item-cf` versions to `media_similarities`.

    With a `version_id` (the one its `train` step reported, so overlapping pipelines do not mix versions) it builds exactly that version
    instead, which must be a `candidate` of the one method in `methods`; anything else fails the job.

    Each method goes in its own transaction under its own version id, so one failing leaves the other's rows and the old rows of the
    failing one untouched. A method with no version yet is skipped. Neighbors of media that are no longer live are dropped.
    """
    methods = list(params.get("methods") or ["lsa", "item_cf"])
    unknown = sorted(set(methods) - {"lsa", "item_cf"})
    if unknown:
        raise ValueError(f"Unknown similarity method: {', '.join(unknown)}")

    pinned = registry.parse_version_id(params)
    if pinned is not None and len(methods) != 1:
        raise ValueError("version_id needs exactly one method.")

    metrics: dict[str, Any] = {"written": {}, "skipped": []}
    for method in methods:
        name = "lsa" if method == "lsa" else "item-cf"
        if pinned is None:
            found = registry.latest_version(conn, name)
            if found is None:
                metrics["skipped"].append(method)
                continue
            version_id, uri = found
        else:
            version_id, _, uri = registry.require_candidate(conn, pinned, name)

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


EMBED_CHUNK = 256


def _embed_missing(
    conn: Conn, embedder: embeddings.Embedder, version_id: int, docs: list[dict[str, Any]]
) -> int:
    """Embed the media that have no row under `version_id`, or one made from other text, committing every `EMBED_CHUNK` media.

    Each chunk is committed on its own, so a failure keeps what was already written and the next run starts where this one stopped.
    """
    stored = dict(
        conn.execute(
            "select media_id, content_hash from media_embeddings where model_version_id = %s",
            (version_id,),
        ).fetchall()
    )
    texts = {doc["id"]: embeddings.passage_text(doc) for doc in docs}
    hashes = {m: hashlib.sha256(text.encode()).hexdigest() for m, text in texts.items()}
    todo = [m for m in texts if stored.get(m) != hashes[m]]

    for start in range(0, len(todo), EMBED_CHUNK):
        ids = todo[start : start + EMBED_CHUNK]
        try:
            vectors = embeddings.embed(embedder, [texts[m] for m in ids], "passage")
            with conn.cursor() as cur:
                cur.executemany(
                    """
                    insert into media_embeddings (media_id, model_version_id, embedding, content_hash)
                    values (%s, %s, %s, %s)
                    on conflict (media_id, model_version_id)
                    do update set embedding = excluded.embedding, content_hash = excluded.content_hash
                    """,
                    [(m, version_id, vectors[i], hashes[m]) for i, m in enumerate(ids)],
                )
            conn.commit()
        except BaseException:
            conn.rollback()
            raise

    return len(todo)


def run_embed_media(conn: Conn, params: dict[str, Any]) -> dict[str, Any]:
    """Keep `media_embeddings` current for the live media with the model this service has loaded.

    Incremental when the active `embedding` version was made by that model (same name, revision and dims): media with no row, or whose
    text changed since, are embedded under the active version. Otherwise in full: every live media is embedded under a `candidate`
    (the newest candidate made by the same model is resumed, so a failed run is not started over), and only when all are written is
    the candidate promoted, in one transaction that retires the old version and deletes its embeddings and `embedding` similarities.
    A failure leaves the active version and its rows as they were. No model loaded fails the job before anything is written.
    """
    embedder = embeddings.current()
    if embedder.dims != embeddings.DIMS:
        raise ValueError(
            f"media_embeddings holds {embeddings.DIMS}-dimensional vectors; a model with {embedder.dims} needs its own table."
        )

    model = {"model": embedder.name, "revision": embedder.revision, "dims": embedder.dims}
    docs = db.fetch_corpus(conn)
    active = registry.active_version(conn, "embedding")

    if active is not None and all(active[2].get(k) == v for k, v in model.items()):
        embedded = _embed_missing(conn, embedder, active[0], docs)

        return {
            "mode": "incremental",
            "embedded": embedded,
            "n_media": len(docs),
            "version_id": active[0],
        }

    version_id = registry.candidate_with_parameters(conn, "embedding", model)
    if version_id is None:
        version_id, _ = registry.create_version(conn, "embedding", "embedding", model, "media:live")
        registry.finish_version(conn, version_id, "candidate", {})
        conn.commit()

    embedded = _embed_missing(conn, embedder, version_id, docs)
    metrics = {"mode": "full", "embedded": embedded, "n_media": len(docs)}
    try:
        registry.record_metrics(conn, version_id, metrics)
        registry.promote(conn, version_id)
        conn.commit()
    except BaseException:
        conn.rollback()
        raise

    return {**metrics, "version_id": version_id}


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


def _held_out_neighbors(
    conn: Conn, positive: dict[tuple[int, int], float], held: dict[int, int], k: int
) -> dict[int, list[tuple[int, float]]]:
    """Item-cf neighbors fitted in memory on `positive` minus each reader's held-out item. Only live media are kept, as in `build-similarities`."""
    training = {key: s for key, s in positive.items() if held.get(key[0]) != key[1]}
    live = db.fetch_live_media_ids(conn)

    return {
        m: [(n, s) for n, s in pairs if n in live]
        for m, pairs in item_cf.item_neighbors(training, k).items()
        if m in live
    }


def _neighbors_of(
    conn: Conn,
    kind: str,
    version_id: int,
    method: str,
    parameters: dict[str, Any],
    positive_for: Callable[[dict[str, Any]], dict[tuple[int, int], float]],
    held: dict[int, int],
    needed: set[int],
    fallback: tuple[dict[str, Any], int],
) -> dict[int, list[tuple[int, float]]]:
    """The neighbors one version is scored on: `item_cf` refits in memory on the training split with the version's own weights and `k`
    (`fallback` when it stored none), `lsa` reads the version's stored rows, only those of the `needed` source items."""
    if kind != "item_cf":
        return db.fetch_similarity_rows(conn, version_id, method, media_ids=needed)

    positive = positive_for(parameters.get("weights") or fallback[0])

    return _held_out_neighbors(conn, positive, held, int(parameters.get("k", fallback[1])))


def run_evaluate(conn: Conn, params: dict[str, Any]) -> dict[str, Any]:
    """Score the `candidate` of `kind` (the one `version_id` names, else the newest) against each eligible reader's last positive item
    and promote it if the gate allows.

    Interactions come from the same eligible-reader export as training. An `item_cf` model is scored on neighbors trained in memory
    on the leave-last-out training split only, with its own weights and `k`, so a held-out item never helped build the neighbors it is
    scored on; nothing is written to `media_similarities` for that (promotion still uses the stored candidate). `lsa` learns from item
    text, not interactions, so it cannot leak a held-out interaction and is ranked from its stored `media_similarities` rows (so
    `build-similarities` must have run).

    The gate compares against the active version scored now, on the same split in the same run (item_cf refit with the active version's
    parameters, lsa from its stored rows), never against metrics recorded at some earlier time. Both metric sets, `promoted` and `reason`
    are stored on the candidate, and the promotion goes in that same transaction. A candidate with no `media_similarities` rows is never
    promoted. With too little data (fewer than two readers with two positive items, or no neighbors) nothing can be compared, so the
    candidate is promoted (no evidence blocks it, and a frozen model would never get neighbors for new media). A candidate the gate
    refuses on its metrics is retired and its rows deleted in the same transaction. Any failure rolls back and re-raises, so no version or row changes;
    the job runner records the failure.
    """
    kind = str(params.get("kind", ""))
    if kind not in EVAL_KINDS:
        raise ValueError(
            f"Unknown evaluation kind: kind must be one of {', '.join(sorted(EVAL_KINDS))}."
        )

    pinned = registry.parse_version_id(params)
    if pinned is None:
        found = registry.latest_candidate(conn, EVAL_KINDS[kind])
        if found is None:
            return {"promoted": False, "reason": "no candidate"}
        version_id, method = found
    else:
        version_id, method, _ = registry.require_candidate(conn, pinned, EVAL_KINDS[kind])

    try:
        parameters = registry.version_parameters(conn, version_id)
        # The candidate's own weights (decay half-life included), so the split and the in-memory model match what it was trained on.
        weights = parameters.get("weights") or db.fetch_interaction_weights(conn)
        k = int(parameters.get("k", 50))
        now = datetime.now(UTC)

        # One streamed pass folds both the scores and each pair's latest time, so the interaction rows are never held at once.
        latest: dict[tuple[int, int], datetime] = {}

        def tracked() -> Iterator[tuple[int, int, str, float | None, datetime]]:
            for row in db.fetch_interactions(conn):
                key = (row[0], row[1])
                latest[key] = max(row[4], latest.get(key, row[4]))
                yield row

        positive = item_cf.interaction_scores(tracked(), weights, now)
        last_seen = {key: at for key, at in latest.items() if key in positive}
        latest = {}

        def positive_for(w: dict[str, Any]) -> dict[tuple[int, int], float]:
            # A version with other weights needs its own scores: stream the rows again rather than keep them.
            if w == weights:
                return positive

            return item_cf.interaction_scores(db.fetch_interactions(conn), w, now)

        train, held = evaluate.leave_last_out((u, m, at) for (u, m), at in last_seen.items())
        active = registry.active_version(conn, EVAL_KINDS[kind])
        catalog_size = len(db.fetch_live_media_ids(conn))
        item_pop: dict[int, int] = {}
        for _user, media in positive:
            item_pop[media] = item_pop.get(media, 0) + 1
        n_users = len({u for u, _ in positive})

        # An lsa version's rows are loaded for these source items only, not its whole table.
        needed = {item for u in held for item in train[u]}

        def score(
            neighbors: dict[int, list[tuple[int, float]]], stored: int | None = None
        ) -> dict[str, float]:
            recommended = {u: _rank_for_user(train[u], neighbors, EVAL_K) for u in held}
            if stored is None:
                lookup = {(a, b): s for a, pairs in neighbors.items() for b, s in pairs}
            else:
                # Diversity only asks about pairs inside one list, so that is all of the stored rows it needs.
                shown = {item for items in recommended.values() for item in items}
                pairs = db.fetch_similarity_rows(conn, stored, method, shown, shown)
                lookup = {(a, b): s for a, rows in pairs.items() for b, s in rows}

            return evaluate.metrics_at_k(
                recommended,
                {u: {m} for u, m in held.items()},
                EVAL_K,
                catalog_size=catalog_size,
                item_pop=item_pop,
                item_sim=lambda a, b: max(lookup.get((a, b), 0.0), lookup.get((b, a), 0.0)),
                n_users=n_users,
            )

        stored_rows = kind != "item_cf"
        neighbors = _neighbors_of(
            conn, kind, version_id, method, parameters, positive_for, held, needed, (weights, k)
        )
        metrics_refused = False
        outcome: dict[str, Any] = {"n_eval_users": len(held)}
        if active is not None:
            outcome["active_version_id"] = active[0]

        if len(held) >= MIN_EVAL_USERS and not neighbors and active is not None:
            # Enough readers to compare, yet the candidate ranks nothing for them: that is evidence against it.
            promote, metrics_refused = False, True
            reason = "the candidate had no neighbors for the evaluated readers"
        elif len(held) < MIN_EVAL_USERS or not neighbors:
            # Nothing can be compared, and a frozen model would never get neighbors for new media: the newer version covers more.
            promote = True
            reason = (
                "no active version (not evaluated)"
                if active is None
                else "not enough data to compare; the newer version was promoted"
            )
        else:
            metrics = score(neighbors, version_id if stored_rows else None)
            outcome |= metrics
            if active is None:
                promote, reason = True, "no active version"
            else:
                active_neighbors = _neighbors_of(
                    conn,
                    kind,
                    active[0],
                    method,
                    active[2],
                    positive_for,
                    held,
                    needed,
                    (weights, k),
                )
                # An active version with nothing to rank from (its rows are gone) cannot be a baseline.
                active_scored = (
                    score(active_neighbors, active[0] if stored_rows else None)
                    if active_neighbors
                    else {}
                )
                outcome["active_metrics"] = active_scored
                promote = evaluate.should_promote(metrics, active_scored)
                metrics_refused = not promote
                if promote:
                    reason = (
                        "passed the gate" if active_scored else "active version could not be scored"
                    )
                else:
                    reason = (
                        "NDCG@10 fell by more than 2% or coverage fell against the active version"
                    )
        if promote and not registry.has_similarity_rows(conn, version_id, method):
            promote, reason = False, "no similarity rows (run build-similarities first)"
            metrics_refused = False

        outcome |= {"promoted": promote, "reason": reason}
        registry.record_metrics(conn, version_id, outcome)
        if promote:
            registry.promote(conn, version_id)
        elif metrics_refused:
            registry.retire(conn, version_id)
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
    "embed-media": _with_connection(run_embed_media),
}
