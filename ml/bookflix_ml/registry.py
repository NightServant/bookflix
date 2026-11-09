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


def require_candidate(conn: Conn, version_id: int, name: str) -> tuple[int, str, str | None]:
    """The id, method and artifact URI of the version `version_id`, which must be a `candidate` named `name`; `ValueError` otherwise.

    Pipelines can overlap on the one worker, so a step that was told which version its `train` made must use that one, not the newest.
    """
    row = conn.execute(
        "select kind, artifact_uri from model_versions where id = %s and name = %s and status = 'candidate'",
        (version_id, name),
    ).fetchone()
    if row is None:
        raise ValueError(f"Model version {version_id} is not a candidate of {name}.")

    return version_id, str(row[0]), row[1]


def parse_version_id(params: dict[str, Any]) -> int | None:
    """The optional `version_id` param as an int; `ValueError` when it is present but not an integer."""
    value = params.get("version_id")
    if value is None:
        return None
    if isinstance(value, bool) or not isinstance(value, int):
        raise ValueError("version_id must be an integer.")

    return value


def has_similarity_rows(conn: Conn, version_id: int, method: str) -> bool:
    row = conn.execute(
        "select exists (select 1 from media_similarities where model_version_id = %s and method = %s)",
        (version_id, method),
    ).fetchone()

    return bool(row and row[0])


def version_parameters(conn: Conn, version_id: int) -> dict[str, Any]:
    """The parameters a version was created with (for item-cf: its weights and `k`)."""
    row = conn.execute(
        "select parameters from model_versions where id = %s", (version_id,)
    ).fetchone()

    return {} if row is None or row[0] is None else dict(row[0])


def active_version(conn: Conn, name: str) -> tuple[int, dict[str, Any], dict[str, Any]] | None:
    """The active version of `name` (id, metrics, parameters), or None when none is active."""
    row = conn.execute(
        "select id, metrics, parameters from model_versions where name = %s and status = 'active'",
        (name,),
    ).fetchone()
    if row is None:
        return None

    return int(row[0]), dict(row[1] or {}), dict(row[2] or {})


def record_metrics(conn: Conn, version_id: int, metrics: dict[str, Any]) -> None:
    """Merge `metrics` into the version's stored ones, keeping what training wrote."""
    conn.execute(
        "update model_versions set metrics = metrics || %s, updated_at = now() where id = %s",
        (Jsonb(metrics), version_id),
    )


def promote(conn: Conn, version_id: int) -> None:
    """Make a `candidate` the active version of its name, in the caller's transaction (it does not commit).

    The current active version and every older candidate of the name are retired (only one may be active, and an older candidate can
    never be promoted after a newer one), `trained_at` stays as it was, and what the retired versions left behind is deleted so it does not
    pile up: the similarity rows of every version of that method that is neither active nor still a waiting candidate and, for `lsa`, the
    vectors and features of its retired versions. A newer candidate is never touched. A version that is not a candidate raises
    `ValueError` before anything changes.
    """
    row = conn.execute(
        "select name, kind, status from model_versions where id = %s for update", (version_id,)
    ).fetchone()
    if row is None or row[2] != "candidate":
        raise ValueError(f"Model version {version_id} is not a candidate.")
    name, method = row[0], row[1]

    conn.execute(
        """
        update model_versions set status = 'retired', updated_at = now()
        where name = %s and (status = 'active' or (status = 'candidate' and id < %s))
        """,
        (name, version_id),
    )
    conn.execute(
        "update model_versions set status = 'active', updated_at = now() where id = %s",
        (version_id,),
    )
    _prune(conn, name, method)


def retire(conn: Conn, version_id: int) -> None:
    """Retire a `candidate` the gate refused and delete what it left behind, in the caller's transaction (it does not commit).

    Uses the same cleanup as `promote`, so the active version and any other waiting candidate are never touched. A version that is
    not a candidate raises `ValueError` before anything changes.
    """
    row = conn.execute(
        "select name, kind, status from model_versions where id = %s for update", (version_id,)
    ).fetchone()
    if row is None or row[2] != "candidate":
        raise ValueError(f"Model version {version_id} is not a candidate.")

    conn.execute(
        "update model_versions set status = 'retired', updated_at = now() where id = %s",
        (version_id,),
    )
    _prune(conn, row[0], row[1])


def _prune(conn: Conn, name: str, method: str) -> None:
    """Delete the similarity rows of every version of `method` that is neither active nor a waiting candidate and, for `lsa`, the
    vectors and features of its retired versions."""
    conn.execute(
        """
        delete from media_similarities
        where method = %s
            and model_version_id not in (select id from model_versions where status in ('active', 'candidate'))
        """,
        (method,),
    )
    if name == "lsa":
        for table in ("media_lsa_vectors", "media_features"):
            conn.execute(
                f"delete from {table} where model_version_id in (select id from model_versions where name = 'lsa' and status = 'retired')"  # noqa: S608
            )
