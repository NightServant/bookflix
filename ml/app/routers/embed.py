from typing import Annotated, Any

from fastapi import APIRouter, HTTPException, status
from pydantic import BaseModel, Field, StringConstraints

from bookflix_ml import embeddings

router = APIRouter()


class EmbedRequest(BaseModel):
    texts: Annotated[
        list[Annotated[str, StringConstraints(strict=True, max_length=8000)]],
        Field(min_length=1, max_length=128),
    ]
    kind: embeddings.Kind


@router.post("/embed")
def embed(body: EmbedRequest) -> dict[str, Any]:
    """Vectors for up to 128 texts with the model loaded at start-up; 503 when none is (the `embed` extra or its weights are missing)."""
    try:
        embedder = embeddings.current()
    except embeddings.EmbedderUnavailable as e:
        raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, str(e)) from None

    vectors = embeddings.embed(embedder, body.texts, body.kind)

    return {
        "model_version": embeddings.model_version(embedder),
        "dims": embedder.dims,
        "vectors": vectors.tolist(),
    }
