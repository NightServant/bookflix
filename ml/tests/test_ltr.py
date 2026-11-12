import logging

import numpy as np
import pandas as pd
import pytest

from bookflix_ml import ltr
from tests.ltr_data import lists


def test_the_gate_refuses_49_999_labeled_impressions() -> None:
    with pytest.raises(ltr.NotEnoughData) as raised:
        ltr.check_gates(49_999)

    assert raised.value.skipped == {
        "reason": "too few labeled impressions",
        "have": 49_999,
        "need": 50_000,
    }
    ltr.check_gates(50_000)
    ltr.check_gates(10, minimum=10)


def test_the_split_is_chronological_and_no_reader_is_on_both_sides() -> None:
    # Readers 1-40 come back over and over; from list 150 on new readers read, and reader 7 is back in the last fifth.
    frame = lists(200)
    frame["user_id"] = np.where(
        frame["user_id"] <= 150, (frame["user_id"] - 1) % 40 + 1, 1000 + frame["user_id"]
    )
    frame.loc[frame["user_id"] == 1170, "user_id"] = 7

    train, validation, cut = ltr.split(frame)

    assert train["generated_at"].max() < cut <= validation["generated_at"].min()
    assert set(train["user_id"]).isdisjoint(validation["user_id"])
    assert 7 in set(validation["user_id"]) and 7 not in set(train["user_id"])
    assert not train.empty and validation["generated_at"].nunique() >= 1


def test_the_split_keeps_the_newest_fifth_as_validation() -> None:
    train, validation, _ = ltr.split(lists(100))

    assert len(validation) == 20 * 10 and len(train) == 80 * 10


def test_a_single_reader_leaves_nothing_to_train_on() -> None:
    with pytest.raises(ltr.NotEnoughData):
        ltr.split(lists(50, same_reader=True))


def test_rows_with_a_nan_feature_are_dropped_with_a_counted_warning(
    caplog: pytest.LogCaptureFixture,
) -> None:
    frame = lists(30)
    frame.loc[3, "popularity"] = np.nan
    frame.loc[40, "novelty"] = np.inf

    with caplog.at_level(logging.WARNING):
        cleaned, dropped = ltr.clean(frame)

    assert dropped == 2 and len(cleaned) == len(frame) - 2
    assert "Dropped 2 labeled impressions" in caplog.text


def test_training_is_deterministic_for_a_fixed_seed() -> None:
    frame = lists(200)

    a, b = ltr.train(frame, rounds=20), ltr.train(frame, rounds=20)

    assert a.booster.model_to_string() == b.booster.model_to_string()
    assert np.array_equal(ltr.score(a, frame), ltr.score(b, frame))


def test_scoring_holds_the_position_constant() -> None:
    frame = lists(200)
    model = ltr.train(frame, rounds=20)
    shuffled = frame.assign(position=np.random.default_rng(1).integers(0, 20, len(frame)))

    assert np.array_equal(ltr.score(model, frame), ltr.score(model, shuffled))
    assert ltr.score(model, frame.head(0)).size == 0


def test_the_model_beats_the_hybrid_order_on_held_out_lists() -> None:
    frame = lists(400)
    model = ltr.train(frame, rounds=60)

    result = ltr.compare(model, frame)

    assert result["n_eval_lists"] > 0 and result["n_eval_impressions"] == model.n_validation
    assert result["ndcg@10"] > result["hybrid_ndcg@10"] + 0.1


def test_the_model_does_not_beat_a_hybrid_order_that_is_already_perfect() -> None:
    frame = lists(400, hybrid_is_perfect=True)
    model = ltr.train(frame, rounds=60)

    result = ltr.compare(model, frame)

    assert result["hybrid_ndcg@10"] == pytest.approx(1.0) and result["ndcg@10"] <= 1.0
    assert not result["ndcg@10"] > result["hybrid_ndcg@10"]


def test_compare_only_looks_at_impressions_the_model_was_not_trained_on() -> None:
    frame = lists(100)
    model = ltr.train(frame, rounds=5)

    held = ltr.held_out(model, frame)

    assert held["generated_at"].min() >= model.validation_since
    assert set(held["user_id"]).isdisjoint(model.train_users)
    assert ltr.compare(model, frame.head(0))["n_eval_lists"] == 0


def test_the_artifact_round_trips() -> None:
    frame = lists(100)
    model = ltr.train(frame, rounds=5)

    back = ltr.from_artifact(ltr.to_artifact(model))

    assert np.allclose(ltr.score(model, frame), ltr.score(back, frame))
    assert back.validation_since == model.validation_since and back.train_users == model.train_users


def test_ndcg_at_k() -> None:
    assert ltr.ndcg_at_k([[0, 0, 0]]) == (0.0, 0)
    assert ltr.ndcg_at_k([[4, 3, 1, 0]]) == (pytest.approx(1.0), 1)
    worse, n = ltr.ndcg_at_k([[0, 0, 3]])
    assert n == 1 and 0 < worse < 1
    assert ltr.ndcg_at_k([[1, 0], [0, 0], [0, 1]])[1] == 2
    assert isinstance(pd.Series([1]).sum(), int | np.integer)
