"""Top-k neighbors per item, and the one-transaction writer for `media_similarities`."""

import numpy as np

from bookflix_ml.db import Conn

Neighbors = dict[int, list[tuple[int, float]]]
WRITE_CHUNK = 5000
# What one block of similarities may take: its float32 scores plus the int64 index array `argpartition` returns, per candidate column.
BLOCK_BYTES = 64 * 1024 * 1024
_BYTES_PER_CELL = 4 + 8


def block_rows(n: int, max_bytes: int = BLOCK_BYTES) -> int:
    """How many rows of the n x n similarity matrix fit in `max_bytes` (at least one)."""
    return max(1, max_bytes // (_BYTES_PER_CELL * max(n, 1)))


def top_k_cosine(
    ids: list[int], vectors: np.ndarray, k: int = 50, block: int | None = None
) -> Neighbors:
    """Up to `k` neighbors per id by cosine, best first, in blocks so the full matrix never exists.

    A block is `block` rows, by default as many as keep one block near `BLOCK_BYTES` however many items there are.

    The item itself is never a neighbor; a zero vector has no neighbors and is nobody's neighbor (its cosine is undefined).
    """
    matrix = np.asarray(vectors, dtype=np.float32)
    norms = np.linalg.norm(matrix, axis=1)
    usable = norms > 0
    unit = np.zeros_like(matrix)
    unit[usable] = matrix[usable] / norms[usable, None]

    result: Neighbors = {media_id: [] for media_id in ids}
    if len(ids) < 2 or k < 1:
        return result

    id_array = np.asarray(ids)
    block = block or block_rows(len(ids))
    for start in range(0, len(ids), block):
        rows = range(start, min(start + block, len(ids)))
        sims = unit[start : start + block] @ unit.T
        sims[:, ~usable] = -np.inf
        for offset, row in enumerate(rows):
            sims[offset, row] = -np.inf
        if not usable.any():
            continue

        take = min(k, len(ids) - 1)
        top = np.argpartition(sims, len(ids) - take, axis=1)[:, len(ids) - take :]
        for offset, row in enumerate(rows):
            if not usable[row]:
                continue
            cols = top[offset]
            scores = sims[offset, cols]
            # Ties break on id so blocks and runs give the same answer.
            order = sorted(
                (n for n in range(len(cols)) if np.isfinite(scores[n])),
                key=lambda n: (-float(scores[n]), int(id_array[cols[n]])),
            )
            result[ids[row]] = [(int(id_array[cols[n]]), float(scores[n])) for n in order]

    return result


def write(conn: Conn, method: str, model_version_id: int, neighbors: Neighbors) -> int:
    """Replace the rows of one `(method, version)` in the caller's transaction; returns how many were written.

    The version is part of the primary key, so a candidate version stores its pairs next to the active version's and never touches them;
    only the promotion switches which rows readers see. It never commits: a failure anywhere leaves the old rows as they were once the
    caller rolls back.
    """
    rows = [
        (media_id, similar, method, score, model_version_id)
        for media_id, pairs in neighbors.items()
        for similar, score in pairs
        if similar != media_id
    ]

    with conn.cursor() as cur:
        cur.execute(
            "delete from media_similarities where method = %s and model_version_id = %s",
            (method, model_version_id),
        )
        for start in range(0, len(rows), WRITE_CHUNK):
            cur.executemany(
                """
                insert into media_similarities (media_id, similar_media_id, method, score, model_version_id)
                values (%s, %s, %s, %s, %s)
                on conflict (media_id, method, model_version_id, similar_media_id)
                do update set score = excluded.score
                """,
                rows[start : start + WRITE_CHUNK],
            )

    return len(rows)
