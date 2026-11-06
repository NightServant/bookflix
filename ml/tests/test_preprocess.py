from bookflix_ml.preprocess import build_document, normalize, tokenize


def test_normalize_strips_html_and_folds_width() -> None:
    assert normalize("<p>Ｈｅｌｌｏ  World</p>") == "hello world"


def test_normalize_decodes_entities_and_tolerates_none() -> None:
    assert normalize("Tom &amp; Jerry<br/>rule") == "tom & jerry rule"
    assert normalize(None) == ""


def test_tokenize_removes_stop_words() -> None:
    assert tokenize("The fall of the empire") == ["fall", "empire"]


def test_cjk_runs_become_character_ngrams() -> None:
    toks = tokenize("進撃の巨人")
    assert {"進撃", "撃の", "の巨", "巨人", "進撃の"} <= set(toks)


def test_a_single_cjk_character_is_kept_and_latin_around_it_is_split() -> None:
    assert tokenize("dune 道 saga") == ["dune", "道", "saga"]


def test_document_repeats_title_and_genres() -> None:
    doc = build_document(
        {
            "title": "Dune",
            "alternative_titles": [],
            "description": "desert",
            "genres": ["scifi"],
            "themes": [],
            "tags": [],
            "characters": [],
        }
    )
    assert doc.split().count("dune") == 3
    assert doc.split().count("scifi") == 2


def test_document_tolerates_missing_fields_and_odd_alternative_titles() -> None:
    doc = build_document({"title": None, "alternative_titles": ["Dune", {"title": "Duna"}, 3]})
    assert "dune" in doc and "duna" in doc
    assert build_document({}) == ""
