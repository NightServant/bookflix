"""The Dewey classifier: kNN over sentence embeddings averaged with logistic regression over LSA vectors, both calibrated.

It learns from the primary classes reviewers accepted and only ever suggests: PHP caps its confidence below the public threshold, so a
reviewer still decides. Everything is seeded, so the same data gives the same model.
"""

from collections import Counter
from dataclasses import dataclass
from typing import Any

import numpy as np
from sklearn.base import BaseEstimator, ClassifierMixin
from sklearn.calibration import CalibratedClassifierCV
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import StratifiedKFold, train_test_split
from sklearn.neighbors import KNeighborsClassifier

K = 15
SEED = 42
# The data gate: fewer accepted primaries or classes than this and training is skipped (no version is created).
MIN_SAMPLES = 100
MIN_CLASSES = 3
# A class with fewer accepted primaries than this cannot be split for calibration; it is left out, so it is never predicted.
MIN_PER_CLASS = 5
# Isotonic calibration needs data; below this many samples in the smallest class the sigmoid is used instead.
ISOTONIC_MIN = 10
HOLDOUT = 0.2
MAX_CANDIDATES = 5


class NotEnoughData(Exception):
    """Too little accepted data to train; `skipped` is what the job reports."""

    def __init__(self, reason: str, have: int, need: int) -> None:
        super().__init__(f"{reason}: {have} of {need}")
        self.skipped = {"reason": reason, "have": have, "need": need}


def usable(labels: list[str]) -> list[bool]:
    """Which samples belong to a class with at least `MIN_PER_CLASS` of them."""
    counts = Counter(labels)
    return [counts[label] >= MIN_PER_CLASS for label in labels]


def check(labels: list[str]) -> None:
    """The data gate over the usable samples; `NotEnoughData` when it fails. Run it before creating a version."""
    kept = [label for label, ok in zip(labels, usable(labels), strict=True) if ok]
    if len(kept) < MIN_SAMPLES:
        raise NotEnoughData("too few accepted primaries", len(kept), MIN_SAMPLES)
    if len(set(kept)) < MIN_CLASSES:
        raise NotEnoughData("too few classes", len(set(kept)), MIN_CLASSES)


class Ensemble(ClassifierMixin, BaseEstimator):  # type: ignore[misc]
    """kNN (cosine) over the first `split` columns, the embedding, averaged with logistic regression over the rest, the LSA vector.

    One estimator over both feature sets, so the calibration sees the average itself: two calibrated models that disagree average to
    probabilities that are too low.
    """

    def __init__(self, split: int = 384, k: int = K) -> None:
        self.split = split
        self.k = k

    def fit(self, features: np.ndarray, labels: Any) -> "Ensemble":
        k = max(1, min(self.k, len(labels) - 1))
        self.knn_ = KNeighborsClassifier(n_neighbors=k, metric="cosine", algorithm="brute")
        self.knn_.fit(features[:, : self.split], labels)
        self.lr_ = LogisticRegression(max_iter=2000, random_state=SEED)
        self.lr_.fit(features[:, self.split :], labels)
        self.classes_ = self.knn_.classes_
        return self

    def predict_proba(self, features: np.ndarray) -> np.ndarray:
        return np.asarray(
            (
                self.knn_.predict_proba(features[:, : self.split])
                + self.lr_.predict_proba(features[:, self.split :])
            )
            / 2
        )

    def predict(self, features: np.ndarray) -> np.ndarray:
        return np.asarray(self.classes_[self.predict_proba(features).argmax(axis=1)])


@dataclass
class DeweyModel:
    """What the job stores: the calibrated ensemble, its class order and the widths of the vectors it was fitted on."""

    classes: list[str]
    model: CalibratedClassifierCV
    embedding_width: int
    lsa_width: int

    def predict_proba(self, embeddings: np.ndarray, lsa: np.ndarray) -> np.ndarray:
        """Calibrated probabilities, one row per sample, columns in `classes` order."""
        return np.asarray(self.model.predict_proba(np.hstack([embeddings, lsa])), dtype=np.float64)

    def candidates(self, embedding: np.ndarray, lsa: np.ndarray) -> list[dict[str, Any]]:
        """The `MAX_CANDIDATES` likeliest classes of one sample, strongest first (ties by code)."""
        row = self.predict_proba(embedding.reshape(1, -1), lsa.reshape(1, -1))[0]
        order = sorted(range(len(self.classes)), key=lambda i: (-row[i], self.classes[i]))
        return [
            {"code": self.classes[i], "confidence": round(float(row[i]), 4)}
            for i in order[:MAX_CANDIDATES]
            if row[i] > 0
        ]


def fit(embeddings: np.ndarray, lsa: np.ndarray, labels: list[str]) -> DeweyModel:
    """The ensemble calibrated by cross-validation: isotonic, or the sigmoid when the smallest class has under `ISOTONIC_MIN` samples."""
    smallest = min(Counter(labels).values())
    folds = StratifiedKFold(n_splits=min(3, smallest), shuffle=True, random_state=SEED)
    method = "isotonic" if smallest >= ISOTONIC_MIN else "sigmoid"
    model = CalibratedClassifierCV(Ensemble(int(embeddings.shape[1])), method=method, cv=folds)
    model.fit(np.hstack([embeddings, lsa]), labels)

    return DeweyModel(
        [str(c) for c in model.classes_], model, int(embeddings.shape[1]), int(lsa.shape[1])
    )


def train(
    embeddings: np.ndarray, lsa: np.ndarray, labels: list[str]
) -> tuple[DeweyModel, dict[str, Any]]:
    """Fit on a stratified split, score the held-out part, then refit on everything; samples of too-small classes are left out.

    `accuracy` is the held-out top-1 accuracy; `baseline` is what always answering the commonest class would score there;
    `calibration_error` is how far the mean top confidence is from the accuracy.
    """
    check(labels)
    keep = np.asarray(usable(labels))
    embeddings, lsa = embeddings[keep], lsa[keep]
    labels = [label for label, ok in zip(labels, keep, strict=True) if ok]
    classes = len(set(labels))

    test_size = max(round(len(labels) * HOLDOUT), classes)
    e_train, e_test, l_train, l_test, y_train, y_test = train_test_split(
        embeddings, lsa, labels, test_size=test_size, stratify=labels, random_state=SEED
    )
    held_out = fit(e_train, l_train, y_train)
    probabilities = held_out.predict_proba(e_test, l_test)
    predicted = [held_out.classes[i] for i in probabilities.argmax(axis=1)]
    accuracy = float(np.mean([p == y for p, y in zip(predicted, y_test, strict=True)]))
    commonest = Counter(y_train).most_common(1)[0][0]
    baseline = float(np.mean([y == commonest for y in y_test]))
    confidence = float(probabilities.max(axis=1).mean())

    metrics = {
        "accuracy": round(accuracy, 4),
        "baseline": round(baseline, 4),
        "calibration_error": round(abs(confidence - accuracy), 4),
        "n_train": len(y_train),
        "n_test": len(y_test),
        "n_classes": classes,
    }

    return fit(embeddings, lsa, labels), metrics
