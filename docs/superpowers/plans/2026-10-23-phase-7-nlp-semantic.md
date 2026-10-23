# Phase 7 — NLP and Semantic Intelligence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Refresh against the code at phase start.** This plan is at task level (Interfaces + test names with key assertions). Before executing, re-read Phases 1–6 as built, expand each task to step level with `superpowers:writing-plans`, and reconcile names with the code.

**Goal:** Add multilingual semantic embeddings, semantic search fused with keyword search, a rule-based natural-language query parser, extractive LSA summaries, a semantic recommendation source, and an ML Dewey classifier that feeds the review queue.

**Architecture:** Master plan A8/A11/A13. Python `/v1/embed`, `/v1/summarize`, `/v1/classify/dewey` plus batch jobs; Laravel stores `media_embeddings` (vector(384), HNSW), adds a `SemanticLeg` to `HybridSearch` (RRF), and a PHP vocabulary-driven `RuleBasedQueryParser`. The query embedding is the **one** ML call allowed on the request path (300 ms timeout, cached, full-text fallback).

**Tech Stack:** `sentence-transformers` (CPU torch), `intfloat/multilingual-e5-small` (384 d, `query: ` / `passage: ` prefixes, D-11), scikit-learn, pgvector via `whereVectorSimilarTo`, Pest, pytest.

**Spec:** `/Users/gabe/Documents/Bookflix Documentation/Bookflix_Specification.md` §18, §21, §25, §26, §47 (rule-based) (in-repo copy `docs/superpowers/specs/bookflix-specification.md`). **Master plan:** A8, A10, A11, A13, D-11, D-12, R6, R9, R11, Part E Phase 7.

**Depends on:** Phase 6 (`MlClient` jobs, `ml/` job runner + registry, `model_versions`, `ml_job_runs`, `RunMlJob`, `CandidateSource`, `HybridSearch`/`SearchLeg`/`Rrf`, `media_lsa_vectors`), Phase 3 (`ClassificationService`, `ClassifyMedia`, review queue), Phase 4 (search page).

## Global Constraints

- Master-plan constraints apply (open-source only, Flux free, tests on Postgres, fixtures shared via `tests/Fixtures/ml-contracts/`, `Http::preventStrayRequests()`).
- Embedding text: passages `"passage: " + title + description + genres + themes + tags + characters`; queries `"query: " + text`. 384 dims; vectors L2-normalized; cosine.
- Semantic search timeout **300 ms**, query embeddings cached (key `q-embed:{model_version}:{sha1(normalized query)}`, 24 h), any failure → keyword-only results, never an exception.
- A new embedding model with different dimensions needs a new column/table via migration (A6) — never reuse `vector(384)`.
- Dewey auto-classifications are `pending`, method `Automatic`, source `dewey-classifier`, with `model_version_id`; public only at ≥ 0.80 confidence (Phase 3 rule).
- Summaries are extractive (LSA), deterministic, sentences in original order, behind a `Summarizer` strategy so Phase 11 can add an abstractive one; text shorter than the limit is returned unchanged. Stored summaries are not labeled "AI-generated" (they are extractive); Phase 11's abstractive ones are.
- Python loads only artifacts it wrote; `HF_HOME` cached in the image; the model downloads at build time, never at request time.

## Review Focus

1. Semantic leg failure (timeout, 5xx, ML down, bad dimensions) never breaks search; results equal keyword-only. → 7.2.
2. Mixed-language, empty, very long and emoji queries to `/v1/embed` and the parser. → 7.1, 7.3.
3. Embedding drift: switching the active embedding version must not mix vectors from two models in one query. → 7.1, 7.2.
4. Classifier output can't violate Dewey rules (≤ 1 primary, ≤ 3 secondary, unknown codes dropped) and never overwrites manual/accepted decisions. → 7.6.
5. Short or empty text for summarization (0, 1, 2 sentences; no sentence punctuation; CJK). → 7.4.

