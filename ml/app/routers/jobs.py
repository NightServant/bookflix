from typing import Any

from fastapi import APIRouter, HTTPException, status
from pydantic import BaseModel

from bookflix_ml.jobs import JobRunner, UnknownJob

router = APIRouter(prefix="/jobs")
runner = JobRunner()


class StartJob(BaseModel):
    params: dict[str, Any] = {}


@router.post("/{name}", status_code=status.HTTP_202_ACCEPTED)
def start(name: str, body: StartJob | None = None) -> dict[str, str]:
    try:
        return {"run_id": runner.submit(name, (body.params if body else {}))}
    except UnknownJob:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "Unknown job") from None


@router.get("/{run_id}")
def show(run_id: str) -> dict[str, Any]:
    run = runner.get(run_id)
    if run is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "Unknown run")

    return run
