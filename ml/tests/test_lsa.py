from typing import Any

import numpy as np
import pytest

from bookflix_ml.lsa import NotEnoughData, train_lsa, transform


def test_space_opera_synopses_are_closer_to_each_other_than_to_a_cookbook(
    corpus: list[dict[str, Any]],
) -> None:
    r = train_lsa(corpus)
    sim = r.vectors @ r.vectors.T
    a, b, c = (r.media_ids.index(i) for i in (1, 2, 3))
    assert sim[a, b] > sim[a, c]
    assert sim[a, b] > sim[b, c]


def test_vectors_have_200_dimensions_and_unit_norm(corpus: list[dict[str, Any]]) -> None:
    r = train_lsa(corpus)
    assert r.vectors.shape == (len(corpus), 200)
    norms = np.linalg.norm(r.vectors, axis=1)
    assert np.all(np.isclose(norms, 1.0) | np.isclose(norms, 0.0))


def test_training_is_deterministic(corpus: list[dict[str, Any]]) -> None:
    assert np.allclose(train_lsa(corpus).vectors, train_lsa(corpus).vectors)


def test_empty_documents_do_not_produce_nan(corpus: list[dict[str, Any]]) -> None:
    corpus[0].update(
        title="",
        alternative_titles=[],
        description="",
        genres=[],
        themes=[],
        tags=[],
        characters=[],
    )
    r = train_lsa(corpus)
    assert not np.isnan(r.vectors).any()
    assert np.allclose(r.vectors[r.media_ids.index(corpus[0]["id"])], 0.0)


def test_top_terms_and_stats_are_reported(corpus: list[dict[str, Any]]) -> None:
    r = train_lsa(corpus)
    assert len(r.top_terms[1]) <= 10 and r.top_terms[1]
    assert r.vocab_size > 0
    assert 0.0 < r.explained_variance <= 1.0


def test_a_tiny_corpus_trains_with_relaxed_document_frequencies() -> None:
    docs = [
        {"id": 1, "title": "red fox", "description": "a fox runs"},
        {"id": 2, "title": "red fox", "description": "a fox sleeps"},
    ]
    r = train_lsa(docs)
    assert r.vectors.shape == (2, 200)
    assert not np.isnan(r.vectors).any()


def test_too_little_data_raises_not_enough_data() -> None:
    with pytest.raises(NotEnoughData):
        train_lsa([{"id": 1, "title": "only one", "description": "x"}])


def test_a_corpus_without_any_words_raises_not_enough_data() -> None:
    with pytest.raises(NotEnoughData):
        train_lsa([{"id": 1, "title": ""}, {"id": 2, "title": ""}, {"id": 3, "title": "the"}])


def test_transform_makes_the_vectors_training_stored_for_the_same_media(
    corpus: list[dict[str, Any]],
) -> None:
    result = train_lsa(corpus, components=20)
    artifact = {"vectorizer": result.vectorizer, "svd": result.svd}

    vectors = transform(artifact, corpus, 20)

    assert vectors.shape == (len(corpus), 20)
    assert np.allclose(vectors, result.vectors, atol=1e-5)
