import sys
from typing import Any

import numpy as np
import pytest

from bookflix_ml import embeddings
from bookflix_ml.embeddings import EmbedderUnavailable, FakeEmbedder, embed, passage_text


def test_query_and_passage_prefixes_are_applied() -> None:
    fake = FakeEmbedder()

    embed(fake, ["x"], "query")
    embed(fake, ["y"], "passage")

    assert fake.calls == [["query: x"], ["passage: y"]]


def test_vectors_are_384_dimensional_and_unit_length() -> None:
    vectors = embed(FakeEmbedder(), ["one", "two", "three"], "passage")

    assert vectors.shape == (3, 384)
    assert vectors.dtype == np.float32
    assert np.allclose(np.linalg.norm(vectors, axis=1), 1.0, atol=1e-5)


def test_an_encoder_that_does_not_normalize_is_normalized_here() -> None:
    vectors = embed(FakeEmbedder(scale=7.5), ["one", "two"], "query")

    assert np.allclose(np.linalg.norm(vectors, axis=1), 1.0, atol=1e-5)


def test_empty_list_returns_empty() -> None:
    fake = FakeEmbedder()

    vectors = embed(fake, [], "query")

    assert vectors.shape == (0, 384)
    assert fake.calls == []


def test_batches_larger_than_64_are_chunked_and_order_preserved() -> None:
    fake = FakeEmbedder()
    texts = [f"text {i}" for i in range(150)]

    vectors = embed(fake, texts, "passage")

    assert [len(call) for call in fake.calls] == [64, 64, 22]
    assert [t for call in fake.calls for t in call] == [f"passage: {t}" for t in texts]
    for i in (0, 63, 64, 149):
        assert np.allclose(vectors[i], embed(FakeEmbedder(), [texts[i]], "passage")[0])


def test_the_fake_is_deterministic_and_tells_texts_apart() -> None:
    a = embed(FakeEmbedder(), ["same", "other"], "query")
    b = embed(FakeEmbedder(), ["same", "other"], "query")

    assert np.array_equal(a, b)
    assert not np.allclose(a[0], a[1])


def test_model_version_is_name_at_revision() -> None:
    fake = FakeEmbedder(name="intfloat/multilingual-e5-small", revision="abc123")

    assert embeddings.model_version(fake) == "intfloat/multilingual-e5-small@abc123"


def test_nothing_loaded_is_unavailable(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(embeddings, "_loaded", None)

    with pytest.raises(EmbedderUnavailable):
        embeddings.current()


def test_loading_without_the_embed_extra_leaves_it_unavailable(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(embeddings, "_loaded", None)
    # A None entry makes `import sentence_transformers` raise ImportError, as it does without the extra.
    monkeypatch.setitem(sys.modules, "sentence_transformers", None)

    assert embeddings.load("intfloat/multilingual-e5-small", "abc123", 384) is False
    with pytest.raises(EmbedderUnavailable):
        embeddings.current()


def test_passage_text_joins_title_description_and_names() -> None:
    doc: dict[str, Any] = {
        "title": "Starfall Armada",
        "alternative_titles": ["Ignored"],
        "description": "A starship captain leads the fleet.",
        "genres": ["scifi", "war"],
        "themes": ["politics"],
        "tags": [],
        "characters": ["Captain Vale"],
    }

    assert passage_text(doc) == (
        "Starfall Armada\nA starship captain leads the fleet.\nscifi, war\npolitics\nCaptain Vale"
    )


def test_passage_text_skips_a_missing_description() -> None:
    doc: dict[str, Any] = {
        "title": "Only a title",
        "description": None,
        "genres": [],
        "themes": [],
        "tags": [],
        "characters": [],
    }

    assert passage_text(doc) == "Only a title"


@pytest.mark.slow
def test_multilingual_pairs_are_closer_than_unrelated() -> None:
    """The real model (needs the `embed` extra and the downloaded weights): run with `-m slow`."""
    pytest.importorskip("sentence_transformers")
    from app.settings import get_settings

    settings = get_settings()
    real = embeddings.SentenceTransformerEmbedder(
        settings.embedding_model, settings.embedding_revision, settings.embedding_dims
    )
    english, japanese, cooking = embed(
        real, ["dark fantasy manga", "ダークファンタジー漫画", "cooking recipes"], "query"
    )

    assert float(english @ japanese) > float(english @ cooking)
