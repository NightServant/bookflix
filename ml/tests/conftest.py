import json
import os
from collections.abc import Callable
from pathlib import Path
from typing import Any

TOKEN = "test-token-0123456789-0123456789-abcdef"
os.environ["ML_SERVICE_TOKEN"] = TOKEN

import pytest  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402

from app.main import app  # noqa: E402

CONTRACTS = Path(__file__).resolve().parents[2] / "tests" / "Fixtures" / "ml-contracts"


@pytest.fixture
def client() -> TestClient:
    return TestClient(app)


@pytest.fixture
def auth_headers() -> dict[str, str]:
    return {"Authorization": f"Bearer {TOKEN}"}


@pytest.fixture
def contract() -> Callable[[str], Any]:
    def load(name: str) -> Any:
        return json.loads((CONTRACTS / name).read_text())

    return load
