from datetime import timedelta
from pathlib import Path
from typing import Any

import numpy as np
import pytest

from bookflix_ml import als, registry
from bookflix_ml.jobs import JOBS, run_build_similarities, run_evaluate, run_train_als
from tests.db_helpers import Conn, count, now, seed_interaction, seed_media, seed_users
from tests.test_evaluate_db import seed_readers_with_hits, sim_versions, status_of, version

pytestmark = pytest.mark.db

SMALL = {"min_interactions": 1, "min_users": 1}


def seed_blocks(db: Conn, readers: int = 8) -> None:
    """Two groups of readers over items 1-4 and 5-8, each reading its group in a rotated order; the last item is held out."""
    seed_media(db, *range(1, 9))
    base = now() - timedelta(days=20)
    for user in range(1, readers + 1):
        seed_users(db, active=user)
        group = [1, 2, 3, 4] if user % 2 else [5, 6, 7, 8]
        # Each reader ends on another item of the group, so what is held out for one was read by the others.
        turn = (user // 2) % 4
        items = group[turn:] + group[:turn]
        for n, media in enumerate(items):
            seed_interaction(db, user, media, "library_add", None, base + timedelta(days=n))


def test_the_job_is_registered() -> None:
    assert "train-als" in JOBS


def test_trains_a_candidate_with_parameters_and_counts_and_reports_its_version(
    db: Conn, artifact_dir: Path
) -> None:
    seed_blocks(db)

    metrics = run_train_als(db, SMALL)

    row = db.execute(
        "select name, kind, status, parameters, artifact_uri from model_versions where id = %s",
        (metrics["version_id"],),
    ).fetchone()
    assert row is not None
    assert row[:3] == ("als", "als", "candidate") and row[4] is not None
    assert row[3]["factors"] == 64 and row[3]["regularization"] == 0.05
    assert row[3]["iterations"] == 20 and row[3]["n_users"] == 8 and row[3]["n_interactions"] == 32
    assert metrics["n_users"] == 8 and metrics["n_items"] == 8


def test_below_the_gate_the_job_skips_and_leaves_no_version(db: Conn, artifact_dir: Path) -> None:
    seed_blocks(db)

    metrics = run_train_als(db, {"min_interactions": 33})

    assert metrics == {"skipped": {"reason": "too few interactions", "have": 32, "need": 33}}
    assert count(db, "model_versions") == 0
    metrics = run_train_als(db, {"min_interactions": 1, "min_users": 9})
    assert metrics["skipped"]["reason"] == "too few readers" and count(db, "model_versions") == 0


def test_the_default_gate_is_5000_interactions_and_200_readers(
    db: Conn, artifact_dir: Path
) -> None:
    seed_blocks(db)

    metrics = run_train_als(db, {})

    assert metrics["skipped"] == {"reason": "too few interactions", "have": 32, "need": 5000}


def test_deactivated_and_opted_out_readers_never_enter_training(
    db: Conn, artifact_dir: Path
) -> None:
    seed_media(db, 1, 2, 3)
    seed_users(db, active=1, deactivated=2, opted_out=3)
    for user in (1, 2, 3):
        for media in (1, 2, 3):
            seed_interaction(db, user, media, "library_add")

    metrics = run_train_als(db, SMALL)

    assert metrics["n_users"] == 1 and metrics["n_interactions"] == 3


def test_a_single_reader_trains_without_crashing(db: Conn, artifact_dir: Path) -> None:
    seed_media(db, 1, 2)
    seed_users(db, active=1)
    seed_interaction(db, 1, 1)
    seed_interaction(db, 1, 2)

    metrics = run_train_als(db, SMALL)

    assert metrics["n_users"] == 1


def test_build_writes_top_50_neighbors_without_self_for_its_own_version_only(
    db: Conn, artifact_dir: Path
) -> None:
    seed_blocks(db)
    other = version(db, "active", "lsa", "lsa")
    db.execute(
        "insert into media_similarities (media_id, similar_media_id, method, score, model_version_id) values (1, 2, 'lsa', 0.5, %s)",
        (other,),
    )
    first = run_train_als(db, SMALL)["version_id"]
    run_build_similarities(db, {"methods": ["als"], "version_id": first})
    second = run_train_als(db, SMALL)["version_id"]

    written = run_build_similarities(db, {"methods": ["als"], "version_id": second})["written"]

    assert written["als"] > 0
    assert sim_versions(db, "als") == {first, second}
    assert sim_versions(db, "lsa") == {other}
    rows = db.execute(
        "select media_id, similar_media_id, score from media_similarities where method = 'als' and model_version_id = %s order by media_id, score desc",
        (second,),
    ).fetchall()
    assert all(a != b and s > 0 for a, b, s in rows)
    assert {b for a, b, _ in rows if a == 1} <= {2, 3, 4}
    assert max(sum(1 for a, _, _ in rows if a == m) for m in range(1, 9)) <= 50


def test_build_drops_media_that_are_no_longer_live(db: Conn, artifact_dir: Path) -> None:
    seed_blocks(db)
    v = run_train_als(db, SMALL)["version_id"]
    db.execute("update media set deleted_at = now() where id = 2")
    db.execute("update media set merged_into_id = 1 where id = 3")
    db.commit()

    run_build_similarities(db, {"methods": ["als"], "version_id": v})

    seen = {
        x
        for r in db.execute(
            "select media_id, similar_media_id from media_similarities where method = 'als'"
        ).fetchall()
        for x in r
    }
    assert 2 not in seen and 3 not in seen and 1 in seen


def test_build_without_an_als_version_skips_it(db: Conn) -> None:
    assert run_build_similarities(db, {"methods": ["als"]})["skipped"] == ["als"]


def test_evaluate_promotes_a_first_candidate_and_a_good_candidate_over_the_active(
    db: Conn, artifact_dir: Path
) -> None:
    seed_blocks(db)
    a = run_train_als(db, SMALL)["version_id"]
    run_build_similarities(db, {"methods": ["als"], "version_id": a})
    first = run_evaluate(db, {"kind": "als", "version_id": a})
    assert first["promoted"] is True and status_of(db, a) == "active"
    assert first["ndcg@10"] > 0

    b = run_train_als(db, SMALL)["version_id"]
    run_build_similarities(db, {"methods": ["als"], "version_id": b})
    second = run_evaluate(db, {"kind": "als", "version_id": b})

    assert second["promoted"] is True and second["reason"] == "passed the gate"
    assert status_of(db, b) == "active" and status_of(db, a) == "retired"
    assert sim_versions(db, "als") == {b}


def test_evaluate_refuses_on_evidence_retires_and_deletes_the_rows(
    db: Conn, artifact_dir: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    seed_blocks(db)
    active = run_train_als(db, SMALL)["version_id"]
    run_build_similarities(db, {"methods": ["als"], "version_id": active})
    run_evaluate(db, {"kind": "als", "version_id": active})
    candidate = run_train_als(db, SMALL)["version_id"]
    run_build_similarities(db, {"methods": ["als"], "version_id": candidate})
    db.execute(
        "update model_versions set parameters = parameters || '{\"factors\": 1}'::jsonb where id = %s",
        (candidate,),
    )
    db.commit()
    real_train = als.train

    def scrambled(scores: dict[tuple[int, int], float], factors: int, *rest: Any) -> als.AlsModel:
        """The candidate's refit (factors 1) learns items that resemble nothing: every pair is orthogonal."""
        model = real_train(scores, factors, *rest)
        if factors == 1:
            model.item_factors = np.eye(len(model.item_ids), dtype=np.float32)
        return model

    monkeypatch.setattr(als, "train", scrambled)

    result = run_evaluate(db, {"kind": "als", "version_id": candidate})

    assert result["promoted"] is False and "NDCG@10 fell" in result["reason"]
    assert status_of(db, candidate) == "retired" and status_of(db, active) == "active"
    assert sim_versions(db, "als") == {active}


def test_evaluation_refits_on_the_split_and_never_reads_stored_rows(
    db: Conn, artifact_dir: Path
) -> None:
    seed_readers_with_hits(db)
    v = run_train_als(db, SMALL)["version_id"]
    # Stored rows that would predict every held-out item; the evaluation must not use them.
    for media, similar in ((1, 3), (1, 2), (2, 3), (3, 2)):
        db.execute(
            "insert into media_similarities (media_id, similar_media_id, method, score, model_version_id) values (%s, %s, 'als', 0.9, %s)",
            (media, similar, v),
        )
    db.commit()
    before = count(db, "media_similarities")

    result = run_evaluate(db, {"kind": "als", "version_id": v})

    assert result["n_eval_users"] == 6
    assert count(db, "media_similarities") == before
    assert result["promoted"] is True


def test_thin_evaluation_data_promotes_the_newer_version(db: Conn, artifact_dir: Path) -> None:
    seed_media(db, 1, 2)
    seed_users(db, active=1)
    seed_interaction(db, 1, 1)
    seed_interaction(db, 1, 2)
    active = version(db, "active", "als", "als")
    candidate = run_train_als(db, SMALL)["version_id"]
    run_build_similarities(db, {"methods": ["als"], "version_id": candidate})

    result = run_evaluate(db, {"kind": "als", "version_id": candidate})

    assert result["promoted"] is True and "not enough data" in result["reason"]
    assert status_of(db, active) == "retired"


def test_prune_deletes_the_rows_of_retired_als_versions_only(db: Conn) -> None:
    seed_media(db, 1, 2)
    old, new, stale = (
        version(db, "active", "als", "als"),
        version(db, "candidate", "als", "als"),
        version(db, "retired", "als", "als"),
    )
    for v in (old, new, stale):
        db.execute(
            "insert into media_similarities (media_id, similar_media_id, method, score, model_version_id) values (1, 2, 'als', 0.5, %s)",
            (v,),
        )
    db.commit()

    registry.promote(db, new)
    db.commit()

    assert sim_versions(db, "als") == {new}


def test_evaluate_without_a_candidate_is_recorded_without_error(db: Conn) -> None:
    assert run_evaluate(db, {"kind": "als"}) == {"promoted": False, "reason": "no candidate"}
