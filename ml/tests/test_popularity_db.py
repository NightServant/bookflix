import pytest

from bookflix_ml.jobs import JOBS, run_build_similarities, run_refresh_popularity
from bookflix_ml.popularity import refresh
from bookflix_ml.similarities import write
from tests.db_helpers import Conn, count, seed_interaction, seed_media, seed_users

pytestmark = pytest.mark.db


def version(db: Conn, name: str = "lsa", status: str = "candidate", kind: str = "lsa") -> int:
    row = db.execute(
        "insert into model_versions (name, version, kind, status) values (%s, %s, %s, %s) returning id",
        (name, f"v{count(db, 'model_versions')}", kind, status),
    ).fetchone()
    db.commit()
    assert row is not None
    return int(row[0])


def rows(db: Conn) -> set[tuple[int, int, int]]:
    return {
        (int(a), int(b), int(c))
        for a, b, c in db.execute(
            "select media_id, similar_media_id, model_version_id from media_similarities"
        ).fetchall()
    }


def test_write_replaces_rows_for_the_same_version_atomically(db: Conn) -> None:
    seed_media(db, 1, 2, 3)
    v1, other = version(db), version(db, "item-cf", kind="item_cf")
    write(db, "item_cf", other, {1: [(3, 0.5)]})
    write(db, "lsa", v1, {1: [(2, 0.9)], 2: [(1, 0.9)]})
    db.commit()

    assert write(db, "lsa", v1, {1: [(3, 0.8)]}) == 1
    db.commit()

    assert (1, 3, v1) in rows(db)
    assert (1, 2, v1) not in rows(db) and (2, 1, v1) not in rows(db)


def test_a_candidate_version_leaves_the_active_versions_rows_identical(db: Conn) -> None:
    seed_media(db, 1, 2, 3)
    active, candidate = version(db, status="active"), version(db)
    write(db, "lsa", active, {1: [(2, 0.9), (3, 0.4)], 2: [(1, 0.9)]})
    db.commit()
    before = db.execute(
        "select media_id, similar_media_id, score, model_version_id from media_similarities order by 1, 2"
    ).fetchall()

    write(db, "lsa", candidate, {1: [(2, 0.1)], 3: [(1, 0.7)]})
    db.commit()

    kept = db.execute(
        "select media_id, similar_media_id, score, model_version_id from media_similarities where model_version_id = %s order by 1, 2",
        (active,),
    ).fetchall()
    assert kept == before
    assert (1, 2, candidate) in rows(db) and (1, 2, active) in rows(db)


def test_rerunning_a_version_replaces_only_its_own_rows(db: Conn) -> None:
    seed_media(db, 1, 2, 3)
    active, candidate = version(db, status="active"), version(db)
    write(db, "lsa", active, {1: [(2, 0.9)]})
    write(db, "lsa", candidate, {1: [(2, 0.5), (3, 0.4)]})
    db.commit()

    write(db, "lsa", candidate, {2: [(3, 0.6)]})
    db.commit()

    assert rows(db) == {(1, 2, active), (2, 3, candidate)}


def test_other_methods_are_left_alone(db: Conn) -> None:
    seed_media(db, 1, 2, 3)
    v1, other = version(db), version(db, "item-cf", kind="item_cf")
    write(db, "item_cf", other, {1: [(3, 0.5)]})
    write(db, "lsa", v1, {1: [(2, 0.9)]})
    db.commit()

    write(db, "lsa", v1, {2: [(3, 0.4)]})
    db.commit()

    assert (1, 3, other) in rows(db)


def test_write_failure_rolls_back_and_keeps_old_rows(db: Conn) -> None:
    seed_media(db, 1, 2)
    v1 = version(db)
    write(db, "lsa", v1, {1: [(2, 0.9)]})
    db.commit()

    with pytest.raises(Exception):  # noqa: B017, PT011 - an unknown media id breaks the foreign key
        write(db, "lsa", v1, {1: [(2, 0.5), (99, 0.4)]})
    db.rollback()

    assert rows(db) == {(1, 2, v1)}
    assert db.execute("select score from media_similarities").fetchone() == (pytest.approx(0.9),)


def test_refresh_popularity_job_runs_on_an_empty_database(db: Conn) -> None:
    refresh(db)
    assert JOBS["refresh-popularity"] is not None
    assert run_refresh_popularity(db, {}) == {"media": 0}


def test_refresh_reflects_new_interactions_and_ranks_only_active_media(db: Conn) -> None:
    seed_users(db, active=1, deactivated=2)
    seed_media(db, 1, 2, 3)
    seed_interaction(db, 1, 1)
    seed_interaction(db, 1, 1, "view")
    seed_interaction(db, 1, 2)
    seed_interaction(db, 2, 3)  # a deactivated reader does not count
    refresh(db)

    got = {
        int(r[0]): (int(r[1]), float(r[2]))
        for r in db.execute(
            "select media_id, interactions_30d, trending_percentile from media_popularity"
        ).fetchall()
    }
    assert got[1] == (2, 1.0) and got[2] == (1, 0.5) and got[3] == (0, 0.0)


def test_build_similarities_writes_lsa_and_skips_missing_methods(db: Conn) -> None:
    seed_media(db, 1, 2, 3)
    v = version(db)
    for media_id, vec in ((1, [1.0, 0.0]), (2, [0.9, 0.1]), (3, [0.0, 1.0])):
        db.execute(
            "insert into media_lsa_vectors (media_id, model_version_id, vector) values (%s, %s, %s)",
            (media_id, v, vec + [0.0] * 198),
        )
    db.commit()

    metrics = run_build_similarities(db, {})

    assert metrics["skipped"] == ["item_cf"] and metrics["written"]["lsa"] == 6
    assert (1, 2, v) in rows(db)
    with pytest.raises(ValueError, match="Unknown"):
        run_build_similarities(db, {"methods": ["nope"]})
