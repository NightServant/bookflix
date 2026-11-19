from typing import Annotated

from fastapi import APIRouter
from pydantic import BaseModel, Field, StringConstraints

from bookflix_ml import summarize

router = APIRouter()


class SummarizeRequest(BaseModel):
    # Several short texts (reviews) joined by blank lines; each line is split into sentences on its own.
    text: Annotated[str, StringConstraints(strict=True, min_length=1, max_length=20000)]
    sentences: Annotated[int, Field(strict=True, ge=1, le=5)] = 3


@router.post("/summarize")
def summarize_text(body: SummarizeRequest) -> dict[str, str | None]:
    """A few whole sentences picked from the text, in their original order; `summary` is null when the text gives no good one."""
    summarizer = summarize.LsaSummarizer()
    result = summarizer.summarize(body.text, body.sentences, summarize.REVIEW_MAX_CHARS)

    return {"summary": result.summary if result else None, "method": summarizer.method}
