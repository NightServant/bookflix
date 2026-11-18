from typing import Any

from fastapi.testclient import TestClient

from bookflix_ml.summarize import MAX_CHARS, REVIEW_MAX_CHARS, LsaSummarizer, split_sentences
from tests.test_summarize import REVIEWS, STORY


def post(client: TestClient, headers: dict[str, str], body: dict[str, Any]) -> Any:
    return client.post("/v1/summarize", json=body, headers=headers)


def test_summarize_requires_a_token(client: TestClient) -> None:
    assert client.post("/v1/summarize", json={"text": REVIEWS}).status_code == 401
    assert (
        client.post(
            "/v1/summarize", json={"text": REVIEWS}, headers={"Authorization": "Bearer nope"}
        ).status_code
        == 401
    )


def test_it_summarizes_across_reviews_with_whole_input_sentences(
    client: TestClient, auth_headers: dict[str, str]
) -> None:
    response = post(client, auth_headers, {"text": REVIEWS})

    assert response.status_code == 200
    body = response.json()
    assert body["method"] == "lsa"
    summary = body["summary"]
    assert isinstance(summary, str) and 0 < len(summary) <= REVIEW_MAX_CHARS
    assert 1 <= len(split_sentences(summary)) <= 3
    for sentence in split_sentences(summary):
        assert sentence in REVIEWS


def test_sentences_default_and_bounds(client: TestClient, auth_headers: dict[str, str]) -> None:
    one = post(client, auth_headers, {"text": REVIEWS, "sentences": 1}).json()["summary"]
    assert len(split_sentences(one)) == 1
    assert post(client, auth_headers, {"text": REVIEWS, "sentences": 5}).status_code == 200
    for bad in (0, 6, -1, "3", 2.5):
        assert post(client, auth_headers, {"text": REVIEWS, "sentences": bad}).status_code == 422


def test_text_length_bounds(client: TestClient, auth_headers: dict[str, str]) -> None:
    assert post(client, auth_headers, {"text": ""}).status_code == 422
    assert post(client, auth_headers, {"text": 20001 * "a"}).status_code == 422
    assert (
        post(client, auth_headers, {"text": ("Mara opens the door. " * 1000)[:20000]}).status_code
        == 200
    )
    assert post(client, auth_headers, {}).status_code == 422


def test_unsummarizable_text_gives_null(client: TestClient, auth_headers: dict[str, str]) -> None:
    for text in (
        "Great.",
        "- one\n- two\n- three",
        "A single sentence that is the whole text and cannot be shortened.",
    ):
        response = post(client, auth_headers, {"text": text})
        assert response.status_code == 200
        assert response.json() == {"summary": None, "method": "lsa"}


def test_description_summaries_keep_their_limit() -> None:
    result = LsaSummarizer().summarize(STORY)
    assert result is not None and len(result.summary) <= MAX_CHARS
