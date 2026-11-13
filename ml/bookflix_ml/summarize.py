"""Extractive summaries: a few whole sentences of a description, chosen by LSA, in their original order.

Deterministic and model-free (TF-IDF and a seeded truncated SVD), so it runs in the batch job without a download. A description that cannot be
summarized well (one long sentence, a list or a table with no sentences, nothing shorter than the text) gets no summary rather than a cut one.
"""

import html
import re
from dataclasses import dataclass
from typing import Protocol

import numpy as np
from sklearn.decomposition import TruncatedSVD
from sklearn.feature_extraction.text import TfidfVectorizer

from bookflix_ml.preprocess import tokenize

# A lead for the story page: two or three sentences, never more than this many characters.
MAX_SENTENCES = 3
MAX_CHARS = 450
# Fewer content words than this is a fragment ("Vol. 3."), not a sentence worth picking.
MIN_TOKENS = 3
TOPICS = 2

_TAGS = re.compile(r"<[^>]*>")
# The end of a sentence: Latin terminal punctuation followed by space or the end of the line, or CJK terminal punctuation, which needs no
# space; closing quotes and brackets stay with the sentence they close.
_END = re.compile(r"[.!?…]+[\"'”’»)\]]*(?=\s|$)|[。！？]+[」』”’）)]*")
# Lines that are structure, not prose: list items, table rows, headings, quotes and rules (markdown or plain).
_STRUCTURE = re.compile(r"^(?:[-*•+|#>=_~]|\d+[.)]\s)")
# A line wholly in parentheses or brackets is an aside, such as "(Source: ...)", never the story.
_ASIDE = re.compile(r"^[(\[].*[)\]][.!?]?$")
# A period after these is an abbreviation or an initial, not the end of a sentence.
_ABBREVIATION = re.compile(r"(?:^|\s)(?:[A-Z]|Mr|Mrs|Ms|Dr|St|Jr|Sr|Prof|Vol|vs|etc|No)\.$")
# A dotted abbreviation: two or more letter-dot groups such as "U.S.", "e.g.", "p.m." or "Ph.D.".
_DOTTED_ABBREVIATION = re.compile(r"(?:^|[^\w.])(?:[A-Za-z]{1,2}\.){2,}$")
_CJK_END = ("。", "！", "？", "」", "』", "）")


@dataclass(frozen=True)
class Summary:
    summary: str
    sentence_indices: tuple[int, ...]


class Summarizer(Protocol):
    """Turns a description into a summary, or None when it should be shown as it is. Phase 11 adds an abstractive one."""

    method: str

    def summarize(self, text: str, max_sentences: int = MAX_SENTENCES) -> Summary | None: ...


def plain_text(text: str) -> str:
    """What the import cleaning stores (`SanitizesProviderData::plainText`): entities decoded first, then tags stripped, trimmed."""
    return _TAGS.sub("", html.unescape(text)).strip()


def _continues(line: str, start: int, end: int) -> bool:
    """Whether the period ending at `end` leaves the sentence going. When in doubt it does: two sentences joined are better than a
    fragment that starts mid-sentence."""
    rest = line[end:].lstrip()
    if rest[:1].islower():
        return True

    candidate = line[start:end]
    if _ABBREVIATION.search(candidate):
        return True

    # At the end of the line there is nothing to merge with, so a dotted abbreviation there closes the sentence.
    return bool(rest) and bool(_DOTTED_ABBREVIATION.search(candidate))


def split_sentences(text: str) -> list[str]:
    """The sentences of the text's prose lines, in order. Structure lines, asides and a line's unfinished tail are not sentences."""
    sentences: list[str] = []

    for raw in plain_text(text).splitlines():
        line = raw.strip()
        if not line or _STRUCTURE.match(line) or _ASIDE.match(line):
            continue

        start = 0
        for match in _END.finditer(line):
            if match.group().startswith(".") and _continues(line, start, match.end()):
                continue
            sentence = line[start : match.end()].strip()
            start = match.end()
            if len(tokenize(sentence)) >= MIN_TOKENS:
                sentences.append(sentence)

    return sentences


def _join(sentences: list[str]) -> str:
    text = ""
    for sentence in sentences:
        text += sentence if not text or text.endswith(_CJK_END) else " " + sentence
    return text


def _scores(sentences: list[str]) -> list[float]:
    """Steinberger and Ježek's score: the length of each sentence's vector in the latent topic space, weighted by the singular values.

    Only the `TOPICS` strongest topics count: a description has a handful of sentences, and further dimensions each fit one off-topic
    sentence, which would then score as high as the central ones.
    """
    matrix = TfidfVectorizer(analyzer=tokenize).fit_transform(sentences)
    components = min(TOPICS, matrix.shape[0] - 1, matrix.shape[1] - 1)
    if components < 1:
        return [0.0] * len(sentences)

    weighted = TruncatedSVD(n_components=components, random_state=42).fit_transform(matrix)
    # Rounded so a last-bit difference between BLAS builds cannot reorder two equal sentences.
    return [round(float(s), 9) for s in np.sqrt((weighted**2).sum(axis=1))]


class LsaSummarizer:
    method = "lsa"

    def summarize(self, text: str, max_sentences: int = MAX_SENTENCES) -> Summary | None:
        """The best-scoring sentences that fit `MAX_CHARS`, at most `max_sentences`, in their original order; None when none fit or the
        result would not be shorter than the text."""
        sentences = split_sentences(text)
        if not sentences:
            return None

        scores = _scores(sentences)
        chosen: list[int] = []
        length = 0
        for index in sorted(range(len(sentences)), key=lambda i: (-scores[i], i)):
            if len(chosen) == max_sentences:
                break
            extra = len(sentences[index]) + (1 if chosen else 0)
            if length + extra <= MAX_CHARS:
                chosen.append(index)
                length += extra

        chosen.sort()
        summary = _join([sentences[i] for i in chosen])
        if not chosen or len(summary) >= len(plain_text(text)):
            return None

        return Summary(summary, tuple(chosen))
