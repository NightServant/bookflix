import re

from bookflix_ml.summarize import (
    MAX_CHARS,
    MAX_SENTENCES,
    LsaSummarizer,
    Summarizer,
    split_sentences,
)

STORY = (
    "In a city ruled by guilds, a young locksmith named Mara finds a key that opens any door. "
    "The weather that spring was mild and the markets were busy. "
    "The thieves guild wants the key, and the locksmith guild wants Mara silenced before the guild war begins. "
    "Her brother sells bread near the harbour. "
    "Mara must choose which guild to trust with the key before the guild war burns the city. "
    "A cat sleeps on the bakery roof most afternoons. "
    "Old songs about the harbour are still sung in the taverns on rainy nights."
)


def in_order_from(summary: str, text: str, indices: tuple[int, ...]) -> bool:
    sentences = split_sentences(text)
    picked = [sentences[i] for i in indices]
    position = 0
    for sentence in picked:
        found = text.find(sentence, position)
        if found < 0:
            return False
        position = found + len(sentence)
    return " ".join(picked) == summary or "".join(picked) == summary


def test_it_is_a_summarizer_named_lsa() -> None:
    summarizer: Summarizer = LsaSummarizer()
    assert summarizer.method == "lsa"


def test_the_output_is_the_same_across_runs_and_instances() -> None:
    first = LsaSummarizer().summarize(STORY)
    assert first is not None
    assert all(LsaSummarizer().summarize(STORY) == first for _ in range(5))


def test_the_summary_is_whole_sentences_of_the_description_in_their_original_order() -> None:
    result = LsaSummarizer().summarize(STORY)

    assert result is not None
    assert list(result.sentence_indices) == sorted(result.sentence_indices)
    assert in_order_from(result.summary, STORY, result.sentence_indices)


def test_it_picks_the_central_sentences() -> None:
    result = LsaSummarizer().summarize(STORY, max_sentences=2)

    assert result is not None
    assert result.sentence_indices == (2, 4)


def test_the_summary_is_shorter_than_the_description_and_within_the_cap() -> None:
    long = " ".join(
        f"Chapter {n} follows the crew of the airship Meridian as they chase a storm across the northern sea."
        for n in range(1, 30)
    )

    result = LsaSummarizer().summarize(long)

    assert result is not None
    assert len(result.summary) <= MAX_CHARS < len(long)
    assert 1 <= len(result.sentence_indices) <= MAX_SENTENCES


def test_html_is_stripped_and_entities_decoded_as_the_import_cleaning_does() -> None:
    text = (
        "<p>The <b>Ashen</b> Court rules seven kingdoms &amp; their colonies.</p>\n"
        "<p>Prince Kael flees the Ashen Court after his father&#39;s murder.</p>\n"
        "<p>He hides among smugglers who carry contraband between the seven kingdoms.</p>"
    )

    result = LsaSummarizer().summarize(text, max_sentences=2)

    assert result is not None
    assert (
        "<" not in result.summary
        and "&amp;" not in result.summary
        and "&#39;" not in result.summary
    )
    assert "Ashen Court" in result.summary
    assert split_sentences(text)[0] == "The Ashen Court rules seven kingdoms & their colonies."


def test_japanese_chinese_and_korean_text_splits_into_sentences() -> None:
    japanese = (
        "少年は古い地図を見つけた。地図は海の向こうの島を示していた！彼は仲間と船を出すことにした？"
    )
    chinese = "少年在旧书店里发现了一张地图。地图指向海那边的岛屿！他决定和朋友们一起出海？"
    korean = "소년은 오래된 지도를 발견했다. 지도는 바다 건너 섬을 가리켰다! 그는 친구들과 배를 띄우기로 했다?"

    assert split_sentences(japanese) == [
        "少年は古い地図を見つけた。",
        "地図は海の向こうの島を示していた！",
        "彼は仲間と船を出すことにした？",
    ]
    assert len(split_sentences(chinese)) == 3
    assert len(split_sentences(korean)) == 3

    result = LsaSummarizer().summarize(japanese * 3, max_sentences=2)
    assert result is not None
    assert in_order_from(result.summary, japanese * 3, result.sentence_indices)
    assert " " not in result.summary


