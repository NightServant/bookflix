"""`build-similarities` with method `embedding`: neighbors from the active embedding version's vectors."""

import numpy as np
import pytest

from bookflix_ml.jobs import run_build_similarities
from bookflix_ml.similarities import write
from tests.db_helpers import Conn, count, seed_media

pytestmark = pytest.mark.db


def version(
    db: Conn, status: str = "active", name: str = "embedding", kind: str = "embedding"
) -> int:
    row = db.execute(
        "insert into model_versions (name, version, kind, status) values (%s, %s, %s, %s) returning id",
        (name, f"v{count(db, 'model_versions')}", kind, status),
    ).fetchone()
    db.commit()
    assert row is not None
    return int(row[0])


def embed(db: Conn, version_id: int, vectors: dict[int, list[float]]) -> None:
    for media_id, head in vectors.items():
        vector = np.zeros(384, dtype=np.float32)
        vector[: len(head)] = head
        db.execute(
            "insert into media_embeddings (media_id, model_version_id, embedding, content_hash) values (%s, %s, %s, %s)",
            (media_id, version_id, vector, "h" * 64),
        )
    db.commit()


def rows(db: Conn, method: str = "embedding") -> set[tuple[int, int, int]]:
    return {
        (int(a), int(b), int(c))
        for a, b, c in db.execute(
            "select media_id, similar_media_id, model_version_id from media_similarities where method = %s",
            (method,),
        ).fetchall()
    }


def test_writes_the_neighbors_of_the_active_embedding_version(db: Conn) -> None:
    seed_media(db, 1, 2, 3)
    v = version(db)
    embed(db, v, {1: [1.0, 0.0], 2: [0.9, 0.1], 3: [0.0, 1.0]})

    metrics = run_build_similarities(db, {"methods": ["embedding"]})

    assert metrics == {"written": {"embedding": 6}, "skipped": []}
    assert (1, 2, v) in rows(db) and (2, 1, v) in rows(db)
    best = db.execute(
        "select similar_media_id from media_similarities where media_id = 1 order by score desc limit 1"
    ).fetchone()
    assert best == (2,)


def test_same_media_is_never_its_own_neighbor(db: Conn) -> None:
    seed_media(db, 1, 2, 3)
    v = version(db)
    # Identical vectors: each is as close to itself as to the others.
    embed(db, v, {1: [1.0, 0.0], 2: [1.0, 0.0], 3: [1.0, 0.0]})

    run_build_similarities(db, {"methods": ["embedding"]})

    assert rows(db) == {(a, b, v) for a in (1, 2, 3) for b in (1, 2, 3) if a != b}


def test_a_media_keeps_at_most_fifty_neighbors(db: Conn) -> None:
    ids = list(range(1, 61))
    seed_media(db, *ids)
    v = version(db)
    rng = np.random.default_rng(0)
    embed(db, v, {m: list(rng.normal(size=8)) for m in ids})

    run_build_similarities(db, {"methods": ["embedding"]})

    per_media = db.execute(
        "select max(n) from (select count(*) as n from media_similarities group by media_id) c"
    ).fetchone()
    assert per_media == (50,)


def test_only_the_active_version_is_built_and_retired_or_candidate_vectors_are_ignored(
    db: Conn,
) -> None:
    seed_media(db, 1, 2, 3)
    retired, active, candidate = version(db, "retired"), version(db), version(db, "candidate")
    embed(db, retired, {1: [1.0, 0.0], 3: [1.0, 0.0]})
    embed(db, active, {1: [1.0, 0.0], 2: [1.0, 0.1]})
    embed(db, candidate, {2: [0.0, 1.0], 3: [0.0, 1.0]})

    run_build_similarities(db, {"methods": ["embedding"]})

    assert rows(db) == {(1, 2, active), (2, 1, active)}


def test_soft_deleted_and_merged_media_are_left_out(db: Conn) -> None:
    seed_media(db, 1, 2, 3, 4)
    v = version(db)
    embed(db, v, {1: [1.0, 0.0], 2: [1.0, 0.1], 3: [1.0, 0.2], 4: [1.0, 0.3]})
    db.execute("update media set deleted_at = now() where id = 3")
    db.execute("update media set merged_into_id = 1 where id = 4")
    db.commit()

    run_build_similarities(db, {"methods": ["embedding"]})

    assert rows(db) == {(1, 2, v), (2, 1, v)}


def test_a_rerun_replaces_its_own_rows_and_leaves_other_methods_alone(db: Conn) -> None:
    seed_media(db, 1, 2, 3)
    v, lsa = version(db), version(db, name="lsa", kind="lsa")
    write(db, "lsa", lsa, {1: [(3, 0.5)]})
    write(db, "embedding", v, {1: [(3, 0.9)]})
    db.commit()
    embed(db, v, {1: [1.0, 0.0], 2: [1.0, 0.1]})

    run_build_similarities(db, {"methods": ["embedding"]})

    assert rows(db) == {(1, 2, v), (2, 1, v)}
    assert rows(db, "lsa") == {(1, 3, lsa)}


def test_no_active_version_or_no_vectors_does_nothing_and_succeeds(db: Conn) -> None:
    seed_media(db, 1, 2)
    candidate = version(db, "candidate")
    embed(db, candidate, {1: [1.0, 0.0], 2: [1.0, 0.0]})

    assert run_build_similarities(db, {"methods": ["embedding"]}) == {
        "written": {},
        "skipped": ["embedding"],
    }

    version(db)
    assert run_build_similarities(db, {"methods": ["embedding"]}) == {
        "written": {"embedding": 0},
        "skipped": [],
    }
    assert count(db, "media_similarities") == 0


def test_a_version_id_must_be_the_active_embedding_version(db: Conn) -> None:
    seed_media(db, 1, 2)
    retired, active = version(db, "retired"), version(db)
    lsa = version(db, name="lsa", kind="lsa")
    embed(db, active, {1: [1.0, 0.0], 2: [1.0, 0.0]})

    for bad in (retired, lsa, 99999):
        with pytest.raises(ValueError, match="active"):
            run_build_similarities(db, {"methods": ["embedding"], "version_id": bad})
    assert count(db, "media_similarities") == 0

    run_build_similarities(db, {"methods": ["embedding"], "version_id": active})

    assert rows(db) == {(1, 2, active), (2, 1, active)}


def test_embedding_is_not_built_unless_asked_for(db: Conn) -> None:
    seed_media(db, 1, 2)
    v = version(db)
    embed(db, v, {1: [1.0, 0.0], 2: [1.0, 0.0]})

    metrics = run_build_similarities(db, {})

    assert "embedding" not in metrics["written"] and "embedding" not in metrics["skipped"]
    assert rows(db) == set()
