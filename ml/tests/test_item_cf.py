from datetime import UTC, datetime, timedelta

import pytest

from bookflix_ml.item_cf import DEFAULT_WEIGHTS, interaction_scores, item_neighbors

NOW = datetime(2026, 11, 1, tzinfo=UTC)
W = DEFAULT_WEIGHTS


def test_events_are_weighted_and_decayed_by_a_90_day_half_life() -> None:
    rows = [
        (1, 10, "library_add", None, NOW),
        (1, 10, "reading_complete", None, NOW - timedelta(days=90)),
    ]
    assert interaction_scores(rows, W, NOW)[(1, 10)] == pytest.approx(3 + 8 * 0.5)


def test_reading_progress_scales_with_percent_points() -> None:
    assert interaction_scores([(1, 1, "reading_progress", 30.0, NOW)], W, NOW)[
        (1, 1)
    ] == pytest.approx(1.5)


def test_ratings_are_centered_on_5_5() -> None:
    assert interaction_scores([(1, 1, "rating", 9.0, NOW)], W, NOW)[(1, 1)] == pytest.approx(4.2)


def test_the_stored_midpoint_and_scale_are_used() -> None:
    weights = {**W, "rating_midpoint": 5.0, "rating_scale": 2.0}
    assert interaction_scores([(1, 1, "rating", 9.0, NOW)], weights, NOW)[(1, 1)] == pytest.approx(
        8
    )


def test_net_negative_items_are_dropped_and_unknown_events_ignored() -> None:
    rows = [
        (1, 1, "abandon", None, NOW),
        (1, 2, "mystery", None, NOW),
        (1, 3, "search", None, NOW),
        (1, 4, "rating", None, NOW),
        (1, 5, "rating", 2.0, NOW),
    ]
    assert interaction_scores(rows, W, NOW) == {}


def test_an_abandon_cancels_earlier_positive_signals() -> None:
    rows = [(1, 1, "view", None, NOW), (1, 1, "abandon", None, NOW)]
    assert interaction_scores(rows, W, NOW) == {}


def test_naive_timestamps_are_read_as_utc_and_the_future_does_not_inflate() -> None:
    naive = datetime(2026, 11, 1)
    assert interaction_scores([(1, 1, "view", None, naive)], W, NOW)[(1, 1)] == pytest.approx(1)
    future = NOW + timedelta(days=30)
    assert interaction_scores([(1, 1, "view", None, future)], W, NOW)[(1, 1)] == pytest.approx(1)


def test_neighbors_prefer_more_shared_readers_and_exclude_self() -> None:
    a, b, c, d, e = 1, 2, 3, 4, 5
    scores = {
        (1, a): 5.0,
        (1, b): 5.0,
        (2, a): 5.0,
        (2, b): 5.0,
        (3, a): 5.0,
        (3, c): 5.0,
        (4, d): 5.0,
        (4, e): 5.0,
    }
    nb = item_neighbors(scores, k=50)
    ranked = [i for i, _ in nb[a]]
    assert ranked[:2] == [b, c]
    assert a not in ranked and d not in ranked


def test_a_single_user_or_empty_input_does_not_fail() -> None:
    assert item_neighbors({}, k=50) == {}
    assert item_neighbors({(1, 1): 3.0}, k=50) == {}
    nb = item_neighbors({(1, 1): 3.0, (1, 2): 3.0}, k=50)
    assert nb[1][0][0] == 2 and 1 not in [i for i, _ in nb[1]]


def test_neighbor_scores_are_descending_finite_and_at_most_k() -> None:
    import math
    import random

    rng = random.Random(1)
    scores = {
        (u, i): rng.uniform(0.5, 8) for u in range(40) for i in range(30) if rng.random() < 0.3
    }
    nb = item_neighbors(scores, k=5)
    assert nb
    for item, neighbors in nb.items():
        values = [s for _, s in neighbors]
        assert len(neighbors) <= 5
        assert values == sorted(values, reverse=True)
        assert all(math.isfinite(v) and v > 0 for v in values)
        assert item not in [i for i, _ in neighbors]
