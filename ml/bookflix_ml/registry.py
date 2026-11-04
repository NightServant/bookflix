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
) -> None:
    conn.execute(
        """
        update model_versions
        set status = %s, metrics = %s, artifact_uri = coalesce(%s, artifact_uri),
            trained_at = case when %s = 'candidate' then now() else trained_at end, updated_at = now()
        where id = %s
        """,
        (status, Jsonb(metrics), artifact_uri, status, version_id),
    )
