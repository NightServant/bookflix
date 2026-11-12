from typing import Any

import pytest

from bookflix_ml import embeddings
from bookflix_ml.embeddings import EmbedderUnavailable, FakeEmbedder
from bookflix_ml.jobs import run_embed_media
from tests.db_helpers import Conn, count, seed_corpus

pytestmark = pytest.mark.db


def embedding_versions(conn: Conn) -> list[tuple[int, str]]:
    return [
        (int(r[0]), str(r[1]))
        for r in conn.execute(
            "select id, status from model_versions where name = 'embedding' order by id"
        ).fetchall()
    ]


def rows_by_version(conn: Conn) -> dict[int, int]:
    return {
        int(r[0]): int(r[1])
        for r in conn.execute(
            "select model_version_id, count(*) from media_embeddings group by model_version_id"
        ).fetchall()
    }


def passages(fake: FakeEmbedder) -> list[str]:
    return [text for call in fake.calls for text in call]


def test_a_first_run_embeds_every_live_media_under_a_new_active_version(
    db: Conn, corpus: list[dict[str, Any]], fake_embedder: FakeEmbedder
) -> None:
    seed_corpus(db, corpus)
    db.execute("update media set deleted_at = now() where id = 1")
    db.execute("update media set merged_into_id = 2 where id = 3")
    db.commit()

    metrics = run_embed_media(db, {})

    [(version_id, status)] = embedding_versions(db)
    assert status == "active"
    assert metrics["version_id"] == version_id
    assert metrics["mode"] == "full" and metrics["embedded"] == 10
    assert rows_by_version(db) == {version_id: 10}
    ids = {r[0] for r in db.execute("select media_id from media_embeddings").fetchall()}
    assert 1 not in ids and 3 not in ids
    row = db.execute(
        "select kind, parameters, version, trained_at from model_versions where id = %s",
        (version_id,),
    ).fetchone()
    assert row is not None
    assert row[0] == "embedding" and row[3] is not None
    assert row[1] == {"model": fake_embedder.name, "revision": fake_embedder.revision, "dims": 384}
    assert "@" not in row[2]
    assert all(p.startswith("passage: ") for p in passages(fake_embedder))
    assert "passage: Galactic Crown\n" in passages(fake_embedder)[0]


def test_vectors_and_hashes_are_stored_at_384_dimensions(
    db: Conn, corpus: list[dict[str, Any]], fake_embedder: FakeEmbedder
) -> None:
    seed_corpus(db, corpus)
    run_embed_media(db, {})

    row = db.execute(
        "select vector_dims(embedding), length(content_hash) from media_embeddings limit 1"
    ).fetchone()
    assert row == (384, 64)


def test_a_second_run_with_nothing_changed_embeds_nothing(
    db: Conn, corpus: list[dict[str, Any]], fake_embedder: FakeEmbedder
) -> None:
    seed_corpus(db, corpus)
    first = run_embed_media(db, {})
    fake_embedder.calls.clear()

    second = run_embed_media(db, {})

    assert second == {
        "mode": "incremental",
        "embedded": 0,
        "n_media": 12,
        "version_id": first["version_id"],
    }
    assert fake_embedder.calls == []
    assert count(db, "media_embeddings") == 12
    assert len(embedding_versions(db)) == 1


def test_incremental_runs_embed_new_and_changed_media_only(
    db: Conn, corpus: list[dict[str, Any]], fake_embedder: FakeEmbedder
) -> None:
    seed_corpus(db, corpus)
    version_id = run_embed_media(db, {})["version_id"]
    before = db.execute("select embedding from media_embeddings where media_id = 5").fetchone()
    db.execute("update media set description = 'A brand new blurb.' where id = 5")
    db.execute("insert into media (id, title, description) values (99, 'Newcomer', 'Fresh.')")
    db.commit()
    fake_embedder.calls.clear()

    metrics = run_embed_media(db, {})

    assert metrics["mode"] == "incremental" and metrics["embedded"] == 2
    assert metrics["version_id"] == version_id
    assert {p.split("\n")[0] for p in passages(fake_embedder)} == {
        "passage: Newcomer",
        f"passage: {corpus[4]['title']}",
    }
    assert rows_by_version(db) == {version_id: 13}
    after = db.execute("select embedding from media_embeddings where media_id = 5").fetchone()
    assert before is not None and after is not None
    assert not (before[0].to_numpy() == after[0].to_numpy()).all()


