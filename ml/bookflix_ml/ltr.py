"""Learning to rank: a LightGBM LambdaMART model over the features the hybrid ranker logged when it served a tile (P8-R2, P8-R5).

The model learns which logged features led to clicks, library adds and reads. Position is a feature only at training, where it soaks up the
exposure bias (a tile at the top is seen more whatever it is); scoring sets it to one constant for every row, so it cannot rank anything.
Everything is seeded and single-threaded, so the same impressions give the same model.
"""

import logging
import math
from dataclasses import dataclass
from datetime import datetime
from typing import Any

import lightgbm as lgb
import numpy as np
import pandas as pd

from bookflix_ml.impressions import FEATURES

logger = logging.getLogger(__name__)

SEED = 42
# The data gate: fewer labeled impressions than this and training is skipped (no version is created).
MIN_LABELED = 50_000
TRAIN_SHARE = 0.8
ROUNDS = 200
K = 10
# What scoring sets the position to, for every row.
SCORING_POSITION = 0
# Fewer held-out lists with something relevant in them than this say nothing about a model: the evaluation retires it.
MIN_EVAL_LISTS = 20
# Scores are only read by the page while they are younger than this, and `score-ltr` reads only candidates this fresh.
FRESH_HOURS = 36
MODEL_FEATURES = [*FEATURES, "position"]
GROUP = ["generated_at", "user_id", "shelf"]

PARAMS: dict[str, Any] = {
    "objective": "lambdarank",
    "metric": "ndcg",
    "eval_at": [K],
    "learning_rate": 0.05,
    "num_leaves": 15,
    "min_data_in_leaf": 20,
    "seed": SEED,
    "deterministic": True,
    "force_row_wise": True,
    "num_threads": 1,
    "verbosity": -1,
}


class NotEnoughData(Exception):
    """Too little data to train; `skipped` is what the job reports."""

    def __init__(self, reason: str, have: int, need: int) -> None:
        super().__init__(f"{reason}: {have} of {need}")
        self.skipped = {"reason": reason, "have": have, "need": need}


@dataclass
class LtrModel:
    booster: lgb.Booster
    validation_since: datetime
    train_users: list[int]
    n_train: int
    n_validation: int
    n_dropped: int


def check_gates(n_labeled: int, minimum: int = MIN_LABELED) -> None:
    """`NotEnoughData` below the gate. Run it before creating a version."""
    if n_labeled < minimum:
        raise NotEnoughData("too few labeled impressions", n_labeled, minimum)


def clean(df: pd.DataFrame) -> tuple[pd.DataFrame, int]:
    """The rows with every feature and the position present and finite, and how many were dropped (logged as a warning)."""
    ok = np.isfinite(df[MODEL_FEATURES].to_numpy(dtype=float)).all(axis=1)
    dropped = int((~ok).sum())
    if dropped:
        logger.warning(
            "Dropped %d labeled impressions with a missing or non-finite feature.", dropped
        )

    return df[ok].reset_index(drop=True), dropped


def _ordered(df: pd.DataFrame) -> pd.DataFrame:
    """Rows grouped one list per (generated_at, user, shelf), lists oldest first, each list in its shown order."""
    return df.sort_values([*GROUP, "position", "recommendation_id"], kind="stable").reset_index(
        drop=True
    )


def split(df: pd.DataFrame) -> tuple[pd.DataFrame, pd.DataFrame, datetime]:
    """Chronological split of the lists: the oldest `TRAIN_SHARE` train, the newest validate, and no reader is in both.

    A list belongs to validation from the generation time of the list at the cut on (ties go to validation). A reader with a list there is
    left out of training altogether, so the model is never scored on someone it learned from. Returns train, validation and the cut.
    """
    ordered = _ordered(df)
    lists = ordered[GROUP].drop_duplicates()
    if len(lists) < 2:
        raise NotEnoughData("too few lists to split", len(lists), 2)

    cut = lists.iloc[min(len(lists) - 1, int(len(lists) * TRAIN_SHARE))]["generated_at"]
    validation = ordered[ordered["generated_at"] >= cut]
    train = ordered[
        (ordered["generated_at"] < cut) & ~ordered["user_id"].isin(validation["user_id"])
    ]
    if train.empty or validation.empty:
        raise NotEnoughData("too little history to split", len(train), 1)

    return train.reset_index(drop=True), validation.reset_index(drop=True), cut


