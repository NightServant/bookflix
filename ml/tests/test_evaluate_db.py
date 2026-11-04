import json
import math
from datetime import timedelta
from typing import Any

import pytest

from bookflix_ml import registry
from bookflix_ml.item_cf import DEFAULT_WEIGHTS, interaction_scores, item_neighbors
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


def seed_readers_with_hits(db: Conn) -> None:
    """Six readers: 1-3 read 1, 2, 3 (3 is held out); 4-6 read 1, 3, 2 (2 is held out). Both held-out items are read by others earlier."""
    seed_media(db, 1, 2, 3, 4)
    base = now() - timedelta(days=10)
    for user, order in (
        (1, (1, 2, 3)),
        (2, (1, 2, 3)),
        (3, (1, 2, 3)),
        (4, (1, 3, 2)),
        (5, (1, 3, 2)),
        (6, (1, 3, 2)),
    ):
        seed_users(db, active=user)
        for n, media in enumerate(order):
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
    seed_readers_with_hits(db)
    active = version(db, "active", metrics={"ndcg@10": 0.0, "coverage": 0.0})
    candidate = version(db)
    write(db, "item_cf", active, {1: [(4, 0.9)]})
    write(db, "item_cf", candidate, good_neighbors())
    db.commit()

    result = run_evaluate(db, {"kind": "item_cf"})

    assert result["promoted"] is True and result["ndcg@10"] > 0
    assert status_of(db, candidate) == "active" and status_of(db, active) == "retired"
    assert sim_versions(db) == {candidate}


def lsa_pair(
    db: Conn, active_rows: dict[int, list[tuple[int, float]]], stored: dict[str, Any]
) -> tuple[int, int]:
    """An active lsa version with `active_rows` and the given stored metrics, and a candidate that predicts nothing."""
    seed_readers_with_hits(db)
    active = version(db, "active", "lsa", "lsa", stored)
    candidate = version(db, "candidate", "lsa", "lsa")
    write(db, "lsa", active, active_rows)
    write(db, "lsa", candidate, {1: [(4, 0.9)]})
    db.commit()

    return active, candidate


def test_gate_scores_the_active_version_now_instead_of_trusting_its_stored_metrics(
    db: Conn,
) -> None:
    # Stored metrics claim the active is useless; scored on today's split it is the better one, so the candidate is blocked.
    active, candidate = lsa_pair(db, good_neighbors(), {"ndcg@10": 0.0, "coverage": 0.0})

    result = run_evaluate(db, {"kind": "lsa"})

    assert result["promoted"] is False and result["active_version_id"] == active
    assert result["active_metrics"]["ndcg@10"] > result["ndcg@10"] == 0.0
    assert status_of(db, candidate) == "candidate" and status_of(db, active) == "active"
    assert sim_versions(db, "lsa") == {active, candidate}


def test_gate_promotes_a_better_candidate_whatever_the_active_stored(db: Conn) -> None:
    # Stored metrics claim the active is perfect; scored now it is the worse one.
    active, candidate = lsa_pair(db, {1: [(4, 0.9)]}, {"ndcg@10": 1.0, "coverage": 1.0})
    write(db, "lsa", candidate, good_neighbors())
    db.commit()

    result = run_evaluate(db, {"kind": "lsa"})

    assert result["promoted"] is True and result["reason"] == "passed the gate"
    assert status_of(db, candidate) == "active" and status_of(db, active) == "retired"
    row = db.execute("select metrics from model_versions where id = %s", (candidate,)).fetchone()
    assert row is not None and row[0]["active_metrics"]["ndcg@10"] == 0.0


def test_item_cf_gate_refits_the_active_version_with_its_own_parameters(db: Conn) -> None:
    seed_readers_with_hits(db)
    # The active one only ever learned from `view` events, which nobody has: it recommends nothing.
    active = version(db, "active", metrics={"ndcg@10": 0.0, "coverage": 0.0})
    db.execute(
        "update model_versions set parameters = %s::jsonb where id = %s",
        (json.dumps({"weights": {"view": 1, "half_life_days": 90}, "k": 50}), active),
    )
    candidate = version(db)
    write(db, "item_cf", candidate, good_neighbors())
    db.commit()

    result = run_evaluate(db, {"kind": "item_cf"})

    assert result["promoted"] is True and result["active_metrics"] == {}
    assert result["reason"] == "active version could not be scored"


