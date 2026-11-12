from pathlib import Path
from typing import Any

import numpy as np
import pytest
from psycopg.types.json import Jsonb

from bookflix_ml import artifacts, dewey
from bookflix_ml.embeddings import FakeEmbedder
from bookflix_ml.jobs import JOBS, run_train_dewey_classifier
from tests.db_helpers import Conn, count
from tests.test_dewey import SIZES, synthetic

pytestmark = pytest.mark.db


def version(db: Conn, name: str, kind: str, status: str = "active", **parameters: Any) -> int:
    row = db.execute(
        "insert into model_versions (name, version, kind, status, parameters) values (%s, %s, %s, %s, %s) returning id",
        (name, f"v{count(db, 'model_versions')}", kind, status, Jsonb(parameters)),
    ).fetchone()
    db.commit()
    assert row is not None
    return int(row[0])


def seed_training(db: Conn, sizes: dict[str, int] = SIZES) -> tuple[int, int]:
    """Live media with vectors under an active embedding and LSA version and an accepted primary class each; returns both version ids."""
    fake = FakeEmbedder()
    embedding = version(
        db, "embedding", "embedding", model=fake.name, revision=fake.revision, dims=fake.dims
    )
    lsa = version(db, "lsa", "lsa")
    emb, lsa_vectors, labels = synthetic(sizes)
    for code in sorted(set(labels)):
        db.execute("insert into dewey_classes (code) values (%s) on conflict do nothing", (code,))
    for i, code in enumerate(labels, 1):
        db.execute(
            "insert into media (id, title, description) values (%s, %s, 'x')", (i, f"Media {i}")
        )
        db.execute(
            "insert into media_embeddings (media_id, model_version_id, embedding, content_hash) values (%s, %s, %s, %s)",
            (i, embedding, emb[i - 1], "h" * 64),
        )
        db.execute(
            "insert into media_lsa_vectors (media_id, model_version_id, vector) values (%s, %s, %s)",
            (i, lsa, lsa_vectors[i - 1]),
        )
        db.execute(
            """
            insert into media_dewey_classes (media_id, dewey_class_id, classification_type, review_status)
            select %s, id, 'primary', 'accepted' from dewey_classes where code = %s
            """,
            (i, code),
        )
    db.commit()
    return embedding, lsa


def classifier_versions(db: Conn) -> list[tuple[int, str]]:
    return [
        (int(r[0]), str(r[1]))
        for r in db.execute(
            "select id, status from model_versions where name = 'dewey-classifier' order by id"
        ).fetchall()
    ]


def test_trains_on_accepted_primaries_and_promotes_a_version_that_beats_the_baseline(
    db: Conn, artifact_dir: Path
) -> None:
    embedding, lsa = seed_training(db)

    metrics = run_train_dewey_classifier(db, {})

    [(version_id, status)] = classifier_versions(db)
    assert (
        status == "active" and metrics["version_id"] == version_id and metrics["promoted"] is True
    )
    assert metrics["accuracy"] > metrics["baseline"] and metrics["n_classes"] == 5
    row = db.execute(
        "select kind, parameters, artifact_uri from model_versions where id = %s", (version_id,)
    ).fetchone()
    assert row is not None
    assert row[0] == "dewey_classifier"
    assert row[1]["embedding_version_id"] == embedding and row[1]["lsa_version_id"] == lsa
    model = artifacts.load(row[2])
    assert isinstance(model, dewey.DeweyModel) and sorted(model.classes) == sorted(SIZES)


def test_a_newer_version_retires_the_active_one(db: Conn, artifact_dir: Path) -> None:
    seed_training(db)
    first = run_train_dewey_classifier(db, {})["version_id"]

    second = run_train_dewey_classifier(db, {})["version_id"]

    assert classifier_versions(db) == [(first, "retired"), (second, "active")]


def test_a_version_not_above_the_majority_baseline_is_retired_never_promoted(
    db: Conn, artifact_dir: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    seed_training(db)
    active = run_train_dewey_classifier(db, {})["version_id"]
    train = dewey.train

    def no_better(emb: np.ndarray, lsa: np.ndarray, labels: list[str]) -> Any:
        model, metrics = train(emb, lsa, labels)
        return model, {**metrics, "accuracy": metrics["baseline"]}

    monkeypatch.setattr(dewey, "train", no_better)

    metrics = run_train_dewey_classifier(db, {})

    assert metrics["promoted"] is False and "baseline" in metrics["reason"]
    assert classifier_versions(db) == [(active, "active"), (metrics["version_id"], "retired")]


@pytest.mark.parametrize(
    ("sizes", "reason", "have"),
    [
        pytest.param(
            {"813": 40, "741.52": 30, "895.6": 20}, "too few accepted primaries", 90, id="samples"
        ),
        pytest.param({"813": 80, "741.52": 40}, "too few classes", 2, id="classes"),
    ],
)
def test_too_little_data_skips_cleanly_without_a_version(
    db: Conn, artifact_dir: Path, sizes: dict[str, int], reason: str, have: int
) -> None:
    seed_training(db, sizes)

    metrics = run_train_dewey_classifier(db, {})

    assert metrics == {
        "skipped": {"reason": reason, "have": have, "need": 100 if have == 90 else 3}
    }
    assert classifier_versions(db) == []


@pytest.mark.parametrize("missing", ["embedding", "lsa"])
def test_no_active_embedding_or_lsa_version_skips_cleanly(
    db: Conn, artifact_dir: Path, missing: str
) -> None:
    seed_training(db)
    db.execute("update model_versions set status = 'retired' where name = %s", (missing,))
    db.commit()

    metrics = run_train_dewey_classifier(db, {})

    assert metrics == {"skipped": {"reason": f"no active {missing} version", "have": 0, "need": 1}}
    assert classifier_versions(db) == []


def test_only_accepted_primaries_of_live_media_with_vectors_of_the_active_versions_count(
    db: Conn, artifact_dir: Path
) -> None:
    embedding, _ = seed_training(db)
    total = sum(SIZES.values())
    db.execute("update media_dewey_classes set review_status = 'pending' where media_id = 1")
    db.execute("update media_dewey_classes set review_status = 'rejected' where media_id = 2")
    db.execute(
        "update media_dewey_classes set classification_type = 'secondary' where media_id = 3"
    )
    db.execute("update media set deleted_at = now() where id = 4")
    db.execute("update media set merged_into_id = 6 where id = 5")
    other = version(db, "embedding", "embedding", "candidate")
    db.execute(
        "update media_embeddings set model_version_id = %s where media_id = 7 and model_version_id = %s",
        (other, embedding),
    )
    db.commit()

    metrics = run_train_dewey_classifier(db, {})

    assert metrics["n_train"] + metrics["n_test"] == total - 6


def test_the_job_is_registered() -> None:
    assert "train-dewey-classifier" in JOBS