---

### Task 7.1: `/v1/embed`, embedding versions and batch embedding → `media_embeddings`

**Files:**
- Create (Python): `ml/bookflix_ml/embeddings.py`, `ml/app/routers/embed.py`, `ml/tests/{test_embeddings,test_embed_api,test_embed_job_db}.py`, `Dockerfile` model pre-download step; job `embed-media` registered in `bookflix_ml/jobs.py` (new job name — A8's list gains it)
- Create (PHP): migration `create_media_embeddings_table` (A6: PK `(media_id, model_version_id)`, `embedding vector(384)`, HNSW `vector_cosine_ops`), `app/Models/MediaEmbedding.php`, `app/Ml/{EmbeddingResult,SummaryResult}.php`, `app/Jobs/GenerateMediaFeatures.php`, `app/Console/Commands/EnsureEmbeddingVersion.php`, `tests/Fixtures/ml-contracts/embed.json`
- Modify: `app/Ml/MlClient.php`, `app/Catalog/Ingestion/IngestionPipeline.php` (dispatch `GenerateMediaFeatures` after `ClassifyMedia`, per A9)
- Test: `tests/Feature/Ml/EmbedClientTest.php`, `tests/Feature/Intelligence/GenerateMediaFeaturesTest.php`

**Interfaces:**
- Python: `embeddings.embed(texts: list[str], kind: Literal["query", "passage"]) -> np.ndarray` (prefix applied, normalized, batch ≤ 64, empty list → empty array); `POST /v1/embed {texts, kind}` → `{model_version, dims, vectors}` (422 on empty `texts`, `texts` > 128, any text > 8 000 chars, bad `kind`). `model_version` = `"{model name}@{revision}"`.
- PHP (A8): `MlClient::embed(array $texts, string $kind): EmbeddingResult` (`modelVersion: string`, `dims: int`, `vectors: list<list<float>>`; optional `?float $timeout` argument — default 5 s, search passes 0.3). `GenerateMediaFeatures(int $mediaId)` — queue `ingestion`: builds the passage text (`SearchTextBuilder`-independent `EmbeddingText::forMedia(Media): string`), embeds, upserts `media_embeddings` under the **active** `embedding` model version (creates it from `MlClient::models()` via `EnsureEmbeddingVersion` when none exists); skips when dims ≠ 384 or the media has no text; idempotent. `bookflix:ensure-embedding-version`: creates `model_versions` row `name = embedding`, `kind = embedding`, `version = <ml model_version>`, `status = active`, retiring others with different dims in one transaction.

- [ ] **Tests (key assertions):**
  - pytest: `test_query_and_passage_prefixes_are_applied` (monkeypatched encoder records inputs: `"query: x"`, `"passage: y"`); `test_vectors_are_384_dimensional_and_unit_length`; `test_empty_list_returns_empty`; `test_batches_larger_than_64_are_chunked_and_order_preserved`; `test_multilingual_pairs_are_closer_than_unrelated` (slow-marked, real model: `"dark fantasy manga"` ↔ `"ダークファンタジー漫画"` cosine > cosine to `"cooking recipes"`); API: 422 cases above, 401 without token, response keys equal `embed.json` contract.
  - db pytest: `embed-media` job writes one row per live media for a new candidate `embedding` version, resumable (second run adds no duplicates), a failure leaves no rows for the version.
  - Pest: `EmbedClientTest` — request body carries `kind`, `Http::fake` returns contract; `timeout` option is applied (`Http::assertSent` checks request option via `Http::fake` + `->timeout`); `MlServiceUnavailable` on 5xx. `GenerateMediaFeaturesTest` — upserts under the active version only; second run no duplicate; wrong-dimension response is skipped and logged; empty text media skipped; **after switching the active version, only the new version's row is created** (Review Focus #3); ingestion dispatches the job; `Http::preventStrayRequests` honoured.
- [ ] **Commit per sub-deliverable:** `feat: embed endpoint and ml embedding job`, `feat: media embeddings and GenerateMediaFeatures`.

---

### Task 7.2: Semantic search leg and fusion (Review Focus #1, #3)

**Files:**
- Create: `app/Discovery/Search/Legs/SemanticLeg.php`
- Modify: `app/Discovery/Search/HybridSearch.php` (legs list gains the semantic leg when `config('bookflix.search.semantic_enabled')` and an active embedding version exists), `config/bookflix.php` (`search.semantic_enabled`, `search.semantic_timeout=0.3`, `search.semantic_min_similarity=0.6`), `app/Ml/MlClient.php` (timeout param from 7.1)
- Test: `tests/Feature/Discovery/SemanticSearchTest.php`, `tests/Unit/Discovery/RrfFusionTest.php` (extends Phase 4)

**Interfaces:** consumes `SearchLeg`, `Rrf::fuse`, `SearchFilters::apply`. `SemanticLeg::ids(string $query, SearchFilters $filters, int $limit = 100): array` — embeds `"query"`, `MediaEmbedding::query()->where('model_version_id', activeEmbeddingVersionId)->whereVectorSimilarTo('embedding', $vector, minSimilarity: …)` joined to `media` with filters applied in SQL, ordered by similarity; returns `[]` on `MlServiceUnavailable`, `ConnectionException`, timeout, dimension mismatch or no active version (logs at `warning` with request id).

- [ ] **Tests:**
  - `fusion order on fixtures`: lists `[A,B,C]` (keyword) and `[C,A,D]` (semantic) → fused order `[A,C,B,D]` with RRF k = 60.
  - `semantic hits that keyword search misses are returned`: media "A lonely robot learns to love" found for query `"sad machine who feels"` with faked embeddings placing them close.
  - `ml timeout falls back to keyword only without exception`: `Http::fake` throws `ConnectionException` → result equals the keyword-only result; no `report()`-level exception; log written.
  - `ml 503 / malformed vectors / wrong dims fall back` (dataset).
  - `query embeddings are cached`: second identical (case/whitespace-normalized) query makes no HTTP call; different active version → cache miss.
  - `filters are applied before fusion`: semantic neighbor of the wrong media type is excluded; `limit` honoured.
  - `no active embedding version → leg skipped` and `rows from a retired version are never used`.
  - `empty or punctuation-only query never calls ML`.
- [ ] **Commit:** `feat: semantic search leg with rrf fusion and safe fallback`

---

### Task 7.3: Rule-based `QueryParser` and intent chips

**Files:**
- Create: `app/Discovery/Search/{QueryIntent,QueryParser,RuleBasedQueryParser}.php`, `app/Discovery/Search/Vocabulary.php` (cached lookup of media types, genres, themes, Dewey class names, with synonyms from `database/data/query_synonyms.csv`: `phrase,kind,slug`), `resources/views/components/intent-chips.blade.php`
- Modify: `resources/views/pages/search/⚡index.blade.php` (parse on submit, show removable chips, apply intent), `app/Discovery/Search/HybridSearch.php` (accept an optional `QueryIntent`)
- Test: `tests/Unit/Discovery/RuleBasedQueryParserTest.php`, `tests/Feature/Discovery/NaturalLanguageSearchTest.php`

**Interfaces:**
- Produces (A7): `final readonly class QueryIntent { __construct(public array $mediaTypes = [], public array $genres = [], public array $themes = [], public array $deweyCodes = [], public ?string $lengthHint = null, public string $semanticText = '') }`; `interface QueryParser { public function parse(string $query): QueryIntent; }` (Phase 11 adds an LLM implementation behind it); `RuleBasedQueryParser` bound as default. Note: master plan A8 lists a Python `/v1/query/parse`; this plan keeps parsing in PHP (the vocabulary lives in Postgres) and does **not** add that endpoint — see report.
- Application rule: intent media types/genres/themes become SQL filters only when at least one result remains; otherwise they are dropped (progressive relaxation) and the chips show them as "suggested" instead of "applied". `semanticText` feeds the semantic leg; the original string feeds the keyword leg.

- [ ] **Tests:**
  - `dark fantasy manga with political intrigue` → `mediaTypes ['manga']`, `genres ['fantasy']`, `themes ['politics']`, `semanticText` contains `dark`, `fantasy`, `political intrigue`.
  - `short science-fiction novel I can finish this weekend` → `['novel']`, `['science-fiction']`, `lengthHint 'short'`.
  - `something similar to Dune but more philosophical` → no crash; `themes ['philosophy']`; `semanticText` keeps `dune` (anchor handling is Phase 11).
  - Dataset: plural/case/accents (`Mangas`, `SCI-FI`, `fantasía`), Japanese terms (`漫画`), multiword synonyms (`graphic novels`), negation words ignored (`not romance` → no `romance`), emoji/empty/5 000-char strings return an intent without exceptions, `deweyCodes` from class names (`"history books"` → `['900']`).
  - Search page: chips render and removing a chip re-runs without that filter; relaxation case (type has no results) drops it and still shows results; the parser result is cached per normalized query for 10 minutes.
  - Perf: parsing makes ≤ 2 queries after the vocabulary is cached.
- [ ] **Commit:** `feat: rule-based natural language query parser and intent chips`

---

### Task 7.4: `/v1/summarize` (LSA extractive) and stored summaries (Review Focus #5)

**Files:**
- Create (Python): `ml/bookflix_ml/summarize.py` (`Summarizer` protocol, `LsaSummarizer`), `ml/app/routers/summarize.py`, tests
- Create (PHP): migration adding `creators.bio_summary text null`, `series.description_summary text null` (A6 has `media_features.summary` only — flagged), `app/Ml/SummaryResult.php`, `app/Jobs/SummarizeText.php`; `tests/Fixtures/ml-contracts/summarize.json`
- Modify: `MlClient::summarize`, `GenerateMediaFeatures` (also summarizes descriptions over 600 chars into `media_features.summary` for the active LSA version), story page (renders summary with "Read more" `<details>` for the full text), creator/series pages as they exist
- Test: `ml/tests/test_summarize.py`, `tests/Feature/Ml/SummarizeClientTest.php`, `tests/Feature/Intelligence/SummaryStorageTest.php`

**Interfaces:** `LsaSummarizer.summarize(text: str, max_sentences: int = 3) -> SummaryOut(summary, sentence_indices, method="lsa", model_version)`: split sentences (including CJK `。！？`), sentence × term TF-IDF, `TruncatedSVD` (`random_state=42`), Steinberger & Ježek sentence scores, top-k returned **in original order**. `POST /v1/summarize {text, max_sentences=3}` (422 for > 50 000 chars or `max_sentences` outside 1..10). `MlClient::summarize(string $text, int $maxSentences = 3): SummaryResult`.

- [ ] **Tests:** pytest — `deterministic output across runs`; `sentences kept in original order`; `text with <= max sentences returned unchanged` (0, 1, 2 sentences); `no terminal punctuation → treated as one sentence`; `CJK text is split and summarized`; `whitespace-only text → 422`; `selects the central sentences` on a fixture paragraph (assert the 2 known central sentence indices); contract keys equal `summarize.json`. Pest — `summary stored for long descriptions only`; `second run overwrites rather than duplicates`; `ML failure leaves the full description visible and no summary` ; `story page shows summary and full text behind <details>`; `short description shows no summary block`.
- [ ] **Commit:** `feat: lsa extractive summaries`

---

### Task 7.5: Semantic recommendation source

**Files:** Create `app/Discovery/Recommendations/Sources/SemanticSimilarSource.php`; Modify `ml/bookflix_ml/similarities.py` (method `embedding` from `media_embeddings` top-50 via blockwise cosine; job `build-similarities` params `methods` gains `embedding`), `RecommendationServiceProvider` (tag the source), `HybridRanker` (`content_similarity` also considers `embedding` neighbors — already specified in Phase 6), `routes/console.php` (embedding similarities in the weekly pipeline after `embed-media`); Test: `tests/Feature/Discovery/Recommendations/SemanticSimilarSourceTest.php`, `ml/tests/test_similarities.py` (embedding method)
**Interfaces:** `SemanticSimilarSource` key `semantic_similar`, reason `ReasonType::SimilarThemes`, `reasonMediaId` = best seed; active `embedding` version only; excludes library/dismissed/deleted (shared base).
- [ ] **Tests:** `contributes candidates with reason similar_themes and the seed as reason media`; `ignores rows of retired embedding versions`; `no seeds → empty`; `same media never its own neighbor` (pytest + Pest); `shelf "Similar To {Y}" prefers embedding neighbors when present` (ShelfBuilder test).
- [ ] **Commit:** `feat: semantic recommendation source`

---

### Task 7.6: `DeweyClassifier` — kNN over embeddings + LSA logistic regression (Review Focus #4)

**Files:**
- Create (Python): `ml/bookflix_ml/dewey.py`, `ml/app/routers/classify.py`, job `train-dewey-classifier` (new job name), tests, `tests/Fixtures/ml-contracts/classify-dewey.json`
- Create (PHP): `app/Classification/Dewey/DeweyClassifier.php`
- Modify: `MlClient::classifyDewey(Media $media): array` (returns `list<ClassificationCandidate>`), `app/Jobs/ClassifyMedia.php` / `ClassificationService::classify` (append classifier candidates, method `Automatic`, source `dewey-classifier`, `modelVersionId`), admin review queue (shows model version + confidence)
- Test: `ml/tests/test_dewey.py`, `tests/Feature/Classification/DeweyClassifierTest.php`

**Interfaces:** `POST /v1/classify/dewey {media_id, text, genres, themes, subjects, external_code}` → `{candidates: [{code, confidence}], model_version}`; training data = `media_dewey_classes` rows with `review_status = accepted` (primary class labels); ensemble = kNN (k = 15, cosine over `media_embeddings`) averaged with logistic regression over `media_lsa_vectors`, probabilities calibrated (`CalibratedClassifierCV`, isotonic; sigmoid when a class has < 10 samples); training refuses with `NotEnoughData` below 100 accepted primaries or 3 classes. `DeweyClassifier::predict(Media $media): list<ClassificationCandidate>`.

- [ ] **Tests:** pytest — `accuracy above a majority-class baseline on a fixture split` (assert accuracy ≥ baseline + 0.10); `probabilities sum to <= 1 and are calibrated within 0.15 on the fixture` (reliability check); `unknown labels never predicted`; `too few samples raises NotEnoughData and registers no version`; contract keys equal fixture. Pest — `low confidence stays pending and non public` (0.55 → hidden from explore, visible in review queue); `high confidence pending >= 0.80 is public`; `classifier output is passed through DeweyValidator` (returns 5 candidates → ≤ 1 primary + ≤ 3 secondary); `codes missing from the seeded hierarchy are dropped`; `never overwrites a manual or accepted decision and never resurrects a rejected pair`; `ML down → classification falls back to rules only, no exception`; `candidate carries model_version_id`.
- [ ] **Commit:** `feat: dewey classifier via ml service`

---

## Phase 7 exit criteria
- [ ] `composer test` + ML suite + browser smoke (search with natural-language chips) green; embeddings backfilled for the catalog; HNSW index used (`EXPLAIN` in a test).
- [ ] Offline check: semantic leg improves zero-result queries (compare `search_queries` zero-result rate on a fixture set); document numbers in the PR.
- [ ] Docker image size recorded (R11); `security-review` not required; `/code-review` and `ponytail:ponytail-review` run.
- [ ] Ask the user to run the full `php artisan test --compact`.
