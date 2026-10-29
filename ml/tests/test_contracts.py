from collections.abc import Callable
from typing import Any

from fastapi.testclient import TestClient


def test_models_response_matches_the_shared_fixture(
    client: TestClient, auth_headers: dict[str, str], contract: Callable[[str], Any]
) -> None:
    assert client.get("/v1/models", headers=auth_headers).json() == contract("models.json")
