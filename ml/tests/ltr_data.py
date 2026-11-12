"""Synthetic impressions for the learning-to-rank tests: lists whose relevance follows two of the logged features, shown in a random order."""

from datetime import UTC, datetime, timedelta

import numpy as np
import pandas as pd

from bookflix_ml.impressions import COLUMNS, FEATURES

LIST_SIZE = 10
BASE = datetime(2026, 9, 1, tzinfo=UTC).replace(tzinfo=None)


def lists(
    n_lists: int, seed: int = 0, hybrid_is_perfect: bool = False, same_reader: bool = False
) -> pd.DataFrame:
    """`n_lists` lists of ten, one per reader (or all by reader 1), an hour apart.

    The grade is high where `content_similarity` and `collaborative_score` are. The logged order is random, so the hybrid order is no
    better than chance, unless `hybrid_is_perfect` shows the best first.
    """
    rng = np.random.default_rng(seed)
    records = []
    for n in range(n_lists):
        features = rng.random((LIST_SIZE, len(FEATURES)))
        signal = 3 * features[:, 0] + 2 * features[:, 1] + rng.normal(0, 0.2, LIST_SIZE)
        label = np.digitize(signal, [3.0, 3.8, 4.4])
        order = np.argsort(-signal) if hybrid_is_perfect else rng.permutation(LIST_SIZE)
        for place, item in enumerate(order):
            records.append(
                {
                    "recommendation_id": n * LIST_SIZE + place + 1,
                    "user_id": 1 if same_reader else n + 1,
                    "media_id": int(item) + 1,
                    "shelf": "recommended",
                    "generated_at": BASE + timedelta(hours=n),
                    "position": place,
                    "logged_score": 0.5,
                    **{name: float(features[item, i]) for i, name in enumerate(FEATURES)},
                    "label": int(label[item]),
                }
            )

    return pd.DataFrame.from_records(records, columns=COLUMNS)
