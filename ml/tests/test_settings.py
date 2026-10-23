import pytest
from fastapi.testclient import TestClient
from pydantic import ValidationError

from app.settings import Settings


@pytest.mark.parametrize("token", ["", "short", "x" * 31])
def test_a_missing_or_short_token_is_rejected(token: str) -> None:
    with pytest.raises(ValidationError):
        Settings(ml_service_token=token)


def test_a_32_character_token_is_accepted() -> None:
    assert Settings(ml_service_token="x" * 32).ml_service_token == "x" * 32


@pytest.mark.parametrize("path", ["/docs", "/redoc", "/openapi.json"])
def test_api_docs_are_off_by_default(client: TestClient, path: str) -> None:
    assert client.get(path).status_code == 404