def test_item_cf_evaluation_does_not_see_the_held_out_item_it_is_scored_on(db: Conn) -> None:
    seed_readers(db)
    candidate = version(db)
    # Stored neighbors that would "predict" every held-out item perfectly: the evaluation must not use them.
    write(db, "item_cf", candidate, good_neighbors())
    db.commit()

    leaky = item_neighbors(
        interaction_scores(
            [
                (u, m, "library_add", None, now() - timedelta(days=10 - n))
                for u in (1, 2, 3, 4)
                for n, m in enumerate((1, 2, 3))
            ],
            DEFAULT_WEIGHTS,
            now(),
        )
    )
    assert 3 in {
        n for n, _ in leaky[1]
    }  # training on everything would trivially recommend the held-out item

    result = run_evaluate(db, {"kind": "item_cf"})

    assert result["n_eval_users"] == 4
    assert result["hit_rate@10"] == 0.0 and result["ndcg@10"] == 0.0 and result["recall@10"] == 0.0


def test_item_cf_evaluation_uses_the_candidates_own_k_and_weights(db: Conn) -> None:
    seed_readers_with_hits(db)
    ignoring = version(db)
    db.execute(
        "update model_versions set parameters = %s::jsonb where id = %s",
        (json.dumps({"weights": {"view": 1, "half_life_days": 90}, "k": 5}), ignoring),
    )
    db.commit()

    version(db, "active")

    # Weights that give library_add no weight leave nothing to learn from.
    assert run_evaluate(db, {"kind": "item_cf"})["reason"] == "not enough data"


def test_item_cf_evaluation_writes_nothing_to_media_similarities(db: Conn) -> None:
    seed_readers_with_hits(db)
    active, candidate = (
        version(db, "active", metrics={"ndcg@10": 0.0, "coverage": 0.0}),
        version(db),
    )
    write(db, "item_cf", active, {1: [(4, 0.9)]})
    write(db, "item_cf", candidate, good_neighbors())
    db.commit()
    before = db.execute(
        "select model_version_id, method, media_id, similar_media_id, score from media_similarities order by 1, 2, 3, 4"
    ).fetchall()

    run_evaluate(db, {"kind": "item_cf"})
    db.commit()
    # Promotion prunes the old active's rows, which is its job; the candidate's stored rows are what stays.
    after = db.execute(
        "select model_version_id, method, media_id, similar_media_id, score from media_similarities order by 1, 2, 3, 4"
    ).fetchall()

    assert after == [row for row in before if row[0] == candidate]
    assert len(after) == 3


def test_item_cf_evaluation_without_a_promotion_leaves_every_row_untouched(db: Conn) -> None:
    seed_readers(db)
    candidate = version(db)
    write(db, "item_cf", candidate, good_neighbors())
    db.commit()
    before = count(db, "media_similarities")

    run_evaluate(db, {"kind": "item_cf"})

    assert count(db, "media_similarities") == before == 3


def test_metrics_stay_finite_on_tiny_inputs(db: Conn) -> None:
    seed_media(db, 1, 2, 3)
    for user in (1, 2):
        seed_users(db, active=user)
        for n, media in enumerate((1, 2, 3)):
            seed_interaction(db, user, media, "library_add", None, now() - timedelta(days=5 - n))
    candidate = version(db)
    db.commit()

    result = run_evaluate(db, {"kind": "item_cf"})

    assert result["n_eval_users"] == 2
    for key in (
        "precision@10",
        "recall@10",
        "ndcg@10",
        "map@10",
        "hit_rate@10",
        "coverage",
        "diversity",
        "novelty",
    ):
        assert isinstance(result[key], float) and math.isfinite(result[key])
    assert status_of(db, candidate) in {"candidate", "active"}


def test_evaluate_writes_metrics_and_reason_even_when_blocked(db: Conn) -> None:
    active, candidate = lsa_pair(db, good_neighbors(), {"n_users": 4})
    db.execute(
        "update model_versions set metrics = metrics || '{\"n_users\": 4}' where id = %s",
        (candidate,),
    )
    db.commit()

    run_evaluate(db, {"kind": "lsa"})

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
    active, candidate = version(db, "active"), version(db)
    write(db, "item_cf", candidate, {1: [(2, 0.9)]})
    db.commit()

    result = run_evaluate(db, {"kind": "item_cf"})

    assert result["promoted"] is False and result["reason"] == "not enough data"
    assert result["n_eval_users"] == 1
    assert status_of(db, candidate) == "candidate" and status_of(db, active) == "active"


