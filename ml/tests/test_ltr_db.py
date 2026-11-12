from datetime import timedelta
from pathlib import Path
from typing import Any

import numpy as np
import pandas as pd
import pytest
from psycopg.types.json import Jsonb

from bookflix_ml import artifacts, impressions, ltr, registry
from bookflix_ml.jobs import JOBS, run_evaluate, run_score_ltr, run_train_ltr
from tests.db_helpers import Conn, count, now, seed_media
from tests.ltr_data import BASE, lists
from tests.test_evaluate_db import status_of, version

pytestmark = pytest.mark.db

SMALL = {"min_labeled": 100}


def load(db: Conn, frame: pd.DataFrame) -> None:
    """Write a synthetic frame as logged recommendations: an impression each, and the events its grade implies. Lists end up 20-60 days old."""
    shift = now().replace(tzinfo=None) - timedelta(days=60) - BASE
    seed_media(db, *range(1, 11))
    for user in sorted(set(frame["user_id"])):
        db.execute("insert into users (id) values (%s) on conflict do nothing", (int(user),))
    for r in frame.to_dict("records"):
        at = r["generated_at"] + shift
        features = {name: None if pd.isna(r[name]) else r[name] for name in impressions.FEATURES}
        db.execute(
            "insert into recommendations (id, user_id, media_id, shelf, position, score, reason_payload, generated_at) values (%s, %s, %s, %s, %s, %s, %s, %s)",
            (
                r["recommendation_id"],
                r["user_id"],
                r["media_id"],
                r["shelf"],
                r["position"],
                r["logged_score"],
                Jsonb({"features": features}),
                at,
            ),
        )
        events = ["impression"]
        if r["label"] >= 1:
            events.append("click")
        if r["label"] >= 3:
            events.append("library_add")
        for event in events:
            db.execute(
                "insert into recommendation_events (recommendation_id, event, occurred_at) values (%s, %s, %s)",
                (r["recommendation_id"], event, at + timedelta(minutes=1)),
            )
        if r["label"] == 4:
            db.execute(
                "insert into user_interactions (user_id, media_id, event, occurred_at) values (%s, %s, 'reading_start', %s)",
                (r["user_id"], r["media_id"], at + timedelta(days=1)),
            )
    db.commit()


def versions(db: Conn, name: str = "ltr") -> list[tuple[int, str]]:
    return [
        (int(r[0]), str(r[1]))
        for r in db.execute(
            "select id, status from model_versions where name = %s order by id", (name,)
        ).fetchall()
    ]


def test_the_jobs_are_registered() -> None:
    assert {"train-ltr", "score-ltr"} <= JOBS.keys()


def test_below_the_gate_the_job_skips_and_leaves_no_version(db: Conn, artifact_dir: Path) -> None:
    load(db, lists(60))

    metrics = run_train_ltr(db, {})

    assert metrics == {
        "skipped": {"reason": "too few labeled impressions", "have": 600, "need": 50000}
    }
    assert count(db, "model_versions") == 0


def test_rows_dropped_by_cleaning_do_not_count_towards_the_gate(
    db: Conn, artifact_dir: Path
) -> None:
    frame = lists(60)
    frame.loc[frame["user_id"] > 30, "popularity"] = np.nan  # 300 of the 600 rows are unusable

    load(db, frame)
    metrics = run_train_ltr(db, {"min_labeled": 400})

    assert metrics == {
        "skipped": {"reason": "too few labeled impressions", "have": 300, "need": 400}
    }
    assert count(db, "model_versions") == 0


def test_a_history_that_cannot_be_split_skips_cleanly_too(db: Conn, artifact_dir: Path) -> None:
    load(db, lists(60, same_reader=True))

    metrics = run_train_ltr(db, SMALL)

    assert "skipped" in metrics and count(db, "model_versions") == 0


