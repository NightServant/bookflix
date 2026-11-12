import math
from datetime import timedelta
from pathlib import Path

import pytest

from bookflix_ml.evaluate import metrics_at_k
from bookflix_ml.jobs import run_build_similarities, run_evaluate, run_train_item_cf
from tests.db_helpers import Conn, now, seed_interaction, seed_media, seed_users

RELEVANT = {1: {9}}


def genre_diversity(lists: dict[int, list[int]], genres: dict[int, set[int]] | None) -> float:
    return metrics_at_k(lists, RELEVANT, 10, item_genres=genres)["genre_diversity"]


def test_identical_genre_sets_give_zero_and_disjoint_ones_give_one() -> None:
    assert genre_diversity({1: [1, 2, 3]}, {1: {5, 6}, 2: {5, 6}, 3: {5, 6}}) == 0.0
    assert genre_diversity({1: [1, 2, 3]}, {1: {1}, 2: {2}, 3: {3}}) == 1.0


def test_partial_overlap_is_the_jaccard_distance() -> None:
    assert genre_diversity({1: [1, 2]}, {1: {1, 2}, 2: {2, 3}}) == pytest.approx(1 - 1 / 3)


def test_titles_without_genres_are_skipped_not_counted_as_distance() -> None:
    assert genre_diversity({1: [1, 2, 3]}, {1: {1}, 2: {1}}) == 0.0
    assert genre_diversity({1: [1, 2]}, {1: {1}}) == 0.0
    assert genre_diversity({1: [1, 2]}, None) == 0.0


def test_a_list_with_no_valid_pair_does_not_drag_the_mean() -> None:
    lists = {1: [1, 2], 2: [3]}

    assert genre_diversity(lists, {1: {1}, 2: {2}, 3: {1}}) == 1.0


def test_the_existing_diversity_and_novelty_keys_are_unchanged() -> None:
    lists = {1: [1, 2]}
    pop = {1: 1, 2: 4}
    base = metrics_at_k(lists, RELEVANT, 10, item_pop=pop, item_sim=lambda a, b: 0.25, n_users=8)
    both = metrics_at_k(
        lists,
        RELEVANT,
        10,
        item_pop=pop,
        item_sim=lambda a, b: 0.25,
        n_users=8,
        item_genres={1: {1}},
    )

    assert base["diversity"] == 0.75 and base["novelty"] == pytest.approx((3 + 1) / 2)
    assert {k: v for k, v in both.items() if k != "genre_diversity"} == {
        k: v for k, v in base.items() if k != "genre_diversity"
    }
    assert base["genre_diversity"] == 0.0


def test_novelty_is_higher_for_long_tail_recommendations() -> None:
    pop = {1: 90, 2: 80, 3: 2, 4: 1}
    head = metrics_at_k({1: [1, 2]}, RELEVANT, 10, item_pop=pop, n_users=100)["novelty"]
    tail = metrics_at_k({1: [3, 4]}, RELEVANT, 10, item_pop=pop, n_users=100)["novelty"]

    assert tail > head and math.isfinite(tail)


@pytest.mark.db
def test_the_evaluation_stores_genre_diversity_in_the_candidates_metrics(
    db: Conn, artifact_dir: Path
) -> None:
    seed_media(db, 1, 2, 3, 4)
    for genre, media in ((1, 1), (1, 2), (2, 3), (2, 4)):
        db.execute(
            "insert into genres (id, name) values (%s, %s) on conflict do nothing",
            (genre, f"g{genre}"),
        )
        db.execute("insert into media_genres (media_id, genre_id) values (%s, %s)", (media, genre))
    base = now() - timedelta(days=10)
    for user, order in ((1, (1, 2, 3)), (2, (1, 2, 3)), (3, (1, 3, 2)), (4, (1, 3, 2))):
        seed_users(db, active=user)
        for n, media in enumerate(order):
            seed_interaction(db, user, media, "library_add", None, base + timedelta(days=n))
    version = run_train_item_cf(db, {})["version_id"]
    run_build_similarities(db, {"methods": ["item_cf"], "version_id": version})

    result = run_evaluate(db, {"kind": "item_cf", "version_id": version})

    stored = db.execute("select metrics from model_versions where id = %s", (version,)).fetchone()
    assert stored is not None
    assert "genre_diversity" in result and "genre_diversity" in stored[0]
    assert {"diversity", "novelty"} <= stored[0].keys()
