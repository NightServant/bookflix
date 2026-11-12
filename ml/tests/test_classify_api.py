from collections.abc import Callable, Iterator
from pathlib import Path
from typing import Any

import pytest
from fastapi.testclient import TestClient

from app.settings import get_settings
from bookflix_ml import artifacts, embeddings, lsa
from bookflix_ml.embeddings import FakeEmbedder
from bookflix_ml.jobs import run_train_dewey_classifier
from tests.db_helpers import Conn
from tests.test_dewey import SIZES
from tests.test_dewey_job_db import seed_training, version

pytestmark = pytest.mark.db

BODY = {
    "media_id": 7,
    "text": "Berserk\nA lone mercenary fights demons in a dark medieval world.",
    "genres": ["Dark Fantasy", "Action"],
    "themes": ["Revenge"],
    "subjects": ["Mercenaries"],
    "external_code": None,
}


@pytest.fixture
def classifier(
    db: Conn,
    artifact_dir: Path,
    fake_embedder: FakeEmbedder,
    corpus: list[dict[str, Any]],
    monkeypatch: pytest.MonkeyPatch,
) -> Iterator[str]:
    """An active classifier trained on the active embedding and LSA versions (whose artifact can transform new text); yields its version."""
    import os

    monkeypatch.setenv("DATABASE_URL", os.environ["ML_TEST_DATABASE_URL"])
    get_settings.cache_clear()
    _, lsa_id = seed_training(db)
    fitted = lsa.train_lsa(corpus)
    uri = artifacts.save({"vectorizer": fitted.vectorizer, "svd": fitted.svd}, "lsa", "test")
    db.execute("update model_versions set artifact_uri = %s where id = %s", (uri, lsa_id))
    db.commit()
    run_train_dewey_classifier(db, {})
    row = db.execute(
        "select version from model_versions where name = 'dewey-classifier' and status = 'active'"
    ).fetchone()
    assert row is not None
    yield str(row[0])
    get_settings.cache_clear()


def classify(client: TestClient, headers: dict[str, str], body: dict[str, Any] = BODY) -> Any:
    return client.post("/v1/classify/dewey", json=body, headers=headers)


def test_it_answers_the_likeliest_known_classes_with_the_classifier_version(
    client: TestClient,
    auth_headers: dict[str, str],
    classifier: str,
    fake_embedder: FakeEmbedder,
    contract: Callable[[str], Any],
) -> None:
    response = classify(client, auth_headers)

    assert response.status_code == 200
    body = response.json()
    assert body.keys() == contract("classify-dewey.json").keys()
    assert body["candidates"][0].keys() == contract("classify-dewey.json")["candidates"][0].keys()
    assert body["model_version"] == classifier
    assert 1 <= len(body["candidates"]) <= 5
    assert {c["code"] for c in body["candidates"]} <= set(SIZES)
    confidences = [c["confidence"] for c in body["candidates"]]
    assert confidences == sorted(confidences, reverse=True) and sum(confidences) <= 1 + 1e-6
    # The text is embedded here: a new work has no stored vector.
    assert fake_embedder.calls and fake_embedder.calls[0][0].startswith("passage: Berserk")


def test_the_same_request_gives_the_same_answer(
    client: TestClient, auth_headers: dict[str, str], classifier: str
) -> None:
    assert classify(client, auth_headers).json() == classify(client, auth_headers).json()


def test_a_token_is_required(client: TestClient, classifier: str) -> None:
    assert client.post("/v1/classify/dewey", json=BODY).status_code == 401


def test_without_a_loaded_model_it_is_503(
    client: TestClient,
    auth_headers: dict[str, str],
    classifier: str,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(embeddings, "_loaded", None)

    assert classify(client, auth_headers).status_code == 503


def test_without_an_active_classifier_it_is_503(
    client: TestClient, auth_headers: dict[str, str], classifier: str, db: Conn
) -> None:
    db.execute("update model_versions set status = 'retired' where name = 'dewey-classifier'")
    db.commit()

    assert classify(client, auth_headers).status_code == 503


@pytest.mark.parametrize(("name", "kind"), [("embedding", "embedding"), ("lsa", "lsa")])
def test_a_newer_embedding_or_lsa_version_than_the_one_trained_on_is_503(
    client: TestClient,
    auth_headers: dict[str, str],
    classifier: str,
    db: Conn,
    fake_embedder: FakeEmbedder,
    name: str,
    kind: str,
) -> None:
    """Two models' vectors are never compared: the classifier waits for its retraining."""
    db.execute("update model_versions set status = 'retired' where name = %s", (name,))
    db.commit()
    version(
        db,
        name,
        kind,
        model=fake_embedder.name,
        revision=fake_embedder.revision,
        dims=fake_embedder.dims,
    )

    response = classify(client, auth_headers)

    assert response.status_code == 503
    assert "trained on" in response.json()["detail"]


def test_a_loaded_model_other_than_the_active_embedding_versions_is_503(
    client: TestClient,
    auth_headers: dict[str, str],
    classifier: str,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(embeddings, "_loaded", FakeEmbedder(revision="newer"))

    assert classify(client, auth_headers).status_code == 503


@pytest.mark.parametrize(
    "change",
    [
        pytest.param({"text": "   "}, id="blank text"),
        pytest.param({"text": "x" * 20001}, id="text over 20000 chars"),
        pytest.param({"text": 3}, id="text not a string"),
        pytest.param({"media_id": 0}, id="media id below 1"),
        pytest.param({"media_id": "7"}, id="media id not an integer"),
        pytest.param({"genres": ["x"] * 51}, id="more than 50 genres"),
        pytest.param({"themes": "Revenge"}, id="themes not a list"),
        pytest.param({"subjects": ["x" * 256]}, id="a subject over 255 chars"),
        pytest.param({"external_code": "x" * 17}, id="external code over 16 chars"),
    ],
)
def test_invalid_requests_are_422(
    client: TestClient,
    auth_headers: dict[str, str],
    fake_embedder: FakeEmbedder,
    change: dict[str, Any],
) -> None:
    assert classify(client, auth_headers, {**BODY, **change}).status_code == 422
    assert fake_embedder.calls == []
