import hashlib
from typing import Any

import pytest

from bookflix_ml import jobs
from bookflix_ml.jobs import JOBS, run_summarize_media
from bookflix_ml.summarize import MAX_CHARS, LsaSummarizer, Summary
from tests.db_helpers import Conn, count

pytestmark = pytest.mark.db


def long_description(subject: str) -> str:
    """Seven sentences about one subject, well over 600 characters."""
    return " ".join(
        f"The {subject} crosses the {place} where the old {subject} legends were first written down by travelling scribes."
        for place in ("desert", "mountains", "river", "forest", "harbour", "valley", "ruins")
    )


def seed(db: Conn, media_id: int, description: str | None) -> None:
    db.execute(
        "insert into media (id, title, description) values (%s, %s, %s)",
        (media_id, f"Media {media_id}", description),
    )
    db.commit()


def summaries(db: Conn) -> dict[int, tuple[str, str, str]]:
    return {
        int(r[0]): (r[1], r[2], r[3])
        for r in db.execute(
            "select media_id, summary, source_hash, method from media_summaries"
        ).fetchall()
    }


def sha(text: str) -> str:
    return hashlib.sha256(text.encode()).hexdigest()


def test_live_media_with_a_long_description_get_a_summary(db: Conn) -> None:
    long = long_description("caravan")
    seed(db, 1, long)
    seed(db, 2, "A short blurb. It stays as it is.")
    seed(db, 3, long_description("pilgrim"))
    seed(db, 4, long_description("smuggler"))
    seed(db, 5, None)
    seed(db, 6, "x" * 600)
    db.execute("update media set deleted_at = now() where id = 3")
    db.execute("update media set merged_into_id = 1 where id = 4")
    db.commit()

    metrics = run_summarize_media(db, {})

    rows = summaries(db)
    assert set(rows) == {1}
    summary, source_hash, method = rows[1]
    assert source_hash == sha(long) and method == "lsa"
    assert summary == LsaSummarizer().summarize(long).summary  # type: ignore[union-attr]
    assert len(summary) <= MAX_CHARS
    assert metrics == {
        "eligible": 1,
        "summarized": 1,
        "unchanged": 0,
        "no_summary": 0,
        "failed": 0,
        "removed": 0,
    }
    assert JOBS["summarize-media"] is not None


def test_a_second_run_with_nothing_changed_writes_nothing(db: Conn) -> None:
    seed(db, 1, long_description("caravan"))
    run_summarize_media(db, {})
    before = db.execute("select generated_at from media_summaries").fetchall()

    metrics = run_summarize_media(db, {})

    assert metrics["summarized"] == 0 and metrics["unchanged"] == 1
    assert db.execute("select generated_at from media_summaries").fetchall() == before


def test_a_changed_description_is_summarized_again(db: Conn) -> None:
    seed(db, 1, long_description("caravan"))
    run_summarize_media(db, {})
    changed = long_description("lighthouse keeper")
    db.execute("update media set description = %s where id = 1", (changed,))
    db.commit()

    run_summarize_media(db, {})

    summary, source_hash, _ = summaries(db)[1]
    assert source_hash == sha(changed) and "lighthouse keeper" in summary
    assert count(db, "media_summaries") == 1


def test_rows_go_when_the_media_is_no_longer_live_or_its_description_is_short_or_unsummarizable(
    db: Conn,
) -> None:
    for media_id, subject in enumerate(("caravan", "pilgrim", "smuggler", "miner", "falconer"), 1):
        seed(db, media_id, long_description(subject))
    run_summarize_media(db, {})
    assert set(summaries(db)) == {1, 2, 3, 4, 5}
    db.execute("update media set deleted_at = now() where id = 1")
    db.execute("update media set merged_into_id = 5 where id = 2")
    db.execute("update media set description = 'Now a short blurb.' where id = 3")
    one_sentence = "A miner " + " and then ".join(f"digs past seam {n}" for n in range(80)) + "."
    db.execute("update media set description = %s where id = 4", (one_sentence,))
    db.commit()

    metrics = run_summarize_media(db, {})

    assert set(summaries(db)) == {5}
    assert metrics["removed"] == 4 and metrics["no_summary"] == 1


def test_one_bad_description_is_counted_and_skipped_without_failing_the_run(
    db: Conn, monkeypatch: pytest.MonkeyPatch
) -> None:
    for media_id in (1, 2, 3):
        seed(db, media_id, long_description(f"traveller {media_id}"))
    real = LsaSummarizer.summarize

    def flaky(self: LsaSummarizer, text: str, max_sentences: int = 3) -> Summary | None:
        if "traveller 2" in text:
            raise ValueError("bad text")
        return real(self, text, max_sentences)

    monkeypatch.setattr(LsaSummarizer, "summarize", flaky)

    metrics = run_summarize_media(db, {})

    assert set(summaries(db)) == {1, 3}
    assert metrics["failed"] == 1 and metrics["summarized"] == 2


class Interrupted(BaseException):
    pass


def test_work_is_committed_in_chunks_so_an_interrupted_run_resumes(
    db: Conn, monkeypatch: pytest.MonkeyPatch
) -> None:
    for media_id in range(1, 6):
        seed(db, media_id, long_description(f"traveller {media_id}"))
    monkeypatch.setattr(jobs, "SUMMARY_CHUNK", 2)
    real = LsaSummarizer.summarize
    calls: list[str] = []

    def interrupted(self: LsaSummarizer, text: str, max_sentences: int = 3) -> Summary | None:
        calls.append(text)
        if "traveller 3" in text:
            raise Interrupted
        return real(self, text, max_sentences)

    monkeypatch.setattr(LsaSummarizer, "summarize", interrupted)
    with pytest.raises(Interrupted):
        run_summarize_media(db, {})
    assert set(summaries(db)) == {1, 2}

    monkeypatch.setattr(LsaSummarizer, "summarize", real)
    metrics = run_summarize_media(db, {})

    assert set(summaries(db)) == {1, 2, 3, 4, 5}
    assert metrics["summarized"] == 3 and metrics["unchanged"] == 2


def test_nothing_to_summarize_succeeds(db: Conn) -> None:
    metrics: dict[str, Any] = run_summarize_media(db, {})

    assert metrics["eligible"] == 0 and count(db, "media_summaries") == 0