def test_a_new_model_revision_runs_in_full_and_leaves_only_its_own_rows(
    db: Conn,
    corpus: list[dict[str, Any]],
    fake_embedder: FakeEmbedder,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Review Focus #3: once the new version is active, no vector of the old model is left to mix with it."""
    seed_corpus(db, corpus)
    old = run_embed_media(db, {})["version_id"]
    others = {}
    for name, method in (("lsa", "lsa"), ("item-cf", "item_cf")):
        row = db.execute(
            "insert into model_versions (name, version, kind, status) values (%s, 'v1', %s, 'active') returning id",
            (name, method),
        ).fetchone()
        assert row is not None
        others[method] = int(row[0])
    for method, version_id in [("embedding", old), *others.items()]:
        db.execute(
            "insert into media_similarities (media_id, similar_media_id, method, score, model_version_id) values (1, 2, %s, 0.9, %s)",
            (method, version_id),
        )
    db.commit()
    monkeypatch.setattr(embeddings, "_loaded", FakeEmbedder(revision="newer"))

    metrics = run_embed_media(db, {})

    new = metrics["version_id"]
    assert metrics["mode"] == "full" and metrics["embedded"] == 12
    assert embedding_versions(db) == [(old, "retired"), (new, "active")]
    assert rows_by_version(db) == {new: 12}
    # Only the retired version's embedding neighbors go; lsa and item_cf neighbors survive the promotion.
    assert db.execute(
        "select method, model_version_id from media_similarities order by method"
    ).fetchall() == [("item_cf", others["item_cf"]), ("lsa", others["lsa"])]


def test_a_failed_full_run_leaves_the_active_version_alone_and_the_next_run_resumes(
    db: Conn,
    corpus: list[dict[str, Any]],
    fake_embedder: FakeEmbedder,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    seed_corpus(db, corpus)
    old = run_embed_media(db, {})["version_id"]
    monkeypatch.setattr("bookflix_ml.jobs.EMBED_CHUNK", 5)
    flaky = FakeEmbedder(revision="newer", fail_after=1)
    monkeypatch.setattr(embeddings, "_loaded", flaky)

    with pytest.raises(RuntimeError, match="encoder failed"):
        run_embed_media(db, {})

    [(_, old_status), (candidate, candidate_status)] = embedding_versions(db)
    assert (old_status, candidate_status) == ("active", "candidate")
    assert rows_by_version(db) == {old: 12, candidate: 5}

    flaky.fail_after = None
    flaky.calls.clear()
    metrics = run_embed_media(db, {})

    assert metrics["version_id"] == candidate and metrics["embedded"] == 7
    assert len(passages(flaky)) == 7
    assert embedding_versions(db) == [(old, "retired"), (candidate, "active")]
    assert rows_by_version(db) == {candidate: 12}


def test_a_failed_incremental_run_keeps_what_it_wrote_under_the_active_version(
    db: Conn,
    corpus: list[dict[str, Any]],
    fake_embedder: FakeEmbedder,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    seed_corpus(db, corpus)
    version_id = run_embed_media(db, {})["version_id"]
    db.execute("update media set description = description || ' More.'")
    db.commit()
    monkeypatch.setattr("bookflix_ml.jobs.EMBED_CHUNK", 5)
    fake_embedder.fail_after = 1

    with pytest.raises(RuntimeError):
        run_embed_media(db, {})

    assert embedding_versions(db) == [(version_id, "active")]
    assert rows_by_version(db) == {version_id: 12}


def embedded_media(conn: Conn, version_id: int) -> set[int]:
    return {
        int(r[0])
        for r in conn.execute(
            "select media_id from media_embeddings where model_version_id = %s", (version_id,)
        ).fetchall()
    }


def test_an_incremental_run_drops_the_rows_of_media_no_longer_live_and_only_its_versions(
    db: Conn,
    corpus: list[dict[str, Any]],
    fake_embedder: FakeEmbedder,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    seed_corpus(db, corpus)
    active = run_embed_media(db, {})["version_id"]
    # A waiting candidate of another model, left by a failed full run.
    monkeypatch.setattr("bookflix_ml.jobs.EMBED_CHUNK", 5)
    monkeypatch.setattr(embeddings, "_loaded", FakeEmbedder(revision="newer", fail_after=1))
    with pytest.raises(RuntimeError):
        run_embed_media(db, {})
    [_, (candidate, _)] = embedding_versions(db)
    monkeypatch.setattr(embeddings, "_loaded", fake_embedder)
    db.execute("update media set deleted_at = now() where id = 1")
    db.execute("update media set merged_into_id = 2 where id = 3")
    db.commit()

    metrics = run_embed_media(db, {})

    assert metrics["mode"] == "incremental" and metrics["version_id"] == active
    assert embedded_media(db, active) == set(range(1, 13)) - {1, 3}
    assert embedded_media(db, candidate) == {1, 2, 3, 4, 5}


def test_a_resumed_full_run_does_not_carry_media_no_longer_live_into_its_candidate(
    db: Conn,
    corpus: list[dict[str, Any]],
    fake_embedder: FakeEmbedder,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    seed_corpus(db, corpus)
    run_embed_media(db, {})
    monkeypatch.setattr("bookflix_ml.jobs.EMBED_CHUNK", 5)
    flaky = FakeEmbedder(revision="newer", fail_after=1)
    monkeypatch.setattr(embeddings, "_loaded", flaky)
    with pytest.raises(RuntimeError):
        run_embed_media(db, {})
    [_, (candidate, _)] = embedding_versions(db)
    assert embedded_media(db, candidate) == {1, 2, 3, 4, 5}
    db.execute("update media set deleted_at = now() where id = 1")
    db.execute("update media set merged_into_id = 2 where id = 3")
    db.commit()

    flaky.fail_after = None
    metrics = run_embed_media(db, {})

    assert metrics["version_id"] == candidate and metrics["mode"] == "full"
    assert embedded_media(db, candidate) == set(range(1, 13)) - {1, 3}
    assert rows_by_version(db) == {candidate: 10}


def test_without_a_loaded_model_the_job_fails_and_writes_nothing(
    db: Conn, corpus: list[dict[str, Any]], monkeypatch: pytest.MonkeyPatch
) -> None:
    seed_corpus(db, corpus)
    monkeypatch.setattr(embeddings, "_loaded", None)

    with pytest.raises(EmbedderUnavailable):
        run_embed_media(db, {})

    assert embedding_versions(db) == []
    assert count(db, "media_embeddings") == 0


def test_a_model_with_other_dimensions_is_refused(
    db: Conn, corpus: list[dict[str, Any]], monkeypatch: pytest.MonkeyPatch
) -> None:
    seed_corpus(db, corpus)
    monkeypatch.setattr(embeddings, "_loaded", FakeEmbedder(dims=768))

    with pytest.raises(ValueError, match="384"):
        run_embed_media(db, {})

    assert embedding_versions(db) == []
