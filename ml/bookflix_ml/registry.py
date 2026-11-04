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


def latest_candidate(conn: Conn, name: str) -> tuple[int, str] | None:
    """The newest `candidate` version of `name` (id and the similarity method its rows use), or None."""
    row = conn.execute(
        "select id, kind from model_versions where name = %s and status = 'candidate' order by id desc limit 1",
        (name,),
    ).fetchone()

    return None if row is None else (int(row[0]), str(row[1]))


def version_parameters(conn: Conn, version_id: int) -> dict[str, Any]:
    """The parameters a version was created with (for item-cf: its weights and `k`)."""
    row = conn.execute(
        "select parameters from model_versions where id = %s", (version_id,)
    ).fetchone()

    return {} if row is None or row[0] is None else dict(row[0])


def active_metrics(conn: Conn, name: str) -> dict[str, Any] | None:
    """The metrics of the active version of `name`, or None when none is active."""
    row = conn.execute(
        "select metrics from model_versions where name = %s and status = 'active'", (name,)
    ).fetchone()

    return None if row is None else dict(row[0])


def record_metrics(conn: Conn, version_id: int, metrics: dict[str, Any]) -> None:
    """Merge `metrics` into the version's stored ones, keeping what training wrote."""
    conn.execute(
        "update model_versions set metrics = metrics || %s, updated_at = now() where id = %s",
        (Jsonb(metrics), version_id),
    )


def promote(conn: Conn, version_id: int) -> None:
    """Make a `candidate` the active version of its name, in the caller's transaction (it does not commit).

    The current active version is retired first (only one may be active), `trained_at` stays as it was, and the similarity rows of
    every version of that method that is neither active nor still a waiting candidate are deleted, so a retired version's rows do not
    pile up. A version that is not a candidate raises `ValueError` before anything changes.
    """
    row = conn.execute(
        "select name, kind, status from model_versions where id = %s for update", (version_id,)
    ).fetchone()
    if row is None or row[2] != "candidate":
        raise ValueError(f"Model version {version_id} is not a candidate.")
    name, method = row[0], row[1]

    conn.execute(
        "update model_versions set status = 'retired', updated_at = now() where name = %s and status = 'active'",
        (name,),
    )
    conn.execute(
        "update model_versions set status = 'active', updated_at = now() where id = %s",
        (version_id,),
    )
    conn.execute(
        """
        delete from media_similarities
        where method = %s
            and model_version_id not in (select id from model_versions where status in ('active', 'candidate'))
        """,
        (method,),
    )
