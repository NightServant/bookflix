"""The labeled impressions learning to rank trains on (P8-R5), read from what Laravel logged when it served the tiles.

One labeled impression is one `recommendations` row that has at least one `impression` event, however often the event repeated. The label is
the strongest thing that happened to it in the `window_days` after it was first shown: 0 (nothing, or only a dismissal), 1 a click, 3 a library
add, 4 the reader starting to read it (from `user_interactions`). Features are the ones the ranker logged at serving time; nothing is recomputed.
"""

from datetime import UTC, datetime, timedelta
from typing import Any

import numpy as np
import pandas as pd

from bookflix_ml.db import Conn

FEATURES = [
    "content_similarity",
    "collaborative_score",
    "dewey_similarity",
    "popularity",
    "freshness",
    "personal_preference",
    "context",
    "novelty",
]
WINDOW_DAYS = 7
# Rows older than this are the ones pruning keeps only because they hold a dismissal; they would skew the sample (Laravel's `retention_days`).
RETENTION_DAYS = 90

COLUMNS = [
    "recommendation_id",
    "user_id",
    "media_id",
    "shelf",
    "generated_at",
    "position",
    "hybrid_position",
    "logged_score",
    *FEATURES,
    "label",
]

# The grade of each outcome; the highest one a row reached applies.
GRADES = {"click": 1, "library_add": 3, "reading_start": 4}

_SQL = """
with shown as (
    select r.id, r.user_id, r.media_id, r.shelf, r.position, r.score, r.reason_payload -> 'features' as features, r.generated_at,
        r.reason_payload ->> 'hybrid_position' as hybrid_position,
        min(e.occurred_at) as shown_at
    from recommendations r
    join recommendation_events e on e.recommendation_id = r.id and e.event = 'impression'
    join users u on u.id = r.user_id
    left join profiles p on p.user_id = u.id
    join media m on m.id = r.media_id
    where r.position is not null
        and r.generated_at >= %(oldest)s
        and u.deactivated_at is null
        and coalesce(p.personalization_enabled, true)
        and m.deleted_at is null
        and m.merged_into_id is null
    group by r.id
    having min(e.occurred_at) <= %(youngest)s
)
select s.id, s.user_id, s.media_id, s.shelf, s.generated_at, s.position, s.score, s.features, s.hybrid_position,
    greatest(
        0,
        coalesce((
            select max(case c.event when 'click' then 1 when 'library_add' then 3 else 0 end)
            from recommendation_events c
            where c.recommendation_id = s.id and c.event in ('click', 'library_add')
                and c.occurred_at >= s.shown_at and c.occurred_at <= s.shown_at + make_interval(days => %(days)s)
        ), 0),
        coalesce((
            select 4 from user_interactions i
            where i.user_id = s.user_id and i.media_id = s.media_id and i.event = 'reading_start'
                and i.occurred_at >= s.shown_at and i.occurred_at <= s.shown_at + make_interval(days => %(days)s)
            limit 1
        ), 0)
    ) as label
from shown s
order by s.generated_at, s.id
"""


def _hybrid_position(logged: Any, shown: int) -> int:
    """The place the hybrid ranker gave the title. A row logged before the key existed was served in hybrid order, so it is the shown place."""
    try:
        return int(logged)
    except (TypeError, ValueError):
        return shown


def fetch_labeled(
    conn: Conn, window_days: int = WINDOW_DAYS, now: datetime | None = None
) -> pd.DataFrame:
    """The labeled impressions of eligible readers (as `fetch_interactions` reads them: not deactivated, personalization on, live media).

    Only rows with a place in their shelf (older ones have none), generated within `RETENTION_DAYS`, and first shown at least `window_days`
    ago, so every outcome had its whole window to happen. A feature that was not logged is NaN. `position` is the place shown (a
    training feature); `hybrid_position` is the place in the hybrid order, which differs once a learned ranker serves the shelf.
    """
    now = (now or datetime.now(UTC)).replace(tzinfo=None)
    rows = conn.execute(
        _SQL,
        {
            "oldest": now - timedelta(days=RETENTION_DAYS),
            "youngest": now - timedelta(days=window_days),
            "days": window_days,
        },
    ).fetchall()

    records: list[dict[str, Any]] = []
    for r in rows:
        logged = r[7] if isinstance(r[7], dict) else {}
        record: dict[str, Any] = {
            "recommendation_id": int(r[0]),
            "user_id": int(r[1]),
            "media_id": int(r[2]),
            "shelf": str(r[3]),
            "generated_at": r[4],
            "position": int(r[5]),
            "hybrid_position": _hybrid_position(r[8], int(r[5])),
            "logged_score": float(r[6]),
            "label": int(r[9]),
        }
        for name in FEATURES:
            value = logged.get(name)
            record[name] = (
                float(value)
                if isinstance(value, int | float) and not isinstance(value, bool)
                else np.nan
            )
        records.append(record)

    return pd.DataFrame.from_records(records, columns=COLUMNS)
