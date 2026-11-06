from pathlib import Path
from typing import Any

import pytest

from bookflix_ml.jobs import run_train_lsa
from tests.db_helpers import Conn, count, seed_corpus, version_status

pytestmark = pytest.mark.db


def test_train_lsa_writes_vectors_and_a_candidate_version(
    db: Conn, corpus: list[dict[str, Any]], artifact_dir: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    seed_corpus(db, corpus)

    metrics = run_train_lsa(db, {})

    assert count(db, "media_lsa_vectors") == 12
    assert count(db, "media_features") == 12
    assert version_status(db, "lsa") == "candidate"
    assert metrics["n_docs"] == 12
    row = db.execute(
        "select kind, artifact_uri, trained_at, metrics from model_versions"
    ).fetchone()
    assert row is not None
    assert row[0] == "lsa" and row[2] is not None and row[3]["n_docs"] == 12
    assert list(artifact_dir.rglob("*.joblib"))
    assert str(artifact_dir) in row[1]


def test_vectors_round_trip_at_200_dimensions(
    db: Conn, corpus: list[dict[str, Any]], artifact_dir: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    seed_corpus(db, corpus)
    run_train_lsa(db, {})
    row = db.execute("select vector_dims(vector) from media_lsa_vectors limit 1").fetchone()
    assert row is not None and row[0] == 200


def test_soft_deleted_and_merged_media_are_excluded(
    db: Conn, corpus: list[dict[str, Any]], artifact_dir: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    seed_corpus(db, corpus)
    db.execute("update media set deleted_at = now() where id = 1")
    db.execute("update media set merged_into_id = 2 where id = 3")
    db.commit()

    metrics = run_train_lsa(db, {})

    assert metrics["n_docs"] == 10
    ids = {r[0] for r in db.execute("select media_id from media_lsa_vectors").fetchall()}
    assert 1 not in ids and 3 not in ids


def test_a_failure_leaves_no_vectors_and_a_retired_version(
    db: Conn, corpus: list[dict[str, Any]], monkeypatch: pytest.MonkeyPatch
) -> None:
    seed_corpus(db, corpus)

    def boom(*args: Any, **kwargs: Any) -> None:
        raise RuntimeError("boom")

    monkeypatch.setattr("bookflix_ml.lsa.train_lsa", boom)
    with pytest.raises(RuntimeError):
        run_train_lsa(db, {})

    assert count(db, "media_lsa_vectors") == 0
    assert count(db, "media_features") == 0
    assert version_status(db, "lsa") == "retired"
    row = db.execute("select metrics from model_versions").fetchone()
    assert row is not None and "boom" in row[0]["error"]


def test_a_failure_while_writing_leaves_no_partial_vectors(
    db: Conn, corpus: list[dict[str, Any]], artifact_dir: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    seed_corpus(db, corpus)
    monkeypatch.setattr("bookflix_ml.jobs.CHUNK", 5)
    # The second chunk of vectors hits this constraint after the first one was already inserted.
    db.execute("alter table media_lsa_vectors add constraint boom check (media_id <> 9)")
    db.commit()

    with pytest.raises(Exception, match="boom"):
        run_train_lsa(db, {})

    assert count(db, "media_lsa_vectors") == 0
    assert version_status(db, "lsa") == "retired"


def test_not_enough_media_retires_the_version(db: Conn) -> None:
    from bookflix_ml.lsa import NotEnoughData

    db.execute("insert into media (title) values ('only one')")
    db.commit()
    with pytest.raises(NotEnoughData):
        run_train_lsa(db, {})
    assert version_status(db, "lsa") == "retired"
    assert count(db, "media_lsa_vectors") == 0


def test_rerunning_creates_a_new_version_and_keeps_the_old_vectors(
    db: Conn, corpus: list[dict[str, Any]], artifact_dir: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    seed_corpus(db, corpus)
    run_train_lsa(db, {})
    run_train_lsa(db, {})

    assert count(db, "model_versions") == 2
    assert count(db, "media_lsa_vectors") == 24


def test_the_test_schema_has_every_expected_column(db: Conn) -> None:
    import json
    from pathlib import Path

    expected = json.loads((Path(__file__).parent / "expected_columns.json").read_text())
    for table, columns in expected.items():
        have = {
            r[0]
            for r in db.execute(
                "select column_name from information_schema.columns where table_name = %s", (table,)
            ).fetchall()
        }
        assert set(columns) <= have, table
