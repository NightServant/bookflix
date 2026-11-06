"""Overlapping pipelines on the single worker: each step must act on the version its own `train` made."""

from pathlib import Path
from typing import Any

import pytest

from bookflix_ml.jobs import run_build_similarities, run_evaluate, run_train_item_cf, run_train_lsa
from bookflix_ml.similarities import write
from tests.db_helpers import Conn, seed_corpus
from tests.test_evaluate_db import (
    seed_readers_with_hits,
    sim_versions,
    status_of,
    version,
)

pytestmark = pytest.mark.db


def test_interleaved_item_cf_pipelines_each_promote_their_own_version(
    db: Conn, artifact_dir: Path
) -> None:
    seed_readers_with_hits(db)

    a = run_train_item_cf(db, {})["version_id"]
    b = run_train_item_cf(db, {})["version_id"]
    assert b > a
    run_build_similarities(db, {"methods": ["item_cf"], "version_id": a})
    assert run_evaluate(db, {"kind": "item_cf", "version_id": a})["promoted"] is True
    run_build_similarities(db, {"methods": ["item_cf"], "version_id": b})
    result = run_evaluate(db, {"kind": "item_cf", "version_id": b})

    assert result["promoted"] is True
    assert status_of(db, b) == "active" and status_of(db, a) == "retired"
    assert sim_versions(db) == {b}


def test_train_item_cf_reports_its_version_id(db: Conn, artifact_dir: Path) -> None:
    seed_readers_with_hits(db)

    version_id = run_train_item_cf(db, {})["version_id"]

    row = db.execute("select name, status from model_versions where id = %s", (version_id,))
    assert row.fetchone() == ("item-cf", "candidate")


def test_train_lsa_reports_its_version_id(
    db: Conn, artifact_dir: Path, corpus: list[dict[str, Any]]
) -> None:
    seed_corpus(db, corpus)

    metrics = run_train_lsa(db, {})

    row = db.execute("select name from model_versions where id = %s", (metrics["version_id"],))
    assert isinstance(metrics["version_id"], int) and row.fetchone() == ("lsa",)


def test_build_with_a_version_id_uses_exactly_that_candidate(db: Conn, artifact_dir: Path) -> None:
    seed_readers_with_hits(db)
    a = run_train_item_cf(db, {})["version_id"]
    run_train_item_cf(db, {})

    run_build_similarities(db, {"methods": ["item_cf"], "version_id": a})

    assert sim_versions(db) == {a}


@pytest.mark.parametrize("bad", ["retired", "active"])
def test_build_refuses_a_version_that_is_not_a_candidate(
    db: Conn, artifact_dir: Path, bad: str
) -> None:
    seed_readers_with_hits(db)
    v = run_train_item_cf(db, {})["version_id"]
    db.execute("update model_versions set status = %s where id = %s", (bad, v))
    db.commit()

    with pytest.raises(ValueError, match="candidate"):
        run_build_similarities(db, {"methods": ["item_cf"], "version_id": v})
    assert sim_versions(db) == set()


def test_build_refuses_a_version_of_another_name_or_an_unknown_one(
    db: Conn, artifact_dir: Path
) -> None:
    seed_readers_with_hits(db)
    lsa = version(db, "candidate", "lsa", "lsa")

    with pytest.raises(ValueError, match="candidate"):
        run_build_similarities(db, {"methods": ["item_cf"], "version_id": lsa})
    with pytest.raises(ValueError, match="candidate"):
        run_build_similarities(db, {"methods": ["item_cf"], "version_id": 99999})
    with pytest.raises(ValueError, match="version_id"):
        run_build_similarities(db, {"methods": ["item_cf"], "version_id": "x"})


def test_build_with_a_version_id_needs_exactly_one_method(db: Conn, artifact_dir: Path) -> None:
    seed_readers_with_hits(db)
    v = run_train_item_cf(db, {})["version_id"]

    with pytest.raises(ValueError, match="one method"):
        run_build_similarities(db, {"methods": ["lsa", "item_cf"], "version_id": v})


def test_evaluate_with_a_version_id_uses_exactly_that_candidate(
    db: Conn, artifact_dir: Path
) -> None:
    seed_readers_with_hits(db)
    a = run_train_item_cf(db, {})["version_id"]
    b = run_train_item_cf(db, {})["version_id"]
    run_build_similarities(db, {"methods": ["item_cf"], "version_id": a})

    result = run_evaluate(db, {"kind": "item_cf", "version_id": a})

    assert result["promoted"] is True and status_of(db, a) == "active"
    assert status_of(db, b) == "candidate"


def test_evaluate_refuses_a_version_that_is_not_a_candidate_of_that_kind(
    db: Conn, artifact_dir: Path
) -> None:
    seed_readers_with_hits(db)
    lsa = version(db, "candidate", "lsa", "lsa")
    retired = version(db, "retired")

    for bad in (lsa, retired, 99999):
        with pytest.raises(ValueError, match="candidate"):
            run_evaluate(db, {"kind": "item_cf", "version_id": bad})
    assert status_of(db, lsa) == "candidate"


def test_evaluate_never_promotes_a_candidate_without_similarity_rows(
    db: Conn, artifact_dir: Path
) -> None:
    seed_readers_with_hits(db)
    active = version(db, "active", metrics={"ndcg@10": 0.0, "coverage": 0.0})
    write(db, "item_cf", active, {1: [(4, 0.9)]})
    candidate = run_train_item_cf(db, {})["version_id"]

    result = run_evaluate(db, {"kind": "item_cf", "version_id": candidate})

    assert result["promoted"] is False and "similarity rows" in result["reason"]
    assert status_of(db, candidate) == "candidate" and status_of(db, active) == "active"
    assert sim_versions(db) == {active}
    row = db.execute("select metrics from model_versions where id = %s", (candidate,)).fetchone()
    assert row is not None and "similarity rows" in row[0]["reason"]
