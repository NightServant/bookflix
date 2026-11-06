import math
from datetime import UTC, datetime

import pytest

from bookflix_ml.evaluate import leave_last_out, metrics_at_k, should_promote

# Known answers: user 1 relevant {3}, recommended [3, 1, 2]; user 2 relevant {9}, recommended [1, 2, 3].
REC = {1: [3, 1, 2], 2: [1, 2, 3]}
REL = {1: {3}, 2: {9}}


def t(day: int) -> datetime:
    return datetime(2026, 1, day, tzinfo=UTC)


def test_metrics_on_a_fixture_with_known_answers() -> None:
    m = metrics_at_k(REC, REL, k=10, catalog_size=10)
    assert m["precision@10"] == pytest.approx((1 / 3 + 0) / 2)
    assert m["recall@10"] == pytest.approx(0.5)
    assert m["hit_rate@10"] == pytest.approx(0.5)
    assert m["map@10"] == pytest.approx(0.5)
    assert m["ndcg@10"] == pytest.approx(0.5)
    assert m["coverage"] == pytest.approx(3 / 10)


def test_ndcg_discounts_lower_ranks() -> None:
    assert metrics_at_k({1: [1, 2, 3]}, {1: {3}}, 10)["ndcg@10"] == pytest.approx(1 / math.log2(4))


def test_empty_input_and_users_without_relevant_items_do_not_divide_by_zero() -> None:
    assert metrics_at_k({}, {}, 10)["ndcg@10"] == 0.0
    assert metrics_at_k({1: [1]}, {1: set()}, 10)["recall@10"] == 0.0
    assert all(math.isfinite(v) for v in metrics_at_k({}, {}, 10, catalog_size=0).values())


def test_a_user_with_relevant_items_but_no_recommendations_counts_as_a_miss() -> None:
    m = metrics_at_k({}, {1: {5}}, 10)
    assert m["recall@10"] == 0.0 and m["hit_rate@10"] == 0.0


def test_diversity_and_novelty() -> None:
    m = metrics_at_k(
        {1: [1, 2]},
        {1: {1}},
        10,
        item_pop={1: 1, 2: 4},
        n_users=4,
        item_sim=lambda a, b: 1.0,
    )
    assert m["diversity"] == pytest.approx(0.0)
    assert m["novelty"] == pytest.approx((2 + 0) / 2)


def test_diversity_and_novelty_default_to_zero_without_their_inputs() -> None:
    m = metrics_at_k({1: [1, 2]}, {1: {1}}, 10)
    assert m["diversity"] == 0.0 and m["novelty"] == 0.0


def test_leave_last_out_splits_by_time_and_skips_single_item_users() -> None:
    inter = [(1, 10, t(1)), (1, 11, t(2)), (1, 12, t(3)), (2, 10, t(1))]
    train, held = leave_last_out(inter)
    assert held == {1: 12} and train[1] == {10, 11} and 2 not in held and 2 not in train


def test_leave_last_out_counts_repeat_events_on_one_item_once() -> None:
    train, held = leave_last_out([(1, 10, t(1)), (1, 10, t(2))])
    assert held == {} and train == {}


def test_a_regression_beyond_two_percent_blocks_promotion() -> None:
    active = {"ndcg@10": 0.50, "coverage": 0.20}
    assert should_promote({"ndcg@10": 0.49, "coverage": 0.21}, active) is True
    assert should_promote({"ndcg@10": 0.48, "coverage": 0.30}, active) is False
    assert should_promote({"ndcg@10": 0.60, "coverage": 0.19}, active) is False
    assert should_promote({"ndcg@10": 0.10, "coverage": 0.01}, None) is True
    assert (
        should_promote({"ndcg@10": 0.0, "coverage": 0.0}, {"ndcg@10": 0.0, "coverage": 0.0}) is True
    )


def test_an_active_version_that_was_never_evaluated_does_not_block_promotion() -> None:
    assert should_promote({"ndcg@10": 0.1, "coverage": 0.1}, {"n_users": 3}) is True
