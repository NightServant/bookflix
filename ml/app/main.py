from fastapi import APIRouter, Depends, FastAPI

from app.auth import require_token
from app.settings import get_settings

app = FastAPI(title="Bookflix ML")

v1 = APIRouter(prefix="/v1", dependencies=[Depends(require_token)])


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@v1.get("/models")
def models() -> dict[str, dict[str, str | int]]:
    settings = get_settings()

    return {"embedding": {"name": settings.embedding_model, "dims": settings.embedding_dims}}


app.include_router(v1)
