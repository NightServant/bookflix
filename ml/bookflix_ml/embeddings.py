"""Sentence embeddings (multilingual e5): `query: ` / `passage: ` prefixes, L2-normalized, 384 dimensions.

The real model needs the optional `embed` extra (sentence-transformers and CPU torch), which only the Docker image installs. It is loaded once
when the service starts (`load`), never in a request, and only from the local cache: the image downloads it at build time
(`python -m bookflix_ml.embeddings`). Without the extra or the weights nothing is loaded, `/v1/embed` answers 503 and `embed-media` fails.
This module does not read the settings, so `app.settings` can take its defaults from here.
"""

import hashlib
import logging
import os
from typing import Any, Literal, Protocol

import numpy as np

MODEL_NAME = "intfloat/multilingual-e5-small"
# Pinned so the image, the stored versions and `/v1/models` all name the same weights.
MODEL_REVISION = "614241f622f53c4eeff9890bdc4f31cfecc418b3"
DIMS = 384
BATCH = 64

Kind = Literal["query", "passage"]

logger = logging.getLogger(__name__)


class EmbedderUnavailable(RuntimeError):
    """No model is loaded: the `embed` extra or the downloaded weights are missing."""


class Embedder(Protocol):
    name: str
    revision: str
    dims: int

    def encode(self, texts: list[str]) -> np.ndarray:
        """One batch of already-prefixed texts to a (len(texts), dims) array."""
        ...


class SentenceTransformerEmbedder:
    """The real model on CPU, read from the local Hugging Face cache only (`HF_HOME`)."""

    def __init__(self, name: str, revision: str, dims: int) -> None:
        from sentence_transformers import SentenceTransformer  # the optional `embed` extra

        self.name, self.revision, self.dims = name, revision, dims
        self._model = SentenceTransformer(
            name, revision=revision, device="cpu", local_files_only=True
        )
        actual = self._model.get_embedding_dimension()
        if actual != dims:
            raise ValueError(f"{name} makes {actual}-dimensional vectors, not {dims}.")

    def encode(self, texts: list[str]) -> np.ndarray:
        return np.asarray(
            self._model.encode(texts, batch_size=BATCH, convert_to_numpy=True),
            dtype=np.float32,
        )


class FakeEmbedder:
    """A deterministic stand-in for tests: each text gets a fixed pseudo-random vector seeded by its hash, and every call is recorded.

    `scale` multiplies the output (to check normalization happens here); after `fail_after` successful calls `encode` raises.
    """

    def __init__(
        self,
        name: str = MODEL_NAME,
        revision: str = MODEL_REVISION,
        dims: int = DIMS,
        scale: float = 1.0,
        fail_after: int | None = None,
    ) -> None:
        self.name, self.revision, self.dims = name, revision, dims
        self.scale = scale
        self.fail_after = fail_after
        self.calls: list[list[str]] = []

    def encode(self, texts: list[str]) -> np.ndarray:
        if self.fail_after is not None and len(self.calls) >= self.fail_after:
            raise RuntimeError("The fake encoder failed.")
        self.calls.append(list(texts))

        rows = [
            np.random.default_rng(
                int.from_bytes(hashlib.sha256(f"{self.revision}\0{t}".encode()).digest()[:8])
            ).standard_normal(self.dims)
            for t in texts
        ]

        return np.asarray(rows, dtype=np.float32).reshape(len(texts), self.dims) * self.scale


def embed(embedder: Embedder, texts: list[str], kind: Kind) -> np.ndarray:
    """Prefixed, L2-normalized vectors in the order of `texts`, encoded in batches of `BATCH`; an empty list gives an empty array."""
    if not texts:
        return np.empty((0, embedder.dims), dtype=np.float32)

    prefixed = [f"{kind}: {text}" for text in texts]
    vectors = np.vstack(
        [embedder.encode(prefixed[i : i + BATCH]) for i in range(0, len(prefixed), BATCH)]
    ).astype(np.float32)
    norms = np.linalg.norm(vectors, axis=1, keepdims=True)

    return vectors / np.where(norms == 0, 1.0, norms)


def model_version(embedder: Embedder) -> str:
    """What `/v1/embed` reports, so a caller can tell vectors of two models apart."""
    return f"{embedder.name}@{embedder.revision}"


def passage_text(doc: dict[str, Any]) -> str:
    """The text a media is embedded from (without the prefix): title, description, then its genre, theme, tag and character names."""
    parts = [
        doc["title"],
        doc.get("description") or "",
        *(", ".join(doc[key]) for key in ("genres", "themes", "tags", "characters")),
    ]

    return "\n".join(part.strip() for part in parts if part and part.strip())


_loaded: Embedder | None = None


def load(name: str, revision: str, dims: int) -> bool:
    """Load the real model for this process; False (and a warning) when the extra or the weights are missing."""
    global _loaded
    try:
        _loaded = SentenceTransformerEmbedder(name, revision, dims)
    except Exception as e:  # noqa: BLE001 - a missing extra or model must not stop the service
        logger.warning("Embedding model %s@%s not loaded: %s", name, revision, e)
        _loaded = None

    return _loaded is not None


def current() -> Embedder:
    if _loaded is None:
        raise EmbedderUnavailable("The embedding model is not loaded.")

    return _loaded


def download(name: str, revision: str) -> None:
    """Fetch the weights into the Hugging Face cache (the Docker build runs this; nothing else downloads)."""
    from sentence_transformers import SentenceTransformer

    SentenceTransformer(name, revision=revision, device="cpu")


if __name__ == "__main__":
    download(
        os.environ.get("EMBEDDING_MODEL", MODEL_NAME),
        os.environ.get("EMBEDDING_REVISION", MODEL_REVISION),
    )
