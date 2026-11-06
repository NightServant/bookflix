"""Turns a media row into the text the vectorizer sees."""

import html
import re
import unicodedata
from typing import Any

from sklearn.feature_extraction.text import ENGLISH_STOP_WORDS

# Hiragana, katakana, CJK ideographs (and extension A) and Hangul: scripts written without spaces.
_CJK = "぀-ヿ㐀-䶿一-鿿가-힯"
_RUN = re.compile(f"([{_CJK}]+)|([^\\W_]+)")
_TAGS = re.compile(r"<[^>]*>")
_SPACES = re.compile(r"\s+")


def normalize(text: str | None) -> str:
    """NFKC, no HTML, lowercase, single spaces."""
    if not text:
        return ""

    text = unicodedata.normalize("NFKC", text)
    text = html.unescape(_TAGS.sub(" ", text))

    return _SPACES.sub(" ", text.lower()).strip()


def tokenize(text: str) -> list[str]:
    """Words for Latin text with English stop words removed; character 2- and 3-grams for CJK runs, which have no spaces to split on."""
    tokens: list[str] = []

    for match in _RUN.finditer(normalize(text)):
        cjk, word = match.groups()

        if word is not None:
            if word not in ENGLISH_STOP_WORDS:
                tokens.append(word)
        elif len(cjk) == 1:
            tokens.append(cjk)
        else:
            for size in (2, 3):
                tokens.extend(cjk[i : i + size] for i in range(len(cjk) - size + 1))

    return tokens


def _names(value: Any) -> list[str]:
    """Strings from a list that may hold plain names or `{title|name: ...}` objects; anything else is skipped."""
    if not isinstance(value, list):
        return []

    names: list[str] = []
    for item in value:
        if isinstance(item, dict):
            item = item.get("title") or item.get("name")
        if isinstance(item, str) and item.strip():
            names.append(item)

    return names


def build_document(row: dict[str, Any]) -> str:
    """Title ×3, alternative titles, description, genres ×2, themes ×2, tags and characters, as one normalized string."""
    title = normalize(row.get("title"))
    genres = " ".join(_names(row.get("genres")))
    themes = " ".join(_names(row.get("themes")))

    parts = [
        " ".join([title] * 3) if title else "",
        " ".join(_names(row.get("alternative_titles"))),
        row.get("description") or "",
        " ".join([genres] * 2) if genres else "",
        " ".join([themes] * 2) if themes else "",
        " ".join(_names(row.get("tags"))),
        " ".join(_names(row.get("characters"))),
    ]

    return normalize(" ".join(p for p in parts if p))
