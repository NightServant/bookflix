import numpy as np
import pytest

from bookflix_ml import als


def blocks(users_per_block: int = 12, items_per_block: int = 6) -> dict[tuple[int, int], float]:
    """Two groups of readers, each reading only its own group of items."""
    scores: dict[tuple[int, int], float] = {}
    for block in (0, 1):
        for u in range(users_per_block):
            for i in range(items_per_block):
                scores[(block * 100 + u, block * 100 + i)] = 1.0 + (u + i) % 3

    return scores


def test_training_is_deterministic_for_a_fixed_seed() -> None:
    a = als.train(blocks(), 8, 0.05, 10, seed=7)
    b = als.train(blocks(), 8, 0.05, 10, seed=7)

    assert a.item_ids == b.item_ids
    assert np.allclose(a.item_factors, b.item_factors)


def test_item_factors_of_co_consumed_items_are_closer_than_unrelated_items() -> None:
    model = als.train(blocks(), 8, 0.05, 15)
    near = als.neighbors(model, k=3)

    for item in (0, 1, 2):
        assert {n for n, _ in near[item]} <= {0, 1, 2, 3, 4, 5} - {item}
    assert all(n >= 100 for n, _ in near[100])


def test_neighbors_exclude_self_are_top_k_descending_and_positive() -> None:
    near = als.neighbors(als.train(blocks(), 8, 0.05, 10), k=4)

    for item, pairs in near.items():
        assert item not in [n for n, _ in pairs] and len(pairs) <= 4
        scores = [s for _, s in pairs]
        assert scores == sorted(scores, reverse=True) and all(s > 0 for s in scores)


def test_neighbors_are_limited_to_the_live_items() -> None:
    model = als.train(blocks(), 8, 0.05, 10)

    near = als.neighbors(model, live={0, 1, 2, 3})

    assert set(near) == {0, 1, 2, 3}
    assert all(n in {0, 1, 2, 3} for pairs in near.values() for n, _ in pairs)


@pytest.mark.parametrize("bad", [float("nan"), float("inf"), float("-inf")])
def test_non_finite_scores_are_rejected(bad: float) -> None:
    with pytest.raises(ValueError, match="finite"):
        als.train({(1, 1): 1.0, (1, 2): bad}, 4, 0.05, 2)


def test_a_single_user_fixture_does_not_crash() -> None:
    model = als.train({(1, 10): 2.0, (1, 11): 1.0, (1, 12): 3.0}, 64, 0.05, 20)

    assert model.item_ids == [10, 11, 12]
    assert np.isfinite(model.item_factors).all()
    assert all(item not in [n for n, _ in pairs] for item, pairs in als.neighbors(model).items())
    assert als.train({}, 4, 0.05, 2).item_ids == []


def test_the_gate_refuses_too_few_interactions_or_readers() -> None:
    few = {(u, i): 1.0 for u in range(100) for i in range(49)}
    with pytest.raises(als.NotEnoughData) as raised:
        als.check_gates(few)
    assert raised.value.skipped == {"reason": "too few interactions", "have": 4900, "need": 5000}

    narrow = {(u, i): 1.0 for u in range(100) for i in range(60)}
    with pytest.raises(als.NotEnoughData) as raised:
        als.check_gates(narrow)
    assert raised.value.skipped == {"reason": "too few readers", "have": 100, "need": 200}

    als.check_gates({(u, i): 1.0 for u in range(200) for i in range(25)})
    als.check_gates(few, min_interactions=4900, min_users=100)
