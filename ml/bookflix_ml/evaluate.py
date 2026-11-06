"""Offline evaluation: ranking metrics, the leave-last-out split and the promotion gate. Pure functions, no database."""

import math
from collections import defaultdict
from collections.abc import Callable, Iterable
from datetime import datetime
from itertools import combinations
from typing import Any

MAX_NDCG_DROP = 0.02
# Slack for float noise, so "exactly 2% lower" is still allowed.
_EPSILON = 1e-9


def metrics_at_k(
    recommended: dict[int, list[int]],
    relevant: dict[int, set[int]],
    k: int = 10,
    catalog_size: int = 0,
    item_pop: dict[int, int] | None = None,
    item_sim: Callable[[int, int], float] | None = None,
    n_users: int = 0,
) -> dict[str, float]:
    """Mean ranking metrics over the users who have relevant items; every value is finite, 0.0 when there is nothing to average.

    A user with relevant items but no list counts as a miss. Precision divides by the list length (at most `k`). Coverage is distinct
    recommended items over the catalog, diversity one minus the mean pairwise similarity inside a list (0.0 without `item_sim`), and
    novelty the mean `-log2(popularity / n_users)` of the recommended items that have a popularity (0.0 without `item_pop`).
    """
    precision, recall, hit, average_precision, ndcg = [], [], [], [], []
    for user, wanted in relevant.items():
        if not wanted:
            continue

        top = recommended.get(user, [])[:k]
        hits = [item in wanted for item in top]
        n_hits = sum(hits)

        precision.append(n_hits / len(top) if top else 0.0)
        recall.append(n_hits / len(wanted))
        hit.append(1.0 if n_hits else 0.0)

        seen = 0
        precision_sum = 0.0
        for rank, is_hit in enumerate(hits, start=1):
            if is_hit:
                seen += 1
                precision_sum += seen / rank
        average_precision.append(precision_sum / min(len(wanted), k))

        dcg = sum(1 / math.log2(rank + 1) for rank, is_hit in enumerate(hits, start=1) if is_hit)
        ideal = sum(1 / math.log2(rank + 1) for rank in range(1, min(len(wanted), k) + 1))
        ndcg.append(dcg / ideal)

    lists = [items[:k] for items in recommended.values()]
    distinct = {item for items in lists for item in items}

    return {
        f"precision@{k}": _mean(precision),
        f"recall@{k}": _mean(recall),
        f"ndcg@{k}": _mean(ndcg),
        f"map@{k}": _mean(average_precision),
        f"hit_rate@{k}": _mean(hit),
        "coverage": len(distinct) / catalog_size if catalog_size > 0 else 0.0,
        "diversity": _diversity(lists, item_sim),
        "novelty": _novelty(lists, item_pop, n_users or len(recommended)),
    }


def _mean(values: list[float]) -> float:
    return sum(values) / len(values) if values else 0.0


def _diversity(lists: list[list[int]], item_sim: Callable[[int, int], float] | None) -> float:
    if item_sim is None:
        return 0.0

    per_list = [
        1 - _mean([item_sim(a, b) for a, b in combinations(items, 2)])
        for items in lists
        if len(items) >= 2
    ]

    return _mean(per_list)


def _novelty(lists: list[list[int]], item_pop: dict[int, int] | None, n_users: int) -> float:
    if not item_pop or n_users <= 0:
        return 0.0

    values = [
        -math.log2(item_pop[item] / n_users)
        for items in lists
        for item in items
        if item_pop.get(item, 0) > 0
    ]

    return _mean(values)


def leave_last_out(
    interactions: Iterable[tuple[int, int, datetime]],
) -> tuple[dict[int, set[int]], dict[int, int]]:
    """Per user: the items before their latest one (train) and that latest item (held out).

    Repeat events on one item count once, at their latest time. A user with fewer than two distinct positive items is skipped.
    Ties on time go to the higher media id, so the split is the same on every run.
    """
    latest: dict[int, dict[int, datetime]] = defaultdict(dict)
    for user, media, at in interactions:
        known = latest[user].get(media)
        if known is None or at > known:
            latest[user][media] = at

    train: dict[int, set[int]] = {}
    held: dict[int, int] = {}
    for user, items in latest.items():
        if len(items) < 2:
            continue
        last = max(items, key=lambda m: (items[m], m))
        held[user] = last
        train[user] = set(items) - {last}

    return train, held


def should_promote(
    candidate: dict[str, Any], active: dict[str, Any] | None, max_ndcg_drop: float = MAX_NDCG_DROP
) -> bool:
    """Promote when NDCG@10 falls by at most `max_ndcg_drop` (relative) and coverage does not fall.

    With no active version, or one that was never evaluated (no NDCG in its metrics), there is nothing to compare with: promote.
    """
    if active is None or "ndcg@10" not in active:
        return True

    baseline = float(active["ndcg@10"])
    ndcg_ok = float(candidate.get("ndcg@10", 0.0)) >= baseline * (1 - max_ndcg_drop) - _EPSILON
    coverage_ok = (
        float(candidate.get("coverage", 0.0)) >= float(active.get("coverage", 0.0)) - _EPSILON
    )

    return ndcg_ok and coverage_ok
