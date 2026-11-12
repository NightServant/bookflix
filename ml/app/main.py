from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import APIRouter, Depends, FastAPI

from app.auth import require_token
from app.routers import classify, embed, jobs
from app.settings import get_settings
from bookflix_ml import embeddings

_docs = get_settings().ml_enable_docs


@asynccontextmanager
async def lifespan(_: FastAPI) -> AsyncIterator[None]:
    """Load the embedding model once, before the first request; the service still starts without it."""
    settings = get_settings()
    embeddings.load(settings.embedding_model, settings.embedding_revision, settings.embedding_dims)
    yield


app = FastAPI(
    title="Bookflix ML",
    docs_url="/docs" if _docs else None,
    redoc_url="/redoc" if _docs else None,
    openapi_url="/openapi.json" if _docs else None,
    lifespan=lifespan,
)

v1 = APIRouter(prefix="/v1", dependencies=[Depends(require_token)])


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@v1.get("/models")
def models() -> dict[str, dict[str, str | int]]:
    settings = get_settings()

    return {
        "embedding": {
            "name": settings.embedding_model,
            "revision": settings.embedding_revision,
            "dims": settings.embedding_dims,
        }
    }


v1.include_router(embed.router)
v1.include_router(classify.router)
v1.include_router(jobs.router)

app.include_router(v1)
