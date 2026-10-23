from fastapi.testclient import TestClient


def test_health_is_public(client: TestClient) -> None:
    response = client.get("/health")
    assert response.status_code == 200
    assert response.json() == {"status": "ok"}


def test_v1_requires_a_token(client: TestClient) -> None:
    assert client.get("/v1/models").status_code == 401


def test_v1_rejects_a_wrong_token(client: TestClient) -> None:
    assert client.get("/v1/models", headers={"Authorization": "Bearer nope"}).status_code == 401
