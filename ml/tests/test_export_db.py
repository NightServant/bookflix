import json
from collections.abc import Iterator
from pathlib import Path
from typing import Any

import pytest

from bookflix_ml.db import fetch_interaction_weights, fetch_interactions
from bookflix_ml.item_cf import DEFAULT_WEIGHTS
from bookflix_ml.jobs import run_train_item_cf
from tests.db_helpers import (
    Conn,
    count,
    seed_interaction,
    seed_media,
    seed_users,
    version_status,
)

pytestmark = pytest.mark.db


def test_export_excludes_deactivated_and_opted_out_users(db: Conn) -> None:
    users = seed_users(db, active=1, deactivated=2, opted_out=3, no_profile=4)  # no profile = on
    seed_media(db, 1)
    for user_id in users:
        seed_interaction(db, user_id, 1)

    assert {row[0] for row in list(fetch_interactions(db))} == {1, 4}


def test_interactions_recorded_before_opting_out_are_excluded(db: Conn) -> None:
    seed_users(db, no_profile=1)
    seed_media(db, 1)
    seed_interaction(db, 1, 1)
    assert len(list(fetch_interactions(db))) == 1

    db.execute("insert into profiles (user_id, personalization_enabled) values (1, false)")
    db.commit()

    assert list(fetch_interactions(db)) == []


def test_interactions_recorded_before_deactivation_are_excluded(db: Conn) -> None:
    seed_users(db, no_profile=1)
    seed_media(db, 1)
    seed_interaction(db, 1, 1)
    db.execute("update users set deactivated_at = now() where id = 1")
    db.commit()

    assert list(fetch_interactions(db)) == []


def test_soft_deleted_merged_and_null_media_rows_are_excluded(db: Conn) -> None:
    seed_users(db, active=1)
    seed_media(db, 1, 2, 3, 4)
    db.execute("update media set deleted_at = now() where id = 2")
    db.execute("update media set merged_into_id = 1 where id = 3")
    db.commit()
    for media_id in (1, 2, 3, 4, None):
        seed_interaction(db, 1, media_id)

    assert sorted(row[1] for row in list(fetch_interactions(db))) == [1, 4]


def test_rows_carry_event_value_and_time(db: Conn) -> None:
    seed_users(db, active=1)
    seed_media(db, 1)
    seed_interaction(db, 1, 1, "reading_progress", 40.0)

    user_id, media_id, event, value, occurred_at = list(fetch_interactions(db))[0]
    assert (user_id, media_id, event, value) == (1, 1, "reading_progress", 40.0)
    assert occurred_at is not None


def test_weights_come_from_the_active_model_version_with_a_default_fallback(db: Conn) -> None:
    assert fetch_interaction_weights(db) == DEFAULT_WEIGHTS

    stored = {"view": 2, "half_life_days": 30, "rating_midpoint": 5.0}
    db.execute(
        "insert into model_versions (name, version, kind, status, parameters) values ('interaction-weights', 'old', 'interaction_weights', 'retired', %s)",
        (json.dumps({"view": 99}),),
    )
    db.execute(
        "insert into model_versions (name, version, kind, status, parameters) values ('interaction-weights', 'seed', 'interaction_weights', 'active', %s)",
        (json.dumps(stored),),
    )
    db.commit()

    weights = fetch_interaction_weights(db)
    assert weights["view"] == 2 and weights["half_life_days"] == 30
    assert weights["rating_midpoint"] == 5.0
    assert weights["library_add"] == DEFAULT_WEIGHTS["library_add"]


def test_train_item_cf_stores_a_candidate_version_and_an_artifact(
    db: Conn, artifact_dir: Path
) -> None:
    seed_users(db, active=1, deactivated=2, opted_out=3)
    seed_media(db, 1, 2, 3)
    for user_id in (1, 2, 3):
        seed_interaction(db, user_id, 1)
        seed_interaction(db, user_id, 2)
    seed_interaction(db, 1, 3)

    metrics = run_train_item_cf(db, {})

    assert version_status(db, "item-cf") == "candidate"
    assert metrics["n_users"] == 1  # the deactivated and opted-out readers never reached training
    row = db.execute("select kind, parameters, artifact_uri from model_versions").fetchone()
    assert row is not None
    assert row[0] == "item_cf"
    assert row[1]["k"] == 50 and row[1]["n_users"] == 1 and "weights" in row[1]
    assert list(artifact_dir.rglob("*.joblib"))
    assert count(db, "media_similarities") == 0  # written by build-similarities


def test_train_item_cf_with_no_interactions_still_produces_a_candidate(
    db: Conn, artifact_dir: Path
) -> None:
    metrics = run_train_item_cf(db, {})
    assert metrics["n_users"] == 0 and version_status(db, "item-cf") == "candidate"


def test_a_failing_train_item_cf_retires_its_version(
    db: Conn, monkeypatch: pytest.MonkeyPatch
) -> None:
    def boom(*args: Any, **kwargs: Any) -> None:
        raise RuntimeError("boom")

    monkeypatch.setattr("bookflix_ml.item_cf.item_neighbors", boom)
    with pytest.raises(RuntimeError):
        run_train_item_cf(db, {})
    assert version_status(db, "item-cf") == "retired"


def test_interactions_stream_in_batches_from_a_server_side_cursor(
    db: Conn, monkeypatch: pytest.MonkeyPatch
) -> None:
    seed_users(db, active=1)
    seed_media(db, 1)
    for _ in range(7):
        seed_interaction(db, 1, 1)
    monkeypatch.setattr("bookflix_ml.db.INTERACTION_BATCH", 2)

    rows = fetch_interactions(db)

    assert isinstance(rows, Iterator)  # nothing is read until the caller folds it
    assert len(list(rows)) == 7
    assert db.execute("select count(*) from pg_cursors").fetchone() == (0,)
