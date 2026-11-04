"""Row builders for the db-marked tests, writing straight into tests/schema.sql's tables."""

import json
from datetime import UTC, datetime
from typing import Any

import psycopg

Conn = psycopg.Connection[Any]


def seed_corpus(conn: Conn, corpus: list[dict[str, Any]]) -> None:
    for doc in corpus:
        conn.execute(
            "insert into media (id, title, alternative_titles, description) values (%s, %s, %s, %s)",
            (doc["id"], doc["title"], json.dumps(doc["alternative_titles"]), doc["description"]),
        )
        for table, column, key in (
            ("genres", "genre_id", "genres"),
            ("themes", "theme_id", "themes"),
            ("tags", "tag_id", "tags"),
            ("characters", "character_id", "characters"),
        ):
            for name in doc[key]:
                row = conn.execute(f"select id from {table} where name = %s", (name,)).fetchone()  # noqa: S608
                if row is None:
                    row = conn.execute(
                        f"insert into {table} (name) values (%s) returning id",  # noqa: S608
                        (name,),
                    ).fetchone()
                assert row is not None
                conn.execute(
                    f"insert into media_{table} (media_id, {column}) values (%s, %s)",  # noqa: S608
                    (doc["id"], row[0]),
                )
    conn.commit()


def count(conn: Conn, table: str) -> int:
    row = conn.execute(f"select count(*) from {table}").fetchone()  # noqa: S608
    assert row is not None
    return int(row[0])


def version_status(conn: Conn, name: str) -> str | None:
    row = conn.execute(
        "select status from model_versions where name = %s order by id desc limit 1", (name,)
    ).fetchone()
    return None if row is None else str(row[0])


def now() -> datetime:
    return datetime.now(UTC)


def seed_users(
    conn: Conn,
    active: int | None = None,
    deactivated: int | None = None,
    opted_out: int | None = None,
    no_profile: int | None = None,
) -> dict[int, int]:
    """Users by id: active, deactivated, opted out (profile with personalization off) and one without a profile row."""
    for user_id, deactivated_at in (
        (active, None),
        (deactivated, now()),
        (opted_out, None),
        (no_profile, None),
    ):
        if user_id is not None:
            conn.execute(
                "insert into users (id, deactivated_at) values (%s, %s)", (user_id, deactivated_at)
            )
    for user_id, enabled in ((active, True), (deactivated, True), (opted_out, False)):
        if user_id is not None:
            conn.execute(
                "insert into profiles (user_id, personalization_enabled) values (%s, %s)",
                (user_id, enabled),
            )
    conn.commit()

    return {u: u for u in (active, deactivated, opted_out, no_profile) if u is not None}


def seed_media(conn: Conn, *ids: int) -> None:
    for media_id in ids:
        conn.execute(
            "insert into media (id, title, description) values (%s, %s, 'x') on conflict do nothing",
            (media_id, f"Media {media_id}"),
        )
    conn.commit()


def seed_interaction(
    conn: Conn,
    user_id: int,
    media_id: int | None,
    event: str = "library_add",
    value: float | None = None,
    occurred_at: datetime | None = None,
) -> None:
    conn.execute(
        "insert into user_interactions (user_id, media_id, event, value, occurred_at) values (%s, %s, %s, %s, %s)",
        (user_id, media_id, event, value, occurred_at or now()),
    )
    conn.commit()
