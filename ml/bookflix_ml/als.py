"""Matrix factorisation (implicit-feedback ALS) over the same decayed interaction scores item-cf uses.

Only the item factors are kept: `build-similarities` turns them into cosine neighbors, so nothing here is served directly. Everything is
seeded and single-threaded, so the same scores give the same factors.
"""

import math
from dataclasses import dataclass

import numpy as np
from implicit.cpu.als import AlternatingLeastSquares
from scipy.sparse import csr_matrix
from threadpoolctl import threadpool_limits

from bookflix_ml import similarities

FACTORS = 64
REGULARIZATION = 0.05
ITERATIONS = 20
SEED = 42
# The data gate: fewer eligible interactions or readers than this and training is skipped (no version is created).
MIN_INTERACTIONS = 5000
MIN_USERS = 200


class NotEnoughData(Exception):
    """Too little data to train; `skipped` is what the job reports."""

    def __init__(self, reason: str, have: int, need: int) -> None:
        super().__init__(f"{reason}: {have} of {need}")
        self.skipped = {"reason": reason, "have": have, "need": need}


@dataclass
class AlsModel:
    item_ids: list[int]
    item_factors: np.ndarray


def check_gates(
    scores: dict[tuple[int, int], float],
    min_interactions: int = MIN_INTERACTIONS,
    min_users: int = MIN_USERS,
) -> None:
    """`NotEnoughData` below the gate. Run it before creating a version."""
    if len(scores) < min_interactions:
        raise NotEnoughData("too few interactions", len(scores), min_interactions)
    users = len({u for u, _ in scores})
    if users < min_users:
        raise NotEnoughData("too few readers", users, min_users)


def train(
    scores: dict[tuple[int, int], float],
    factors: int = FACTORS,
    regularization: float = REGULARIZATION,
    iterations: int = ITERATIONS,
    seed: int = SEED,
) -> AlsModel:
    """Fit item factors to the (user, media) scores. A NaN or infinite score is refused, since it would poison every factor."""
    if any(not math.isfinite(score) for score in scores.values()):
        raise ValueError("Interaction scores must be finite.")
    if not scores:
        return AlsModel([], np.zeros((0, factors), dtype=np.float32))

    users = sorted({u for u, _ in scores})
    items = sorted({i for _, i in scores})
    user_index = {u: n for n, u in enumerate(users)}
    item_index = {i: n for n, i in enumerate(items)}
    matrix = csr_matrix(
        (
            np.asarray(list(scores.values()), dtype=np.float32),
            ([user_index[u] for u, _ in scores], [item_index[i] for _, i in scores]),
        ),
        shape=(len(users), len(items)),
    )

    # implicit asks for the BLAS pool to be off: its own threads do the work, and a second pool only fights them.
    with threadpool_limits(1, "blas"):
        model = AlternatingLeastSquares(
            factors=factors,
            regularization=regularization,
            iterations=iterations,
            random_state=seed,
            num_threads=1,
            calculate_training_loss=False,
        )
        model.fit(matrix, show_progress=False)
    item_factors = np.nan_to_num(np.asarray(model.item_factors, dtype=np.float32))

    return AlsModel(items, item_factors)


def neighbors(model: AlsModel, live: set[int] | None = None, k: int = 50) -> similarities.Neighbors:
    """Up to `k` items closest by cosine of their factors, best first, among the `live` items (all when None).

    Only positive similarities are kept: a negative cosine is no sign the two are read together, and the sources multiply by the score.
    """
    keep = [n for n, item in enumerate(model.item_ids) if live is None or item in live]
    ids = [model.item_ids[n] for n in keep]
    found = similarities.top_k_cosine(ids, model.item_factors[keep], k)

    return {m: [(n, s) for n, s in pairs if s > 0] for m, pairs in found.items()}