def test_one_long_sentence_gets_no_summary_rather_than_a_truncated_one() -> None:
    sentence = "A knight " + " and then ".join(f"rides past tower {n}" for n in range(60)) + "."

    assert LsaSummarizer().summarize(sentence) is None
    assert LsaSummarizer().summarize(sentence.rstrip(".")) is None


def test_lists_and_tables_with_no_sentences_get_no_summary() -> None:
    listing = "\n".join(f"- Volume {n}: The Lost Archive part {n}" for n in range(1, 40))
    table = "| Volume | Title |\n|---|---|\n" + "\n".join(
        f"| {n} | The Lost Archive part {n}. |" for n in range(1, 40)
    )
    headings = "\n".join(f"## Part {n}\n**Volume {n} of the Lost Archive**" for n in range(1, 30))

    assert LsaSummarizer().summarize(listing) is None
    assert LsaSummarizer().summarize(table) is None
    assert LsaSummarizer().summarize(headings) is None


def test_list_heading_and_aside_lines_are_never_picked() -> None:
    text = (
        "The Lost Archive follows a librarian who catalogues forbidden books.\n"
        "- The librarian catalogues forbidden books in every volume of the archive.\n"
        "## The librarian and the forbidden books of the archive.\n"
        "(Source: The librarian catalogues forbidden books, Yen Press.)\n"
        "[Written by the librarian who catalogues forbidden books.]\n"
        "Each forbidden book the librarian opens rewrites a page of history."
    )

    sentences = split_sentences(text)

    assert sentences == [
        "The Lost Archive follows a librarian who catalogues forbidden books.",
        "Each forbidden book the librarian opens rewrites a page of history.",
    ]


def test_abbreviations_and_initials_do_not_end_a_sentence() -> None:
    text = "Dr. Stone and J. R. Hale open a clinic in St. Albans. Mr. Hale keeps the books."

    assert split_sentences(text) == [
        "Dr. Stone and J. R. Hale open a clinic in St. Albans.",
        "Mr. Hale keeps the books.",
    ]


def test_a_text_the_summary_could_not_shorten_gets_none() -> None:
    assert LsaSummarizer().summarize("A thief steals a crown. The queen hires a hunter.") is None
    assert LsaSummarizer().summarize("") is None
    assert LsaSummarizer().summarize("   \n ") is None


def test_no_sentence_starts_or_ends_with_whitespace() -> None:
    for sentence in split_sentences(STORY + "\n\n" + STORY):
        assert sentence == sentence.strip()
        assert re.search(r"[.!?。！？]['\"”’)\]]*$", sentence)


def test_dotted_abbreviations_and_lowercase_continuations_do_not_end_a_sentence() -> None:
    text = (
        "She moved to the U.S. army base near Dover. "
        "He asked, e.g. a map of the old kingdom, for help. "
        "Ph.D. students joined the cause."
    )

    assert split_sentences(text) == [
        "She moved to the U.S. army base near Dover.",
        "He asked, e.g. a map of the old kingdom, for help.",
        "Ph.D. students joined the cause.",
    ]


def test_a_sentence_never_starts_mid_sentence_after_an_abbreviation() -> None:
    sentences = split_sentences(
        "The envoy served in the U.S. Army for years before the war. "
        "The meeting ran until 5 p.m. The envoy left at once for the coast. "
        "Her mentor, i.e. the old professor, was waiting there."
    )

    assert all(sentence[0].isupper() for sentence in sentences)
    assert "The meeting ran until 5 p.m. The envoy left at once for the coast." in sentences


def test_a_dotted_abbreviation_at_the_end_of_a_line_still_closes_the_sentence() -> None:
    assert split_sentences("The envoy was born in the U.S.\nThe harbour town lies far away.") == [
        "The envoy was born in the U.S.",
        "The harbour town lies far away.",
    ]
