from fastapi.testclient import TestClient


def test_health_reports_ok(client: TestClient) -> None:
    assert client.get("/health").json() == {"status": "ok"}
