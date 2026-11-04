"""model_versions rows. These helpers never commit: the caller decides what belongs in one transaction."""

from datetime import UTC, datetime
from typing import Any

from psycopg.types.json import Jsonb

from bookflix_ml.db import Conn


def create_version(
    conn: Conn, name: str, kind: str, parameters: dict[str, Any], dataset_ref: str | None
) -> tuple[int, str]:
    """A new `training` version; returns its id and version string."""
    version = datetime.now(UTC).strftime("%Y%m%dT%H%M%S%fZ")
    row = conn.execute(
        """
        insert into model_versions (name, version, kind, status, dataset_ref, parameters, created_at, updated_at)
        values (%s, %s, %s, 'training', %s, %s, now(), now())
        returning id
        """,
        (name, version, kind, dataset_ref, Jsonb(parameters)),
    ).fetchone()
    assert row is not None

    return int(row[0]), version


def finish_version(
    conn: Conn,
    version_id: int,
    status: str,
    metrics: dict[str, Any],
    artifact_uri: str | None = None,
    parameters: dict[str, Any] | None = None,
) -> None:
    conn.execute(
        """
        update model_versions
        set status = %s, metrics = %s, artifact_uri = coalesce(%s, artifact_uri), parameters = parameters || %s,
            trained_at = case when %s = 'candidate' then now() else trained_at end, updated_at = now()
        where id = %s
        """,
        (status, Jsonb(metrics), artifact_uri, Jsonb(parameters or {}), status, version_id),
    )


def latest_version(conn: Conn, name: str) -> tuple[int, str | None] | None:
    """The newest `candidate` or `active` version of `name` (id and artifact URI), or None when there is none."""
    row = conn.execute(
        """
        select id, artifact_uri from model_versions
        where name = %s and status in ('candidate', 'active')
        order by id desc limit 1
        """,
        (name,),
    ).fetchone()

    return None if row is None else (int(row[0]), row[1])
