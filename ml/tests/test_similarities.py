import numpy as np

from bookflix_ml.similarities import top_k_cosine


def unit(m: np.ndarray) -> np.ndarray:
    norms = np.linalg.norm(m, axis=1, keepdims=True)
    return np.asarray(m / np.where(norms == 0, 1, norms))


def test_no_self_neighbors_and_descending_order() -> None:
    ids = [1, 2, 3, 4]
    v = unit(np.array([[1, 0], [0.9, 0.1], [0, 1], [0.1, 0.9]]))
    nb = top_k_cosine(ids, v, k=3)
    assert all(i not in [n for n, _ in nb[i]] for i in ids)
    assert [n for n, _ in nb[1]][0] == 2
    scores = [s for _, s in nb[1]]
    assert scores == sorted(scores, reverse=True)


def test_k_limits_neighbors_and_blocks_give_the_same_result() -> None:
    ids = list(range(200))
    v = unit(np.random.default_rng(0).normal(size=(200, 8)))
    small = top_k_cosine(ids, v, k=50, block=64)
    assert all(len(x) == 50 for x in small.values())
    assert small == top_k_cosine(ids, v, 50, block=1000)


def test_zero_vectors_have_no_neighbors_and_are_nobodys_neighbor() -> None:
    nb = top_k_cosine([1, 2, 3], np.array([[1.0, 0], [0, 0], [0.5, 0.5]]), k=5)
    assert nb[2] == [] and 2 not in [n for n, _ in nb[1]]


def test_fewer_items_than_k_and_a_single_item() -> None:
    assert top_k_cosine([7], np.array([[1.0, 0]]), k=5) == {7: []}
    assert top_k_cosine([], np.zeros((0, 2)), k=5) == {}
    nb = top_k_cosine([1, 2], np.array([[1.0, 0], [1.0, 0]]), k=5)
    assert nb[1] == [(2, 1.0)] or nb[1][0][0] == 2
