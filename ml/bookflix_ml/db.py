"""Postgres access for the training jobs: the direct DATABASE_URL, plain SQL, chunked writes."""

from typing import Any

import psycopg
from pgvector.psycopg import register_vector

from app.settings import get_settings

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
