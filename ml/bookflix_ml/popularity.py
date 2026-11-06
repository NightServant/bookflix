"""Refreshing the `media_popularity` view Laravel reads for Trending."""

from bookflix_ml.db import Conn


def refresh(conn: Conn) -> None:
    """Rebuild the view without locking its readers (it has the unique index `concurrently` needs).

    `refresh ... concurrently` cannot run inside a transaction block, so the caller's transaction is closed first and the
    connection is left in autocommit for the statement only.
    """
    conn.commit()
    previous = conn.autocommit
    conn.autocommit = True
    try:
        conn.execute("refresh materialized view concurrently media_popularity")
    finally:
        conn.autocommit = previous