@pytest.mark.parametrize(("kind", "name"), [("item_cf", "item-cf"), ("lsa", "lsa")])
def test_a_first_candidate_is_promoted_even_without_enough_data_to_evaluate(
    db: Conn, kind: str, name: str
) -> None:
    seed_media(db, 1, 2)
    candidate = version(db, "candidate", name, kind)
    write(db, kind, candidate, {1: [(2, 0.9)]})
    db.commit()

    result = run_evaluate(db, {"kind": kind})

    assert result["promoted"] is True and result["reason"] == "no active version (not evaluated)"
    assert status_of(db, candidate) == "active"


def test_a_first_candidate_without_rows_is_still_refused(db: Conn) -> None:
    candidate = version(db, "candidate", "lsa", "lsa")
    db.commit()

    result = run_evaluate(db, {"kind": "lsa"})

    assert result["promoted"] is False and "similarity rows" in result["reason"]
    assert status_of(db, candidate) == "candidate"


def test_no_users_and_no_candidate_are_recorded_without_error(db: Conn) -> None:
    assert run_evaluate(db, {"kind": "lsa"})["reason"] == "no candidate"
    candidate = version(db)
    db.commit()

    result = run_evaluate(db, {"kind": "item_cf"})

    assert result["promoted"] is False and "similarity rows" in result["reason"]
    assert status_of(db, candidate) == "candidate"


def test_opted_out_and_deactivated_readers_are_not_evaluated(db: Conn) -> None:
    seed_media(db, 1, 2, 3)
    seed_users(db, deactivated=1, opted_out=2)
    for user in (1, 2):
        for n, media in enumerate((1, 2)):
            seed_interaction(db, user, media, "library_add", None, now() - timedelta(days=5 - n))
    version(db, "active")
    candidate = version(db)
    write(db, "item_cf", candidate, {1: [(2, 0.9)]})
    db.commit()

    assert run_evaluate(db, {"kind": "item_cf"})["reason"] == "not enough data"


def test_the_job_is_registered_and_rejects_an_unknown_kind(db: Conn) -> None:
    assert "evaluate" in JOBS
    with pytest.raises(ValueError, match="kind"):
        run_evaluate(db, {"kind": "nope"})


def test_promote_retires_older_candidates_and_prunes_their_rows_but_never_a_newer_one(
    db: Conn,
) -> None:
    seed_media(db, 1, 2)
    older, older_still, new, newer = version(db), version(db), version(db), version(db)
    other = version(db, "candidate", "lsa", "lsa")
    for v in (older, older_still, new, newer):
        write(db, "item_cf", v, {1: [(2, 0.5)]})
    write(db, "lsa", other, {1: [(2, 0.5)]})
    db.commit()

    registry.promote(db, new)
    db.commit()

    assert status_of(db, older) == status_of(db, older_still) == "retired"
    assert status_of(db, new) == "active" and status_of(db, newer) == "candidate"
    assert status_of(db, other) == "candidate"
    assert sim_versions(db) == {new, newer} and sim_versions(db, "lsa") == {other}


def test_promoting_lsa_deletes_vectors_and_features_of_retired_versions_only(db: Conn) -> None:
    seed_media(db, 1, 2)
    old_active, retired, old_candidate, new, newer, item_cf = (
        version(db, "active", "lsa", "lsa"),
        version(db, "retired", "lsa", "lsa"),
        version(db, "candidate", "lsa", "lsa"),
        version(db, "candidate", "lsa", "lsa"),
        version(db, "candidate", "lsa", "lsa"),
        version(db, "retired"),
    )
    vector = "[" + ",".join(["0.1"] * 200) + "]"
    for v in (old_active, retired, old_candidate, new, newer, item_cf):
        db.execute(
            "insert into media_lsa_vectors (media_id, model_version_id, vector) values (1, %s, %s)",
            (v, vector),
        )
        db.execute(
            "insert into media_features (media_id, model_version_id, computed_at) values (1, %s, now())",
            (v,),
        )
    db.commit()

    registry.promote(db, new)
    db.commit()

    for table in ("media_lsa_vectors", "media_features"):
        kept = {
            int(r[0])
            for r in db.execute(f"select model_version_id from {table}").fetchall()  # noqa: S608
        }
        # The previous active is retired by the promotion, so its data goes too; another name's data is never touched.
        assert kept == {new, newer, item_cf}
