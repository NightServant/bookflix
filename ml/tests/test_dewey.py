import numpy as np
import pytest

from bookflix_ml import dewey
from bookflix_ml.dewey import NotEnoughData


def synthetic(
    sizes: dict[str, int], noise: float = 6.3, seed: int = 0, lsa_dims: int = 200
) -> tuple[np.ndarray, np.ndarray, list[str]]:
    """Embedding and LSA vectors around one centroid per class, noisy enough that the classes overlap (held-out accuracy about 0.75)."""
    rng = np.random.default_rng(seed)
    emb, lsa, labels = [], [], []
    for code, n in sizes.items():
        emb_centre = rng.standard_normal(dewey_dims())
        lsa_centre = rng.standard_normal(lsa_dims)
        for _ in range(n):
            emb.append(emb_centre + noise * rng.standard_normal(dewey_dims()))
            lsa.append(lsa_centre + noise * rng.standard_normal(lsa_dims))
            labels.append(code)
    order = rng.permutation(len(labels))
    return (
        unit(np.asarray(emb, dtype=np.float32)[order]),
        unit(np.asarray(lsa, dtype=np.float32)[order]),
        [labels[i] for i in order],
    )


def dewey_dims() -> int:
    return 384


def unit(m: np.ndarray) -> np.ndarray:
    return np.asarray(m / np.linalg.norm(m, axis=1, keepdims=True), dtype=np.float32)


SIZES = {"813": 70, "741.52": 50, "895.6": 30, "520": 20, "900": 12}


def test_accuracy_beats_the_majority_class_baseline_on_a_held_out_split() -> None:
    emb, lsa, labels = synthetic(SIZES)

    _, metrics = dewey.train(emb, lsa, labels)

    assert metrics["accuracy"] >= metrics["baseline"] + 0.10
    assert metrics["n_train"] + metrics["n_test"] == len(labels)
    assert metrics["n_classes"] == 5


def test_probabilities_sum_to_at_most_one_and_are_calibrated_on_the_fixture() -> None:
    emb, lsa, labels = synthetic(SIZES)

    model, metrics = dewey.train(emb, lsa, labels)
    probabilities = model.predict_proba(emb, lsa)

    assert np.all(probabilities.sum(axis=1) <= 1 + 1e-6)
    assert np.all(probabilities >= 0)
    assert metrics["calibration_error"] <= 0.15


def test_the_same_data_gives_the_same_model() -> None:
    emb, lsa, labels = synthetic(SIZES)

    a, metrics_a = dewey.train(emb, lsa, labels)
    b, metrics_b = dewey.train(emb, lsa, labels)

    assert metrics_a == metrics_b
    assert np.array_equal(a.predict_proba(emb[:20], lsa[:20]), b.predict_proba(emb[:20], lsa[:20]))


def test_unknown_labels_and_classes_too_small_to_learn_are_never_predicted() -> None:
    emb, lsa, labels = synthetic({**SIZES, "100": 2})

    model, metrics = dewey.train(emb, lsa, labels)
    other_emb, other_lsa, _ = synthetic({"999": 20, "100": 5}, seed=7)

    assert "100" not in model.classes and metrics["n_classes"] == 5
    for row in range(len(other_emb)):
        codes = {c["code"] for c in model.candidates(other_emb[row], other_lsa[row])}
        assert codes <= set(SIZES)


def test_candidates_are_the_best_few_strongest_first() -> None:
    emb, lsa, labels = synthetic(SIZES)
    model, _ = dewey.train(emb, lsa, labels)

    candidates = model.candidates(emb[0], lsa[0])

    assert 1 <= len(candidates) <= dewey.MAX_CANDIDATES
    confidences = [c["confidence"] for c in candidates]
    assert confidences == sorted(confidences, reverse=True)
    assert sum(confidences) <= 1 + 1e-6
    assert {c["code"] for c in candidates} <= set(SIZES)


@pytest.mark.parametrize(
    ("sizes", "reason", "have", "need"),
    [
        pytest.param(
            {"813": 40, "741.52": 30, "895.6": 20},
            "too few accepted primaries",
            90,
            100,
            id="90 samples",
        ),
        pytest.param({"813": 80, "741.52": 40}, "too few classes", 2, 3, id="2 classes"),
        pytest.param(
            {"813": 80, "741.52": 40, "520": 4}, "too few classes", 2, 3, id="third class too small"
        ),
    ],
)
def test_too_little_data_raises_not_enough_data_with_its_reason(
    sizes: dict[str, int], reason: str, have: int, need: int
) -> None:
    labels = [code for code, n in sizes.items() for _ in range(n)]

    with pytest.raises(NotEnoughData) as raised:
        dewey.check(labels)

    assert raised.value.skipped == {"reason": reason, "have": have, "need": need}
