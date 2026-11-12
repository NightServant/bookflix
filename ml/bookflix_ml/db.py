"""Postgres access for the training jobs: the direct DATABASE_URL, plain SQL, chunked writes."""

from collections.abc import Iterable, Iterator
from datetime import datetime
from typing import Any

import numpy as np
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


INTERACTION_BATCH = 10_000


def fetch_interactions(conn: Conn) -> Iterator[tuple[int, int, str, float | None, datetime]]:
    """(user, media, event, value, occurred_at) of readers who may be learned from, as the `media_popularity` view counts them.

    Deactivated readers and readers who turned personalization off are out, and so is everything they did before they opted out; a
    missing profile row counts as on. Interactions without media, with soft deleted media and with media merged away are out too.

    The table is the largest one the jobs read, so rows are streamed from a server-side cursor in batches of `INTERACTION_BATCH` for the
    caller to fold as they arrive, never held as a list. Read it to the end before committing or rolling back the connection: that
    closes the cursor.
    """
    with conn.cursor(name="ml_interactions") as cur:
        cur.itersize = INTERACTION_BATCH
        cur.execute(
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
        )
        for r in cur:
            yield int(r[0]), int(r[1]), str(r[2]), None if r[3] is None else float(r[3]), r[4]


def fetch_interaction_weights(conn: Conn) -> dict[str, Any]:
    """The active `interaction-weights` version's parameters over the built-in defaults (a key it lacks keeps its default)."""
    row = conn.execute(
        "select parameters from model_versions where name = 'interaction-weights' and status = 'active'"
    ).fetchone()

    stored = row[0] if row and isinstance(row[0], dict) else {}

    return {**DEFAULT_WEIGHTS, **stored}


def fetch_lsa_vectors(conn: Conn, version_id: int) -> tuple[list[int], np.ndarray]:
    """The stored vectors of one `lsa` version for media that are still live, in id order."""
    rows = conn.execute(
        """
        select v.media_id, v.vector
        from media_lsa_vectors v
        join media m on m.id = v.media_id
        where v.model_version_id = %s and m.deleted_at is null and m.merged_into_id is null
        order by v.media_id
        """,
        (version_id,),
    ).fetchall()

    ids = [int(r[0]) for r in rows]
    vectors = np.asarray([r[1].to_numpy() for r in rows], dtype=np.float32).reshape(len(rows), -1)

    return ids, vectors


def fetch_embedding_vectors(conn: Conn, version_id: int) -> tuple[list[int], np.ndarray]:
    """The stored embeddings of one `embedding` version for media that are still live, in id order."""
    rows = conn.execute(
        """
        select e.media_id, e.embedding
        from media_embeddings e
        join media m on m.id = e.media_id
        where e.model_version_id = %s and m.deleted_at is null and m.merged_into_id is null
        order by e.media_id
        """,
        (version_id,),
    ).fetchall()

    if not rows:
        return [], np.empty((0, 0), dtype=np.float32)

    ids = [int(r[0]) for r in rows]
    vectors = np.asarray([r[1].to_numpy() for r in rows], dtype=np.float32)

    return ids, vectors


def fetch_live_media_ids(conn: Conn) -> set[int]:
    return {
        int(r[0])
        for r in conn.execute(
            "select id from media where deleted_at is null and merged_into_id is null"
        ).fetchall()
    }


def fetch_similarity_rows(
    conn: Conn,
    version_id: int,
    method: str,
    media_ids: Iterable[int] | None = None,
    similar_ids: Iterable[int] | None = None,
) -> dict[int, list[tuple[int, float]]]:
    """One version's stored neighbors per media, for media that are still live on both sides.

    `media_ids` and `similar_ids` narrow the rows to those sources and those neighbors, so a caller that only ranks from some items does
    not load the whole table.
    """
    rows = conn.execute(
        """
        select s.media_id, s.similar_media_id, s.score
        from media_similarities s
        join media a on a.id = s.media_id and a.deleted_at is null and a.merged_into_id is null
        join media b on b.id = s.similar_media_id and b.deleted_at is null and b.merged_into_id is null
        where s.model_version_id = %s and s.method = %s
            and (%s::bigint[] is null or s.media_id = any(%s::bigint[]))
            and (%s::bigint[] is null or s.similar_media_id = any(%s::bigint[]))
        """,
        (
            version_id,
            method,
            *_ids_twice(media_ids),
            *_ids_twice(similar_ids),
        ),
    ).fetchall()

    neighbors: dict[int, list[tuple[int, float]]] = {}
    for media_id, similar, score in rows:
        neighbors.setdefault(int(media_id), []).append((int(similar), float(score)))

    return neighbors


def _ids_twice(ids: Iterable[int] | None) -> tuple[list[int] | None, list[int] | None]:
    listed = None if ids is None else sorted(set(ids))

    return listed, listed
