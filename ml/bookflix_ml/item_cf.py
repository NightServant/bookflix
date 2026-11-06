"""Item-item collaborative filtering over weighted, time-decayed implicit feedback."""

from collections.abc import Iterable
from datetime import UTC, datetime
from typing import Any

import numpy as np
from implicit.nearest_neighbours import CosineRecommender
from scipy.sparse import csr_matrix

# What the `interaction-weights` model version holds (config/bookflix.php seeds it); used when no active version exists.
DEFAULT_WEIGHTS: dict[str, float] = {
    "view": 1,
    "click": 1,
    "recommendation_click": 1.5,
    "library_add": 3,
    "reading_start": 4,
    "reading_progress": 0.05,
    "reading_complete": 8,
    "favorite": 6,
    "rating_midpoint": 5.5,
    "rating_scale": 1.2,
    "review": 4,
    "abandon": -4,
    "search": 0,
    "half_life_days": 90,
}

# Keys of the weight set that are parameters of a formula, not the weight of an event.
_PARAMETERS = {"rating_midpoint", "rating_scale", "half_life_days"}

BM25_K1 = 100.0
BM25_B = 0.8


def _event_weight(event: str, value: float | None, weights: dict[str, Any]) -> float | None:
    if event in _PARAMETERS or event == "rating" or event not in weights:
        return None

    if event == "reading_progress":
        return float(weights[event]) * (value or 0.0)

    return float(weights[event])


def interaction_scores(
    rows: Iterable[tuple[int, int, str, float | None, datetime]],
    weights: dict[str, Any],
    now: datetime,
) -> dict[tuple[int, int], float]:
    """Per (user, media): the sum of event weights, each halved every `half_life_days`. Totals of zero or less are dropped."""
    half_life = float(weights.get("half_life_days") or DEFAULT_WEIGHTS["half_life_days"])
    midpoint = float(weights.get("rating_midpoint", DEFAULT_WEIGHTS["rating_midpoint"]))
    scale = float(weights.get("rating_scale", DEFAULT_WEIGHTS["rating_scale"]))
    now = now if now.tzinfo else now.replace(tzinfo=UTC)

    totals: dict[tuple[int, int], float] = {}
    for user_id, media_id, event, value, occurred_at in rows:
        if event == "rating":
            if value is None:
                continue
            weight: float | None = (float(value) - midpoint) * scale
        else:
            weight = _event_weight(event, value, weights)

        if weight is None or weight == 0:
            continue

        occurred_at = occurred_at if occurred_at.tzinfo else occurred_at.replace(tzinfo=UTC)
        age_days = max((now - occurred_at).total_seconds() / 86400, 0.0)
        key = (user_id, media_id)
        totals[key] = totals.get(key, 0.0) + weight * 0.5 ** (age_days / half_life)

    return {key: total for key, total in totals.items() if total > 0}


def _bm25(matrix: csr_matrix) -> csr_matrix:
    """BM25 weights with the always-positive idf, so on a small matrix an item read by most users is not zeroed out (implicit's `bm25_weight` does that, giving NaN)."""
    users = matrix.shape[0]
    doc_freq = np.asarray((matrix > 0).sum(axis=0)).ravel()
    idf = np.log1p((users - doc_freq + 0.5) / (doc_freq + 0.5))
    lengths = np.asarray(matrix.sum(axis=1)).ravel()
    mean_length = lengths.mean() if lengths.size and lengths.mean() > 0 else 1.0

    weighted = matrix.tocoo(copy=True)
    norm = 1 - BM25_B + BM25_B * lengths[weighted.row] / mean_length
    weighted.data = (
        weighted.data * (BM25_K1 + 1) / (weighted.data + BM25_K1 * norm) * idf[weighted.col]
    )

    return weighted.tocsr()


def item_neighbors(
    scores: dict[tuple[int, int], float], k: int = 50
) -> dict[int, list[tuple[int, float]]]:
    """Up to `k` neighbors per item by cosine similarity of its BM25-weighted reader vector, best first.

    Never the item itself, and only items that share at least one reader.
    """
    if not scores:
        return {}

    users = sorted({u for u, _ in scores})
    items = sorted({i for _, i in scores})
    user_index = {u: n for n, u in enumerate(users)}
    item_index = {i: n for n, i in enumerate(items)}

    rows = [user_index[u] for u, _ in scores]
    cols = [item_index[i] for _, i in scores]
    matrix = csr_matrix(
        (np.asarray(list(scores.values()), dtype=np.float32), (rows, cols)),
        shape=(len(users), len(items)),
    )

    if len(items) < 2:
        return {}

    model = CosineRecommender(K=k + 1)
    model.fit(_bm25(matrix), show_progress=False)
    similarity = model.similarity.tocsr()

    neighbors: dict[int, list[tuple[int, float]]] = {}
    for n, media_id in enumerate(items):
        start, end = similarity.indptr[n], similarity.indptr[n + 1]
        pairs = [
            (items[int(j)], float(s))
            for j, s in zip(similarity.indices[start:end], similarity.data[start:end], strict=True)
            if int(j) != n and np.isfinite(s) and s > 0
        ]
        pairs.sort(key=lambda p: (-p[1], p[0]))
        if pairs:
            neighbors[media_id] = pairs[:k]

    return neighbors
