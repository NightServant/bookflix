from typing import Annotated, Any, Self

import psycopg
from fastapi import APIRouter, HTTPException, status
from pydantic import BaseModel, Field, StringConstraints, model_validator

from bookflix_ml import db, dewey, embeddings

router = APIRouter(prefix="/classify")

Name = Annotated[str, StringConstraints(strict=True, max_length=255)]


class ClassifyDeweyRequest(BaseModel):
    media_id: Annotated[int, Field(strict=True, ge=1)]
    # The work's title and description apart, as `embed-media` builds its passage text from them; a work needs one of the two.
    title: Annotated[str, StringConstraints(strict=True, max_length=500)] = ""
    text: Annotated[str, StringConstraints(strict=True, max_length=20000)]
    genres: Annotated[list[Name], Field(max_length=50)] = []
    themes: Annotated[list[Name], Field(max_length=50)] = []
    subjects: Annotated[list[Name], Field(max_length=50)] = []
    # Accepted for the contract; the classifier learns from text, and PHP already resolves a provider's own code.
    external_code: Annotated[str | None, StringConstraints(strict=True, max_length=16)] = None

    @model_validator(mode="after")
    def not_blank(self) -> Self:
        if not self.title.strip() and not self.text.strip():
            raise ValueError("The title and the text are both blank.")
        return self


@router.post("/dewey")
def classify_dewey(body: ClassifyDeweyRequest) -> dict[str, Any]:
    """Dewey class candidates for one work from the active classifier; 503 when no model is loaded, no classifier is active, or the
    classifier was trained on vectors other than the active ones, or the database cannot be read (PHP then classifies by its rules
    alone). The vectors and artifacts it reads are the service's own, kept after first use."""
    try:
        embedder = embeddings.current()
        with db.connect() as conn:
            version, model, lsa_artifact = dewey.active(conn, embedder)
    except (embeddings.EmbedderUnavailable, dewey.ClassifierUnavailable, psycopg.Error) as e:
        raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, str(e)) from None

    candidates = dewey.classify(
        embedder,
        model,
        lsa_artifact,
        body.title,
        body.text,
        body.genres,
        body.themes,
        body.subjects,
    )

    return {"candidates": candidates, "model_version": version}
