import json
from datetime import timedelta
from typing import Any

import pytest

from bookflix_ml import registry
from bookflix_ml.jobs import JOBS, run_evaluate
from bookflix_ml.similarities import write
from tests.db_helpers import Conn, count, now, seed_interaction, seed_media, seed_users

pytestmark = pytest.mark.db


def version(
    db: Conn,
    status: str = "candidate",
    name: str = "item-cf",
    kind: str = "item_cf",
    metrics: dict[str, Any] | None = None,
) -> int:
    row = db.execute(
        "insert into model_versions (name, version, kind, status, metrics) values (%s, %s, %s, %s, %s::jsonb) returning id",
        (name, f"v{count(db, 'model_versions')}", kind, status, json.dumps(metrics or {})),
    ).fetchone()
    db.commit()
    assert row is not None
    return int(row[0])


def sim_versions(db: Conn, method: str = "item_cf") -> set[int]:
    return {
        int(r[0])
        for r in db.execute(
            "select distinct model_version_id from media_similarities where method = %s", (method,)
        ).fetchall()
    }


def status_of(db: Conn, version_id: int) -> str:
    row = db.execute("select status from model_versions where id = %s", (version_id,)).fetchone()
    assert row is not None
    return str(row[0])


def seed_readers(db: Conn) -> None:
    """Three readers who each read 1, 2, then 3 in order (the last one is held out), so 1 and 2 predict 3."""
    seed_media(db, 1, 2, 3, 4)
    seed_users(db, active=1)
    for user in (2, 3, 4):
        seed_users(db, active=user)
    base = now() - timedelta(days=10)
    for user in (1, 2, 3, 4):
        for n, media in enumerate((1, 2, 3)):
            seed_interaction(db, user, media, "library_add", None, base + timedelta(days=n))


def good_neighbors() -> dict[int, list[tuple[int, float]]]:
    return {1: [(3, 0.9)], 2: [(3, 0.8)], 3: [(1, 0.9)]}


def test_promote_swaps_active_atomically_and_never_leaves_two_active(db: Conn) -> None:
    seed_media(db, 1, 2)
    old, new = version(db, "active"), version(db)

    registry.promote(db, new)
    db.commit()

    assert status_of(db, old) == "retired" and status_of(db, new) == "active"
    row = db.execute(
        "select count(*) from model_versions where name = 'item-cf' and status = 'active'"
    ).fetchone()
    assert row is not None and row[0] == 1


def test_promote_prunes_old_rows_but_keeps_a_waiting_candidate_and_other_methods(db: Conn) -> None:
    seed_media(db, 1, 2, 3)
    old, new, waiting = version(db, "active"), version(db), version(db)
    lsa = version(db, "active", "lsa", "lsa")
    stale = version(db, "retired")
    for v in (old, new, waiting, stale):
        write(db, "item_cf", v, {1: [(2, 0.5)]})
    write(db, "lsa", lsa, {1: [(2, 0.5)]})
    db.commit()

    registry.promote(db, new)
    db.commit()

    assert sim_versions(db) == {new, waiting}
    assert sim_versions(db, "lsa") == {lsa}


def test_promote_rolls_back_entirely_when_the_candidate_cannot_be_promoted(db: Conn) -> None:
    seed_media(db, 1, 2)
    old, retired = version(db, "active"), version(db, "retired")
    write(db, "item_cf", old, {1: [(2, 0.5)]})
    db.commit()

    with pytest.raises(ValueError):
        registry.promote(db, retired)
    db.rollback()

    assert status_of(db, old) == "active" and sim_versions(db) == {old}
    with pytest.raises(ValueError):
        registry.promote(db, 99999)


