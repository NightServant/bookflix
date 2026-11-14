from datetime import timedelta
from typing import Any

import pandas as pd
import pytest
from psycopg.types.json import Jsonb

from bookflix_ml import impressions
from tests.db_helpers import Conn, now, seed_interaction, seed_media, seed_users

pytestmark = pytest.mark.db

FEATURES = {name: 0.5 for name in impressions.FEATURES}


def row(
    db: Conn,
    user: int,
    media: int,
    events: list[tuple[str, float]],
    age_days: float = 20,
    position: int | None = 0,
    shelf: str = "recommended",
    features: dict[str, Any] | None = None,
    payload: dict[str, Any] | None = None,
) -> int:
    """A logged recommendation `age_days` old; each event is (name, days after generation)."""
    at = now().replace(tzinfo=None) - timedelta(days=age_days)
    new = db.execute(
        "insert into recommendations (user_id, media_id, shelf, position, score, reason_payload, generated_at) values (%s, %s, %s, %s, 0.4, %s, %s) returning id",
        (
            user,
            media,
            shelf,
            position,
            Jsonb({"features": features or FEATURES, **(payload or {})}),
            at,
        ),
    ).fetchone()
    assert new is not None
    for event, after in events:
        db.execute(
            "insert into recommendation_events (recommendation_id, event, occurred_at) values (%s, %s, %s)",
            (new[0], event, at + timedelta(days=after)),
        )
    db.commit()
    return int(new[0])


def labels(db: Conn, **kwargs: Any) -> dict[int, int]:
    frame = impressions.fetch_labeled(db, **kwargs)
    return dict(zip(frame["recommendation_id"], frame["label"], strict=True))


def world(db: Conn) -> None:
    seed_media(db, 1, 2, 3, 4, 5, 6)
    seed_users(db, active=1)


def test_labels_follow_the_grade_table_and_the_highest_applies(db: Conn) -> None:
    world(db)
    none = row(db, 1, 1, [("impression", 0)])
    click = row(db, 1, 2, [("impression", 0), ("click", 0.1)])
    added = row(db, 1, 3, [("impression", 0), ("click", 0.1), ("library_add", 0.2)])
    started = row(db, 1, 4, [("impression", 0), ("click", 0.1)])
    seed_interaction(db, 1, 4, "reading_start", None, now() - timedelta(days=19))
    dismissed = row(db, 1, 5, [("impression", 0), ("dismiss", 0.1)])
    clicked_then_dismissed = row(db, 1, 6, [("impression", 0), ("click", 0.1), ("dismiss", 0.2)])

    assert labels(db) == {
        none: 0,
        click: 1,
        added: 3,
        started: 4,
        dismissed: 0,
        clicked_then_dismissed: 1,
    }


def test_repeated_impression_events_and_clicks_count_one_row_once(db: Conn) -> None:
    world(db)
    row(
        db,
        1,
        1,
        [("impression", 0), ("impression", 0.5), ("impression", 1), ("click", 1), ("click", 2)],
    )

    frame = impressions.fetch_labeled(db)

    assert len(frame) == 1 and frame["label"].tolist() == [1]


def test_a_row_that_was_never_impressed_is_left_out(db: Conn) -> None:
    world(db)
    row(db, 1, 1, [])
    row(db, 1, 2, [("click", 0.1)])

    assert impressions.fetch_labeled(db).empty


def test_outcomes_outside_the_window_after_the_impression_do_not_count(db: Conn) -> None:
    world(db)
    late = row(db, 1, 1, [("impression", 0), ("click", 8)])
    before = row(db, 1, 2, [("impression", 1)])
    seed_interaction(db, 1, 2, "reading_start", None, now() - timedelta(days=21))
    started_late = row(db, 1, 3, [("impression", 0)])
    seed_interaction(db, 1, 3, "reading_start", None, now() - timedelta(days=11))

    assert labels(db) == {late: 0, before: 0, started_late: 0}


