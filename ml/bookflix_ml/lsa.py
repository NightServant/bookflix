"""TF-IDF and truncated SVD (LSA) over the media corpus."""

from dataclasses import dataclass
from typing import Any

import numpy as np
from sklearn.decomposition import TruncatedSVD
from sklearn.feature_extraction.text import TfidfVectorizer

from bookflix_ml.preprocess import build_document, tokenize


class NotEnoughData(Exception):
    """The corpus is too small or too empty to fit a model."""


@dataclass
class LsaResult:
    media_ids: list[int]
    vectors: np.ndarray[Any, Any]
    top_terms: dict[int, list[str]]
    vocab_size: int
    explained_variance: float
    vectorizer: TfidfVectorizer
    svd: TruncatedSVD


def _vectorizer(min_df: int | float, max_df: float) -> TfidfVectorizer:
    return TfidfVectorizer(
        tokenizer=tokenize,
        lowercase=False,
        token_pattern=None,
        sublinear_tf=True,
        min_df=min_df,
        max_df=max_df,
        ngram_range=(1, 2),
    )


def train_lsa(corpus: list[dict[str, Any]], components: int = 200) -> LsaResult:
    """Fit TF-IDF and LSA and return L2-normalized document vectors, `components` wide.

    Fewer dimensions fit when the corpus is small (`k = min(components, features - 1, docs - 1)`); the rest are zero so every vector has
    the width the table expects. Documents without any known term keep a zero vector rather than NaN.
    """
    if len(corpus) < 2:
        raise NotEnoughData("At least two media are needed to train.")

    documents = [build_document(row) for row in corpus]

    try:
        vectorizer = _vectorizer(2, 0.6)
        matrix = vectorizer.fit_transform(documents)
    except ValueError:
        # A tiny corpus can have no term in 2+ documents but under 60% of them: keep every term instead.
        try:
            vectorizer = _vectorizer(1, 1.0)
            matrix = vectorizer.fit_transform(documents)
        except ValueError as e:
            raise NotEnoughData("The media have no usable text.") from e

    k = min(components, matrix.shape[1] - 1, len(corpus) - 1)
    if k < 1:
        raise NotEnoughData("The media do not share enough vocabulary to train.")

    svd = TruncatedSVD(n_components=k, random_state=42)
    reduced = np.nan_to_num(svd.fit_transform(matrix))

    norms = np.linalg.norm(reduced, axis=1, keepdims=True)
    reduced = np.divide(reduced, norms, out=np.zeros_like(reduced), where=norms > 0)

    vectors = np.zeros((len(corpus), components), dtype=np.float32)
    vectors[:, :k] = reduced

    ids = [int(row["id"]) for row in corpus]
    terms = np.asarray(vectorizer.get_feature_names_out())
    top_terms: dict[int, list[str]] = {}
    for index, media_id in enumerate(ids):
        row = matrix.getrow(index)
        best = row.indices[np.argsort(-row.data, kind="stable")[:10]]
        top_terms[media_id] = [str(t) for t in terms[best]]

    return LsaResult(
        media_ids=ids,
        vectors=vectors,
        top_terms=top_terms,
        vocab_size=int(matrix.shape[1]),
        explained_variance=float(np.nan_to_num(svd.explained_variance_ratio_.sum())),
        vectorizer=vectorizer,
        svd=svd,
    )
