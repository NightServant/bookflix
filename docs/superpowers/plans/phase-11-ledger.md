# Phase 11 ledger (Bookflix AI)

Plan: `2026-10-23-phase-11-bookflix-ai.md` on `claude/phase-plans-7d02cb`. Branch `phase-11-ai`, cut from `main` at 60a7c82 (Phases 1–10 merged, plus #37 and #38). Where a ruling below differs from the plan, the ruling wins.

## Conflict scan (against main at 60a7c82)

- **Provider.** The plan is written for the Anthropic API (Haiku/Sonnet, `cache_control`, Anthropic tool use). The user chose an **open-source model through a hosted, OpenAI-compatible API** (2026-11-24). No Anthropic code is written.
- **Search intent.** `QueryParser::parse(string $query, ?User $viewer = null): QueryIntent` (app/Discovery/Search/QueryParser.php). It is bound to `RuleBasedQueryParser` in AppServiceProvider:39. There is one app caller (search page :228). `RuleBasedQueryParser` caches 10 min under `query-intent:v2:{generation}:{sha1}`. The allowed values come from `QueryVocabulary::slugs(?User)` (kinds type/genre/theme/dewey/length, mature-gated).
- **Summaries.** There is no PHP `Summarizer` interface and no `SummaryResult`. Extractive summaries are written by the Python job `summarize-media` into `media_summaries` (media_id PK, summary, source_hash, method, generated_at). Those columns are a contract with `ml/tests/expected_columns.json`. The story page shows a summary only when `source_hash` matches the current description (stories/⚡show :115-120, :343-346).
- **Explanations.** `Explanation` (Phase 6) has `label()` and `parts()`. There is no popover and no "Why this?" anywhere: tiles show a plain reason line (`<x-cover-tile :reason :reason-subject>`), fed with strings from home/⚡index `reasonOf()`.
- **Model versions.** `ModelKind` has no `Llm` case. `model_versions.kind` is a plain string (no enum or check), so adding a case needs no migration. The enum's docblock says the Python service writes these values.
- **Privacy flag.** `profiles.personalization_enabled` (`User::personalizationEnabled()`), set from settings/⚡privacy.
- **Config.** There is no `ai` section and nothing in `.env.example`. No AI package is installed.
- **Queues.** Horizon has only the `default` and `ingestion` supervisors. There is no `ai` queue.
- **Postgres.** `pg_trgm` and `unaccent` are enabled. `immutable_unaccent()` exists, with trigram GIN indexes on the title and on `alternative_titles` (jsonb on `media`). Merged media follow `merged_into_id` (`Media::resolveSlug`).
- **Rate limiters** live in AppServiceProvider (70-84).
- **Tests.** `Http::preventStrayRequests()` applies to the Feature suite only. There is no shared fake helper; tests fake inline.
- **Admin.** The media edit page has room for a new `<x-settings-card>` between Locked Fields and the danger card.

## Rulings

| # | Ruling | Cost if wrong |
|---|---|---|
| P11-R1 | **A4: a thin `LlmClient` over Laravel `Http` speaking the OpenAI-compatible Chat Completions API.** It sends `POST {base_url}/chat/completions` with a Bearer key. Config is in `config/bookflix.php` `ai.*`, from env: `BOOKFLIX_AI_ENABLED` (false), `BOOKFLIX_AI_BASE_URL`, `BOOKFLIX_AI_API_KEY`, `BOOKFLIX_AI_MODEL_FAST` (intent, explanations; the plan's Haiku), `BOOKFLIX_AI_MODEL_SMART` (assistant, summaries; the plan's Sonnet). No model defaults: AI stays unavailable until they are set. No new package. | A provider quirk (a tool-call or usage field shape) needs a small adapter change. Everything is validated server-side either way. |
| P11-R2 | **Structured output:** send `response_format: {type: "json_object"}` by default, switchable to `json_schema` in config. The server always parses and validates, and anything invalid falls back to the non-AI path. | Providers that ignore JSON mode produce more fallbacks, never wrong data. |
| P11-R3 | **Caching:** no `cache_control`. Static system prompts come first and stay byte-stable, so a provider's automatic prefix caching can apply. `cached_input_tokens` is recorded from `usage.prompt_tokens_details.cached_tokens` when the provider sends it, else 0. Results are cached in Laravel by normalized input. | Higher input cost on providers without prefix caching. |
| P11-R4 | **Tools** use OpenAI function calling (`tools[].function`, `tool_calls`, `role: "tool"` results, `finish_reason`). At most 4 tool turns. `finish_reason: "length"` is flagged as truncated, and `content_filter` is treated as a refusal (fallback). | None found. |
| P11-R5 | **AI summaries get their own table `media_ai_summaries`** (media_id PK/FK, summary, source_hash, model_version_id FK, generated_at), not `media_summaries`, which is the Python contract, and not media_features. `LlmSummarizer` is a plain class used by the `GenerateAiSummary` job. The story page shows the AI summary with an "AI-generated" label when it exists and its hash matches; otherwise the extractive one, unlabelled, as today. | One extra table. |
| P11-R6 | `ModelKind::Llm = 'llm'`, written only by PHP. Prompt versions are `model_versions` rows: name `{feature}-prompt` (intent, assistant, summary, explanation), version `{prompt version}+{model}`. | None found. |
| P11-R7 | **Intent:** `QueryParser` binds to `FallbackQueryParser`, which checks `ai.enabled` and the kill switch per call (so switching off takes effect live). Anonymous viewers, rate-limited users and ProhibitedContent queries get the rules only. Allowed values are `QueryVocabulary::slugs($viewer)`, and the output is re-validated against them. The cache key includes the vocabulary generation and the mature-access key. | None found. |
| P11-R8 | **New `ai` queue** with its own Horizon supervisor (1 process, in every environment). The local worker command becomes `--queue=default,ingestion,ai`. | AI jobs never run if a worker leaves the queue out. |
| P11-R9 | **Rate limiters** in AppServiceProvider, per user: `ai-intent` 30/h, `ai-assistant` 20/h (per IP for guests, who get no assistant), `ai-explanations` 60/h. A daily token cap per user comes from `BudgetGuard` (`ai.daily_token_cap`). | None found. |
| P11-R10 | **ProhibitedContent:** text matching `ProhibitedContent::matches()` is never sent to the model (intent falls back to rules, the assistant answers with its fixed refusal line). A story whose title or description matches gets no AI summary or explanation. | None found. |
| P11-R11 | **Disclosure:** prompts go to a third-party provider. The assistant and Privacy settings say that an outside AI service answers, and what is sent. "Use my library" is off by default, per conversation, and offered only when personalization is on. | None found. |
| P11-R12 | **Design gate:** the assistant page, its nav entry, the AI labels and any "Why this?" disclosure for 11.5 are UI and need the user's sign-off. Backend first: 11.0 → 11.3 → 11.1 → 11.4 (job, table, label) → 11.2 runner and tools → (sign-off) → assistant UI → 11.5. | The UI waits on sign-off. |
| P11-R13 | **TitleVerifier** matches title plus `alternative_titles` through `immutable_unaccent` and `similarity() >= 0.90`, with the creator check when one is given and merged media resolved to the winner. | None found. |
| P11-R15 | **Design sign-off (user, 2026-11-24):**<br>- The assistant is a **primary nav item "Ask"**, with a sparkle icon, in the header and the phone bottom bar (5 items), going to `/assistant`.<br>- The page is one centred 720 px chat column: the user's messages on the right, answers on the left with a row of real catalog cover tiles, a 44 px input with Send, suggestion chips on the empty state, a "Use my library" switch (off; only when personalization is on), and the note "Answers come from an outside AI service. What we send" with a link.<br>- When AI is off or limited, one line plus a link to Search.<br>- AI text carries a small subdued **"AI-generated"** pill: next to the summary heading, and in a **"Why this?" popover** on recommended tiles. The tile's own reason line stays. | Five bottom-bar items are tight at 375 px, so it needs checking at that width. |
| P11-R16 | **TitleVerifier typo tier:** trigram similarity scored "Beserk" against "Berserk" at about 0.5, so 0.90 alone accepts only case, accent and punctuation variants. 0.90 stays for trigram-only matches. A typo tier is added: take the `%` candidates, then accept a Levenshtein distance ≤ 1 for normalized titles of 6–12 characters, ≤ 2 for longer ones, and never under 6 characters, when the lengths differ by at most 2. Order: exact, then ≥ 0.90, then typo. | Two works one letter apart ("Monster" and "Monsters") can be confused. Exact matches win and the creator check applies. |
| P11-R17 | **AI content shows only while `ai.enabled`.** With AI off, pages are exactly Phase 10: no AI-summary query, no labels, no "Why this?". The kill switch stops new calls but does not hide stored AI text. | Stored summaries disappear when AI is switched off, which is intended. |
| P11-R18 | **AI explanations are opt-in:** a new `profiles.ai_explanations_enabled` (default false), offered only with personalization on, because the "because you liked X" title comes from the reader's own history. Privacy settings gets an "AI features" section (`#ai`) holding the switch and the "What we send" text for all four features. | Few readers will see explanations until they opt in. |
| P11-R19 | **The assistant never writes recommendation impressions.** `get_recommendations` uses a seam that builds shelves without logging, since those rows are the labeled LTR/bandit data. Its search always asks for page 1, ignoring the request's `?page`. | None found. |
| P11-R14 | **Exit criterion "cost report from a 100-message scripted run"**: the scripted run uses faked responses with realistic usage numbers. A live cost run needs the user's provider key and their go-ahead. | The real cost is unknown until a live run. |

## Task log

| Task | Status | Commits |
|---|---|---|
| 11.0 LlmClient, budget guard, kill switch | done (31 tests) | 203da34 |
| 11.3 TitleVerifier | done (12 tests; typo tier ASCII-only, CJK exact/similarity) | see git log |
| 11.1 Intent behind QueryParser | done (Discovery folder 649 + QueryParserTest 37; limiter consumed on cache miss only) | see git log |
| 11.4 AI summaries | done (72 tests incl. story + admin; AI off = Phase 10 page, P11-R17) | see git log |
| 11.2 Assistant runner and tools | pending | |
| UI (assistant, labels) | signed off (P11-R15) | |
| 11.5 Explanations | done (43 tests; popover browser check pending in the UI task) | see git log |