def test_young_impressions_old_ones_and_rows_without_a_position_are_excluded(db: Conn) -> None:
    world(db)
    young = row(db, 1, 1, [("impression", 0)], age_days=3)
    edge = row(db, 1, 2, [("impression", 0)], age_days=7.5)
    too_old = row(db, 1, 3, [("impression", 0)], age_days=91)
    no_place = row(db, 1, 4, [("impression", 0)], position=None)
    kept = row(db, 1, 5, [("impression", 0)], age_days=89)

    found = labels(db)

    assert set(found) == {edge, kept}
    assert {young, too_old, no_place}.isdisjoint(found)


def test_the_hybrid_position_is_read_from_the_payload_and_falls_back_to_the_position(
    db: Conn,
) -> None:
    world(db)
    # Served in LTR order: place 2 on the shelf, 7th in the hybrid order. A row logged before the key existed was served in hybrid order.
    served = row(db, 1, 1, [("impression", 0)], position=2, payload={"hybrid_position": 7})
    old = row(db, 1, 2, [("impression", 0)], position=4)

    frame = impressions.fetch_labeled(db).set_index("recommendation_id")

    assert frame.loc[served, "position"] == 2 and frame.loc[served, "hybrid_position"] == 7
    assert frame.loc[old, "position"] == 4 and frame.loc[old, "hybrid_position"] == 4


def test_deactivated_and_opted_out_readers_and_gone_media_are_excluded(db: Conn) -> None:
    seed_media(db, 1, 2)
    seed_users(db, active=1, deactivated=2, opted_out=3, no_profile=4)
    row(db, 2, 1, [("impression", 0)])
    row(db, 3, 1, [("impression", 0)])
    ok = row(db, 4, 1, [("impression", 0)])
    row(db, 1, 2, [("impression", 0)])
    db.execute("update media set deleted_at = now() where id = 2")
    db.commit()

    assert set(labels(db)) == {ok}


def test_features_are_read_as_logged_and_a_missing_one_is_nan(db: Conn) -> None:
    world(db)
    row(db, 1, 1, [("impression", 0)], features={**FEATURES, "popularity": 0.9, "context": None})
    row(db, 1, 2, [("impression", 0)], features={"popularity": 0.1})
    row(db, 1, 3, [("impression", 0)], position=7)

    frame = impressions.fetch_labeled(db)

    assert list(frame.columns) == impressions.COLUMNS
    assert frame.loc[0, "popularity"] == 0.9 and pd.isna(frame.loc[0, "context"])
    assert frame.loc[1, "popularity"] == 0.1 and frame["content_similarity"].isna().tolist() == [
        False,
        True,
        False,
    ]
    assert frame["position"].tolist() == [0, 0, 7] and (frame["logged_score"] == 0.4).all()


def test_a_wider_window_waits_longer_before_a_row_counts(db: Conn) -> None:
    world(db)
    row(db, 1, 1, [("impression", 0)], age_days=10)

    assert len(impressions.fetch_labeled(db, window_days=7)) == 1
    assert impressions.fetch_labeled(db, window_days=14).empty


def readiness_fixture(db: Conn) -> None:
    """The fixture the Laravel `bookflix:ltr-readiness` test builds too; both must count 4."""
    seed_media(db, 1, 2, 3, 4, 5, 6)
    seed_users(db, active=1, deactivated=2, opted_out=3)
    row(db, 1, 1, [("impression", 0), ("impression", 1)])
    row(db, 1, 2, [("impression", 0), ("click", 1)])
    row(db, 1, 3, [("impression", 0)], age_days=89)
    row(db, 1, 4, [("impression", 0)], position=None)
    row(db, 1, 5, [("impression", 0)], age_days=3)
    row(db, 1, 6, [])
    row(db, 2, 1, [("impression", 0)])
    row(db, 3, 1, [("impression", 0)])
    row(db, 1, 6, [("impression", 0)], age_days=100)
    row(db, 1, 4, [("impression", 0)], age_days=30)


def test_the_readiness_fixture_counts_four(db: Conn) -> None:
    readiness_fixture(db)

    assert len(impressions.fetch_labeled(db)) == 4