def test_evaluate_job_promotes_a_good_candidate_and_keeps_the_active_when_gate_fails(
    db: Conn,
) -> None:
    seed_readers(db)
    active = version(db, "active", metrics={"ndcg@10": 0.0, "coverage": 0.0})
    candidate = version(db)
    write(db, "item_cf", active, {1: [(4, 0.9)]})
    write(db, "item_cf", candidate, good_neighbors())
    db.commit()

    result = run_evaluate(db, {"kind": "item_cf"})

    assert result["promoted"] is True
    assert status_of(db, candidate) == "active" and status_of(db, active) == "retired"
    assert sim_versions(db) == {candidate}

    worse = version(db)
    write(db, "item_cf", worse, {1: [(4, 0.9)]})
    db.commit()

    result = run_evaluate(db, {"kind": "item_cf"})

    assert result["promoted"] is False
    assert status_of(db, worse) == "candidate" and status_of(db, candidate) == "active"
    assert sim_versions(db) == {candidate, worse}


def test_evaluate_writes_metrics_and_reason_even_when_blocked(db: Conn) -> None:
    seed_readers(db)
    active = version(db, "active", metrics={"ndcg@10": 0.9, "coverage": 0.9, "n_users": 4})
    candidate = version(db, metrics={"n_users": 4})
    write(db, "item_cf", candidate, {1: [(4, 0.9)]})
    db.commit()

    run_evaluate(db, {"kind": "item_cf"})

    row = db.execute(
        "select metrics, status from model_versions where id = %s", (candidate,)
    ).fetchone()
    assert row is not None and row[1] == "candidate"
    assert row[0]["promoted"] is False and row[0]["reason"] and "ndcg@10" in row[0]
    assert row[0]["n_users"] == 4
    assert status_of(db, active) == "active"


def test_a_failing_evaluation_leaves_versions_untouched(
    db: Conn, monkeypatch: pytest.MonkeyPatch
) -> None:
    seed_readers(db)
    active, candidate = version(db, "active"), version(db)
    write(db, "item_cf", active, {1: [(2, 0.5)]})
    write(db, "item_cf", candidate, good_neighbors())
    db.commit()

    def boom(*args: Any, **kwargs: Any) -> Any:
        raise RuntimeError("scoring blew up")

    monkeypatch.setattr("bookflix_ml.jobs.evaluate.metrics_at_k", boom)

    with pytest.raises(RuntimeError, match="scoring blew up"):
        run_evaluate(db, {"kind": "item_cf"})

    row = db.execute("select metrics from model_versions where id = %s", (candidate,)).fetchone()
    assert row is not None and "promoted" not in row[0]
    assert status_of(db, active) == "active" and status_of(db, candidate) == "candidate"
    assert sim_versions(db) == {active, candidate}


def test_one_user_gives_not_enough_data_and_no_promotion(db: Conn) -> None:
    seed_media(db, 1, 2)
    seed_users(db, active=1)
    seed_interaction(db, 1, 1)
    seed_interaction(db, 1, 2)
    candidate = version(db)
    write(db, "item_cf", candidate, {1: [(2, 0.9)]})
    db.commit()

    result = run_evaluate(db, {"kind": "item_cf"})

    assert result["promoted"] is False and result["reason"] == "not enough data"
    assert result["n_eval_users"] == 1
    assert status_of(db, candidate) == "candidate"


def test_no_users_and_no_candidate_are_recorded_without_error(db: Conn) -> None:
    assert run_evaluate(db, {"kind": "lsa"})["reason"] == "no candidate"
    candidate = version(db)
    db.commit()

    result = run_evaluate(db, {"kind": "item_cf"})

    assert result["promoted"] is False and result["reason"] == "not enough data"
    assert status_of(db, candidate) == "candidate"


def test_opted_out_and_deactivated_readers_are_not_evaluated(db: Conn) -> None:
    seed_media(db, 1, 2, 3)
    seed_users(db, deactivated=1, opted_out=2)
    for user in (1, 2):
        for n, media in enumerate((1, 2)):
            seed_interaction(db, user, media, "library_add", None, now() - timedelta(days=5 - n))
    candidate = version(db)
    write(db, "item_cf", candidate, {1: [(2, 0.9)]})
    db.commit()

    assert run_evaluate(db, {"kind": "item_cf"})["reason"] == "not enough data"


def test_the_job_is_registered_and_rejects_an_unknown_kind(db: Conn) -> None:
    assert "evaluate" in JOBS
    with pytest.raises(ValueError, match="kind"):
        run_evaluate(db, {"kind": "nope"})
