"""Postgres access for the training jobs: the direct DATABASE_URL, plain SQL, chunked writes."""

from datetime import datetime
from typing import Any

import psycopg
from pgvector.psycopg import register_vector

from app.settings import get_settings
from bookflix_ml.item_cf import DEFAULT_WEIGHTS

Conn = psycopg.Connection[Any]


def connect(url: str | None = None) -> Conn:
    url = url or get_settings().database_url
    if not url:
        raise RuntimeError("DATABASE_URL is not set.")

    conn: Conn = psycopg.connect(url)
    register_vector(conn)

    return conn


def fetch_corpus(conn: Conn) -> list[dict[str, Any]]:
    """Live media (not soft deleted, not merged away) with the names of their genres, themes, tags and characters."""
    rows = conn.execute(
        """
        select
            m.id, m.title, m.alternative_titles, m.description,
            array(select g.name from media_genres mg join genres g on g.id = mg.genre_id where mg.media_id = m.id order by g.name),
            array(select t.name from media_themes mt join themes t on t.id = mt.theme_id where mt.media_id = m.id order by t.name),
            array(select tg.name from media_tags mtg join tags tg on tg.id = mtg.tag_id where mtg.media_id = m.id order by tg.name),
            array(select c.name from media_characters mc join characters c on c.id = mc.character_id where mc.media_id = m.id order by c.name)
        from media m
        where m.deleted_at is null and m.merged_into_id is null
        order by m.id
        """
    ).fetchall()

    return [
        {
            "id": r[0],
            "title": r[1],
            "alternative_titles": r[2],
            "description": r[3],
            "genres": r[4],
            "themes": r[5],
            "tags": r[6],
            "characters": r[7],
        }
        for r in rows
    ]


def fetch_interactions(conn: Conn) -> list[tuple[int, int, str, float | None, datetime]]:
    """(user, media, event, value, occurred_at) of readers who may be learned from, as the `media_popularity` view counts them.

    Deactivated readers and readers who turned personalization off are out, and so is everything they did before they opted out; a
    missing profile row counts as on. Interactions without media, with soft deleted media and with media merged away are out too.
    """
    rows = conn.execute(
        """
        select i.user_id, i.media_id, i.event, i.value, i.occurred_at
        from user_interactions i
        join users u on u.id = i.user_id
        left join profiles p on p.user_id = u.id
        join media m on m.id = i.media_id
        where u.deactivated_at is null
            and coalesce(p.personalization_enabled, true)
            and i.media_id is not null
            and m.deleted_at is null
            and m.merged_into_id is null
        order by i.id
        """
    ).fetchall()

    return [
        (int(r[0]), int(r[1]), str(r[2]), None if r[3] is None else float(r[3]), r[4]) for r in rows
    ]


def fetch_interaction_weights(conn: Conn) -> dict[str, Any]:
    """The active `interaction-weights` version's parameters over the built-in defaults (a key it lacks keeps its default)."""
    row = conn.execute(
        "select parameters from model_versions where name = 'interaction-weights' and status = 'active'"
    ).fetchone()

    stored = row[0] if row and isinstance(row[0], dict) else {}

    return {**DEFAULT_WEIGHTS, **stored}
