from collections.abc import Callable
from typing import Any

from fastapi.testclient import TestClient


def test_start_job_returns_run_id_and_status_is_pollable(
    client: TestClient,
    auth_headers: dict[str, str],
    contract: Callable[[str], Any],
    fake_jobs: None,
) -> None:
    r = client.post("/v1/jobs/train-lsa", json={"params": {}}, headers=auth_headers)
    assert r.status_code == 202
    assert set(r.json()) == set(contract("jobs-start.json"))

    status = client.get(f"/v1/jobs/{r.json()['run_id']}", headers=auth_headers).json()
    assert set(status) == set(contract("job-succeeded.json"))
    assert status["status"] == "succeeded"
    assert status["metrics"] == {"items": 1200, "explained_variance": 0.41}
    assert status["error"] is None


def test_unknown_job_and_unknown_run_are_404(
    client: TestClient, auth_headers: dict[str, str]
) -> None:
    assert (
        client.post("/v1/jobs/nope", json={"params": {}}, headers=auth_headers).status_code == 404
    )
    assert client.get("/v1/jobs/missing", headers=auth_headers).status_code == 404


def test_bad_params_are_422(
    client: TestClient, auth_headers: dict[str, str], fake_jobs: None
) -> None:
    r = client.post("/v1/jobs/train-lsa", json={"params": [1]}, headers=auth_headers)
    assert r.status_code == 422


def test_jobs_require_a_token(client: TestClient) -> None:
    assert client.post("/v1/jobs/train-lsa", json={"params": {}}).status_code == 401
    assert client.get("/v1/jobs/anything").status_code == 401


def test_a_raising_job_is_reported_as_failed_with_the_error(
    client: TestClient,
    auth_headers: dict[str, str],
    contract: Callable[[str], Any],
    failing_jobs: None,
) -> None:
    run = client.post("/v1/jobs/train-lsa", json={"params": {}}, headers=auth_headers).json()[
        "run_id"
    ]
    body = client.get(f"/v1/jobs/{run}", headers=auth_headers).json()
    assert set(body) == set(contract("job-failed.json"))
    assert body["status"] == "failed"
    assert "boom" in body["error"]
    assert body["finished_at"] is not None
