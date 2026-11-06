import json
import os
from collections.abc import Callable, Iterator
from pathlib import Path
from typing import Any

TOKEN = "test-token-0123456789-0123456789-abcdef"
os.environ["ML_SERVICE_TOKEN"] = TOKEN

import psycopg  # noqa: E402
import pytest  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402

from app.main import app  # noqa: E402
from app.settings import get_settings  # noqa: E402
from bookflix_ml.db import connect  # noqa: E402
from bookflix_ml.jobs import JOBS, JobRunner  # noqa: E402

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


def pytest_collection_modifyitems(config: pytest.Config, items: list[pytest.Item]) -> None:
    """Skip db-marked tests unless ML_TEST_DATABASE_URL points at a test database."""
    if os.environ.get("ML_TEST_DATABASE_URL"):
        return
    skip = pytest.mark.skip(reason="ML_TEST_DATABASE_URL is not set")
    for item in items:
        if "db" in item.keywords:
            item.add_marker(skip)


@pytest.fixture
def corpus() -> list[dict[str, Any]]:
    docs: list[dict[str, Any]] = json.loads(
        (Path(__file__).parent / "fixtures" / "corpus.json").read_text()
    )
    return docs


@pytest.fixture
def db() -> Iterator[psycopg.Connection[Any]]:
    """A connection to a freshly built copy of the schema subset (tests/schema.sql)."""
    url = os.environ["ML_TEST_DATABASE_URL"]
    with psycopg.connect(url, autocommit=True) as setup:
        setup.execute((Path(__file__).parent / "schema.sql").read_text())
    with connect(url) as conn:
        yield conn


@pytest.fixture
def inline_runner(monkeypatch: pytest.MonkeyPatch) -> JobRunner:
    """Runs jobs in the request thread so a test can read the finished run straight away."""
    runner = JobRunner(inline=True)
    monkeypatch.setattr("app.routers.jobs.runner", runner)
    return runner


@pytest.fixture
def fake_jobs(monkeypatch: pytest.MonkeyPatch, inline_runner: JobRunner) -> None:
    monkeypatch.setitem(
        JOBS, "train-lsa", lambda params: {"items": 1200, "explained_variance": 0.41}
    )


@pytest.fixture
def failing_jobs(monkeypatch: pytest.MonkeyPatch, inline_runner: JobRunner) -> None:
    def boom(params: dict[str, Any]) -> dict[str, Any]:
        raise RuntimeError("boom")

    monkeypatch.setitem(JOBS, "train-lsa", boom)


@pytest.fixture
def artifact_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Iterator[Path]:
    """Points ARTIFACT_DIR at a temp folder (settings are cached, so they are rebuilt around the test)."""
    monkeypatch.setenv("ARTIFACT_DIR", str(tmp_path))
    monkeypatch.delenv("ARTIFACT_BUCKET", raising=False)
    get_settings.cache_clear()
    yield tmp_path
    get_settings.cache_clear()