def test_trains_a_candidate_with_its_artifact_and_reports_the_version(
    db: Conn, artifact_dir: Path
) -> None:
    load(db, lists(100))

    metrics = run_train_ltr(db, SMALL)

    row = db.execute(
        "select name, kind, status, parameters, artifact_uri from model_versions where id = %s",
        (metrics["version_id"],),
    ).fetchone()
    assert row is not None and row[:3] == ("ltr", "ltr", "candidate") and row[4] is not None
    assert row[3]["seed"] == 42 and row[3]["n_labeled"] == 1000 and row[3]["window_days"] == 7
    assert metrics["n_train"] == 800 and metrics["n_validation"] == 200


def test_a_candidate_that_beats_the_hybrid_order_is_promoted(db: Conn, artifact_dir: Path) -> None:
    load(db, lists(400))
    v = run_train_ltr(db, SMALL)["version_id"]

    result = run_evaluate(db, {"kind": "ltr", "version_id": v})

    assert result["promoted"] is True and result["ndcg@10"] > result["hybrid_ndcg@10"]
    assert result["ndcg_delta"] > 0 and status_of(db, v) == "active"
    stored = db.execute("select metrics from model_versions where id = %s", (v,)).fetchone()
    assert stored is not None and stored[0]["hybrid_ndcg@10"] == result["hybrid_ndcg@10"]


def test_a_candidate_that_does_not_beat_the_hybrid_order_is_retired_with_its_scores(
    db: Conn, artifact_dir: Path
) -> None:
    load(db, lists(400, hybrid_is_perfect=True))
    old = version(db, "active", "ltr", "ltr")
    v = run_train_ltr(db, SMALL)["version_id"]
    db.execute(
        "insert into ltr_scores (user_id, media_id, score, model_version_id, generated_at) values (1, 1, 0.5, %s, now())",
        (v,),
    )
    db.commit()

    result = run_evaluate(db, {"kind": "ltr", "version_id": v})

    assert result["promoted"] is False and "not above" in result["reason"]
    assert status_of(db, v) == "retired" and status_of(db, old) == "active"
    assert count(db, "ltr_scores") == 0


def test_thin_evaluation_data_retires_the_candidate_instead_of_promoting_it(
    db: Conn, artifact_dir: Path
) -> None:
    load(db, lists(60))
    v = run_train_ltr(db, SMALL)["version_id"]

    result = run_evaluate(db, {"kind": "ltr", "version_id": v})

    assert result["promoted"] is False and "not enough held-out" in result["reason"]
    assert status_of(db, v) == "retired"


def test_evaluation_never_scores_a_reader_the_model_learned_from(
    db: Conn, artifact_dir: Path
) -> None:
    load(db, lists(100))
    v = run_train_ltr(db, SMALL)["version_id"]
    uri = db.execute("select artifact_uri from model_versions where id = %s", (v,)).fetchone()
    assert uri is not None
    model = ltr.from_artifact(artifacts.load(uri[0]))

    held = ltr.held_out(model, impressions.fetch_labeled(db))

    assert set(held["user_id"]).isdisjoint(model.train_users) and len(held) == model.n_validation


def test_evaluate_without_a_candidate_or_with_a_wrong_one_is_refused(db: Conn) -> None:
    assert run_evaluate(db, {"kind": "ltr"}) == {"promoted": False, "reason": "no candidate"}
    other = version(db, "candidate", "item-cf", "item_cf")
    with pytest.raises(ValueError, match="candidate"):
        run_evaluate(db, {"kind": "ltr", "version_id": other})


def test_promoting_and_retiring_delete_the_scores_and_files_of_retired_versions_only(
    db: Conn, artifact_dir: Path
) -> None:
    seed_media(db, 1)
    db.execute("insert into users (id) values (1)")
    old, new, waiting, stale = (
        version(db, "active", "ltr", "ltr"),
        version(db, "candidate", "ltr", "ltr"),
        version(db, "candidate", "ltr", "ltr"),
        version(db, "retired", "ltr", "ltr"),
    )
    for media, v in enumerate((old, new, waiting, stale), start=1):
        seed_media(db, media)
        db.execute(
            "insert into ltr_scores (user_id, media_id, score, model_version_id, generated_at) values (1, %s, 1, %s, now())",
            (media, v),
        )
    db.commit()

    registry.promote(db, new)
    db.commit()

    kept = {int(r[0]) for r in db.execute("select model_version_id from ltr_scores").fetchall()}
    assert kept == {new, waiting}


