from collections.abc import Callable
from typing import Any

import numpy as np
import pytest
from fastapi.testclient import TestClient

from bookflix_ml import embeddings
from bookflix_ml.embeddings import FakeEmbedder


def test_embed_requires_a_token(client: TestClient, fake_embedder: FakeEmbedder) -> None:
    response = client.post("/v1/embed", json={"texts": ["x"], "kind": "query"})

    assert response.status_code == 401
    assert fake_embedder.calls == []


def test_embed_returns_one_unit_vector_per_text(
    client: TestClient, auth_headers: dict[str, str], fake_embedder: FakeEmbedder
) -> None:
    response = client.post(
        "/v1/embed",
        json={"texts": ["dark fantasy", "space opera"], "kind": "query"},
        headers=auth_headers,
    )

    assert response.status_code == 200
    body = response.json()
    assert body["dims"] == 384
    assert body["model_version"] == f"{fake_embedder.name}@{fake_embedder.revision}"
    vectors = np.asarray(body["vectors"])
    assert vectors.shape == (2, 384)
    assert np.allclose(np.linalg.norm(vectors, axis=1), 1.0, atol=1e-5)
    assert fake_embedder.calls == [["query: dark fantasy", "query: space opera"]]


def test_embed_response_has_the_shape_of_the_shared_fixture(
    client: TestClient,
    auth_headers: dict[str, str],
    fake_embedder: FakeEmbedder,
    contract: Callable[[str], Any],
) -> None:
    fixture = contract("embed.json")

    body = client.post(
        "/v1/embed", json={"texts": ["x"], "kind": "passage"}, headers=auth_headers
    ).json()

    assert body.keys() == fixture.keys()
    assert body["dims"] == fixture["dims"]
    assert len(body["vectors"][0]) == len(fixture["vectors"][0]) == fixture["dims"]
    assert body["model_version"].split("@")[0] == fixture["model_version"].split("@")[0]


@pytest.mark.parametrize(
    "body",
    [
        pytest.param({"texts": [], "kind": "query"}, id="empty texts"),
        pytest.param({"texts": ["x"] * 129, "kind": "passage"}, id="more than 128 texts"),
        pytest.param({"texts": ["x" * 8001], "kind": "passage"}, id="a text over 8000 chars"),
        pytest.param({"texts": ["x"], "kind": "document"}, id="bad kind"),
        pytest.param({"texts": ["x"]}, id="no kind"),
        pytest.param({"texts": "x", "kind": "query"}, id="texts not a list"),
        pytest.param({"texts": [3], "kind": "query"}, id="a text not a string"),
    ],
)
def test_invalid_requests_are_422(
    client: TestClient,
    auth_headers: dict[str, str],
    fake_embedder: FakeEmbedder,
    body: dict[str, Any],
) -> None:
    assert client.post("/v1/embed", json=body, headers=auth_headers).status_code == 422
    assert fake_embedder.calls == []


@pytest.mark.parametrize(
    "text",
    [
        pytest.param("dark fantasy マンガ con intriga política", id="mixed language"),
        pytest.param("", id="empty"),
        pytest.param("🐉⚔️👑 dragons", id="emoji"),
        pytest.param("🐉" * 8000, id="8000 emoji"),
        pytest.param("long " * 1600, id="exactly 8000 chars"),
        pytest.param("ダークファンタジー漫画", id="japanese"),
    ],
)
def test_awkward_texts_still_get_a_unit_vector(
    client: TestClient, auth_headers: dict[str, str], fake_embedder: FakeEmbedder, text: str
) -> None:
    response = client.post(
        "/v1/embed", json={"texts": [text], "kind": "query"}, headers=auth_headers
    )

    assert response.status_code == 200
    vector = np.asarray(response.json()["vectors"][0])
    assert vector.shape == (384,)
    assert abs(float(np.linalg.norm(vector)) - 1.0) < 1e-5
    assert fake_embedder.calls == [[f"query: {text}"]]


def test_128_texts_are_accepted_in_order(
    client: TestClient, auth_headers: dict[str, str], fake_embedder: FakeEmbedder
) -> None:
    texts = [f"t{i}" for i in range(128)]

    body = client.post(
        "/v1/embed", json={"texts": texts, "kind": "passage"}, headers=auth_headers
    ).json()

    expected = embeddings.embed(FakeEmbedder(), texts, "passage")
    assert np.allclose(np.asarray(body["vectors"]), expected, atol=1e-6)


def test_embed_is_503_when_no_model_is_loaded(
    client: TestClient, auth_headers: dict[str, str], monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setattr(embeddings, "_loaded", None)

    response = client.post(
        "/v1/embed", json={"texts": ["x"], "kind": "query"}, headers=auth_headers
    )

    assert response.status_code == 503


def test_the_service_starts_without_the_embed_extra(
    auth_headers: dict[str, str], monkeypatch: pytest.MonkeyPatch
) -> None:
    """Starting the app tries to load the model once; without the extra it still serves, and /v1/embed says 503."""
    import sys

    from app.main import app

    monkeypatch.setattr(embeddings, "_loaded", None)
    monkeypatch.setitem(sys.modules, "sentence_transformers", None)

    with TestClient(app) as started:
        assert started.get("/health").status_code == 200
        response = started.post(
            "/v1/embed", json={"texts": ["x"], "kind": "query"}, headers=auth_headers
        )

    assert response.status_code == 503