def _sizes(df: pd.DataFrame) -> list[int]:
    return [int(n) for n in df.groupby(GROUP, sort=False).size()]


def train(df: pd.DataFrame, seed: int = SEED, rounds: int = ROUNDS) -> LtrModel:
    """Fit LambdaMART on the labeled impressions; rows with a NaN feature are dropped first, counted."""
    frame, dropped = clean(df)
    train_set, validation, cut = split(frame)

    dataset = lgb.Dataset(
        train_set[MODEL_FEATURES], label=train_set["label"], group=_sizes(train_set)
    )
    booster = lgb.train({**PARAMS, "seed": seed}, dataset, num_boost_round=rounds)

    return LtrModel(
        booster,
        cut,
        sorted({int(u) for u in train_set["user_id"]}),
        len(train_set),
        len(validation),
        dropped,
    )


def score(model: LtrModel, features: pd.DataFrame) -> np.ndarray:
    """Scores for rows of `features` (the eight features and a position column), with the position set to `SCORING_POSITION` for every row."""
    if features.empty:
        return np.empty(0)

    matrix = features[MODEL_FEATURES].copy()
    matrix["position"] = SCORING_POSITION

    return np.asarray(model.booster.predict(matrix), dtype=float)


def ndcg_at_k(labels_by_list: list[list[int]], k: int = K) -> tuple[float, int]:
    """Mean NDCG@k over the lists that have something relevant in them, each list's labels in its ranked order; also how many lists counted."""
    values = []
    for labels in labels_by_list:
        gains = [2**g - 1 for g in labels]
        if not any(gains):
            continue
        dcg = sum(g / math.log2(i + 2) for i, g in enumerate(gains[:k]))
        ideal = sum(g / math.log2(i + 2) for i, g in enumerate(sorted(gains, reverse=True)[:k]))
        values.append(dcg / ideal)

    return (sum(values) / len(values) if values else 0.0), len(values)


def held_out(model: LtrModel, df: pd.DataFrame) -> pd.DataFrame:
    """The impressions this model was not trained on: lists from its cut on, of readers it did not learn from."""
    frame, _ = clean(df)

    return _ordered(
        frame[
            (frame["generated_at"] >= model.validation_since)
            & ~frame["user_id"].isin(model.train_users)
        ]
    )


def compare(model: LtrModel, df: pd.DataFrame) -> dict[str, float]:
    """NDCG@10 of the model and of the hybrid order (the order the lists were shown in) on the same held-out lists.

    The model ranks each list by its score with the position held constant; ties keep the shown order.
    """
    frame = held_out(model, df)
    if frame.empty:
        return {"ndcg@10": 0.0, "hybrid_ndcg@10": 0.0, "n_eval_lists": 0, "n_eval_impressions": 0}

    frame = frame.assign(ltr_score=score(model, frame))
    hybrid, learned = [], []
    for _, rows in frame.groupby(GROUP, sort=False):
        hybrid.append(rows["label"].tolist())
        learned.append(
            rows.sort_values("ltr_score", ascending=False, kind="stable")["label"].tolist()
        )

    model_ndcg, lists = ndcg_at_k(learned)
    hybrid_ndcg, _ = ndcg_at_k(hybrid)

    return {
        "ndcg@10": model_ndcg,
        "hybrid_ndcg@10": hybrid_ndcg,
        "n_eval_lists": lists,
        "n_eval_impressions": len(frame),
    }


def to_artifact(model: LtrModel) -> dict[str, Any]:
    return {
        "booster": model.booster.model_to_string(),
        "validation_since": model.validation_since,
        "train_users": model.train_users,
        "n_train": model.n_train,
        "n_validation": model.n_validation,
        "n_dropped": model.n_dropped,
    }


def from_artifact(artifact: dict[str, Any]) -> LtrModel:
    return LtrModel(
        lgb.Booster(model_str=artifact["booster"]),
        artifact["validation_since"],
        list(artifact["train_users"]),
        int(artifact["n_train"]),
        int(artifact["n_validation"]),
        int(artifact["n_dropped"]),
    )