def fresh_candidates(db: Conn, user: int, media: list[int], hours: float = 1) -> None:
    for m in media:
        db.execute(
            "insert into ltr_candidates (user_id, media_id, features, generated_at) values (%s, %s, %s, %s)",
            (
                user,
                m,
                Jsonb({name: (m / 10) for name in impressions.FEATURES}),
                now().replace(tzinfo=None) - timedelta(hours=hours),
            ),
        )
    db.commit()


def active_model(db: Conn, artifact_dir: Path) -> int:
    load(db, lists(100))
    v = run_train_ltr(db, SMALL)["version_id"]
    registry.promote(db, v)
    db.commit()
    return int(v)


def test_scoring_writes_the_scores_of_fresh_candidates_with_the_active_version(
    db: Conn, artifact_dir: Path
) -> None:
    v = active_model(db, artifact_dir)
    fresh_candidates(db, 1, [1, 2, 3])

    result = run_score_ltr(db, {})

    assert result["scored"] == 3 and result["version_id"] == v
    rows = db.execute(
        "select user_id, media_id, model_version_id from ltr_scores order by media_id"
    ).fetchall()
    assert rows == [(1, 1, v), (1, 2, v), (1, 3, v)]


def test_scoring_ignores_stale_candidates_and_replaces_a_readers_rows_as_a_whole(
    db: Conn, artifact_dir: Path
) -> None:
    active_model(db, artifact_dir)
    fresh_candidates(db, 1, [1, 2, 3])
    run_score_ltr(db, {})
    db.execute("delete from ltr_candidates")
    fresh_candidates(db, 1, [4], hours=2)
    fresh_candidates(db, 2, [5], hours=37)

    run_score_ltr(db, {})

    assert [
        r[0] for r in db.execute("select media_id from ltr_scores order by media_id").fetchall()
    ] == [4]


def test_scoring_removes_readers_who_are_no_longer_eligible_and_other_versions(
    db: Conn, artifact_dir: Path
) -> None:
    active_model(db, artifact_dir)
    fresh_candidates(db, 1, [1])
    fresh_candidates(db, 2, [1])
    fresh_candidates(db, 3, [1])
    run_score_ltr(db, {})
    db.execute("update users set deactivated_at = now() where id = 2")
    db.execute("insert into profiles (user_id, personalization_enabled) values (3, false)")
    other = version(db, "retired", "ltr", "ltr")
    db.execute(
        "insert into ltr_scores (user_id, media_id, score, model_version_id, generated_at) values (1, 2, 1, %s, now()) on conflict do nothing",
        (other,),
    )
    db.commit()

    run_score_ltr(db, {})

    assert {int(r[0]) for r in db.execute("select user_id from ltr_scores").fetchall()} == {1}
    assert {
        int(r[0]) for r in db.execute("select model_version_id from ltr_scores").fetchall()
    } == {versions(db)[0][0]}


def test_scoring_without_an_active_version_writes_nothing_and_clears_old_scores(
    db: Conn, artifact_dir: Path
) -> None:
    seed_media(db, 1)
    db.execute("insert into users (id) values (1)")
    retired = version(db, "retired", "ltr", "ltr")
    db.execute(
        "insert into ltr_scores (user_id, media_id, score, model_version_id, generated_at) values (1, 1, 1, %s, now())",
        (retired,),
    )
    db.commit()
    fresh_candidates(db, 1, [1])

    result: dict[str, Any] = run_score_ltr(db, {})

    assert result["scored"] == 0 and count(db, "ltr_scores") == 0


def test_the_position_the_candidates_are_scored_at_does_not_change_their_order(
    db: Conn, artifact_dir: Path
) -> None:
    active_model(db, artifact_dir)
    fresh_candidates(db, 1, [1, 2, 3, 4, 5])

    run_score_ltr(db, {})
    first = db.execute("select media_id, score from ltr_scores order by media_id").fetchall()
    run_score_ltr(db, {})

    assert (
        db.execute("select media_id, score from ltr_scores order by media_id").fetchall() == first
    )
