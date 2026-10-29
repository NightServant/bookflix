# Bookflix Master Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build Bookflix, a single platform for discovering, organizing, tracking, rating and getting recommendations across ten story formats. It grows step by step out of the existing Laravel 13 Livewire starter kit, MVP first.

**Architecture:** One modular Laravel 13 app: Blade + Livewire 4 + Flux UI (free) + Alpine + Tailwind v4, with Motion for animation. It runs on Neon Postgres with pgvector, `pg_trgm` and `unaccent`. One small Python FastAPI service (`ml/`) handles NLP/ML: Laravel calls it over authenticated HTTP from queued jobs, and its batch jobs write vectors, similarities and model versions back to Postgres. Page requests only read Postgres and cache. Recommendations come from precomputed candidates that are ranked when the request arrives.

**Tech Stack:** PHP 8.4 · Laravel 13.38 · Livewire 4.4 · Flux 2.21 (free edition) · Fortify 1.41 · Alpine 3.17 + `@alpinejs/ui` · Tailwind 4 · Motion (motion.dev, vanilla JS API) · Vite 8 · Pest 5.3 · Larastan 3.13 (level 7) · Pint · Neon Postgres + pgvector · Redis + Horizon (from Phase 2) · Laravel Scout (database engine, from Phase 4) · S3-compatible storage (R2/S3) · Python 3.12 · FastAPI · scikit-learn · NumPy · pandas · sentence-transformers · implicit · LightGBM (Phase 8) · uv · pytest · ruff · mypy · Figma (MCP) for design.

**Spec:** `/Users/gabe/Documents/Bookflix Documentation/Bookflix_Specification.md`. The repo is public, so the spec is **not** copied into it (decision D-27); executors read it at that absolute local path. Section references below (§N) point to it.

**Design reference:** Figma "Netflix Design System 2024 (Website ver.)": file key `vHWecJSYA5Afuwr0ntLzbA` (pages: UI Kit `0:1`, Screen Templates `212:22353`, Cover `3:77`).

---

## Progress (updated 2026-10-29)

| Work | PR | State |
|---|---|---|
| Phase 1: Foundation | #4 | done |
| Phase 2: Catalog and ingestion | #6 | done |
| Phase 3: Dewey-inspired classification | #7 | done |
| Logo mark and wordmark | #8 | done |
| Phase 4: Discovery, review fixes and the 3D redesign (`phase-4-redesign.md`) | #9, #10 | done |
| Catalog expansion wave 1: `media_metrics`, popularity score, AniList/MangaDex/MangaUpdates/Open Library trending, Comic Vine, Metron, Hardcover, Wikidata, Wikipedia pageviews | #11 | done |
| MangaDex covers: `Referrer-Policy: same-origin` (hotlink placeholder fix) | #12 | done |
| Taxonomy part 1: genre tree, tropes, demographics, warning statuses, audience ratings, `MediaTypeClassifier`, `bookflix:reclassify` (`genre-taxonomy-spec.md`) | #14 | done |
| Taxonomy part 2: `<x-filter-panel>` genre-tree filters on Search and Discover; mature access gate (`Media::visibleTo()`, Settings → Privacy) | #15 | done |
| Landing page: centred hero with 3D books, Top 10 marquee, problem and solution sections, FAQ, CTA, footer | #13 | done |
| Landing showcase behind the mature gate | #16 | open |
| Phase 5: Personal library (`2026-10-23-phase-5-personal-library.md`, revised 2026-10-29) | `phase-5-library` | in progress |

After pulling these merges, run locally: `php artisan migrate`, `php artisan db:seed --class=TaxonomySeeder`, then for each of `openlibrary`, `comicvine`, `metron` and `hardcover`: `php artisan bookflix:reclassify --resync --source=X` and `php artisan bookflix:sync X --full`.

---

## How this plan is organized

| Part | Contents | Spec |
|---|---|---|
| A | Architecture deliverables: architecture, schema, folders, contracts, Python interfaces, ingestion, Dewey, NLP, recommendations, search, environment variables, testing, MVP scope, risks, open questions | §56 items 1–16 |
| B | Skills, plugins, MCP tools and agents, mapped to phases | (user request) |
| C | Figma design workstream (Netflix-based design system → Bookflix prototypes) | §51–52 |
| D | **Phase 1 Foundation, split into small TDD tasks, ready to execute** | §54 Phase 1, §56 items 17–18 |
| E | Milestone outlines for Phases 2–11. Each gets its own detailed plan (`superpowers:writing-plans`) when it starts | §54 |
| F | Delivery loop and quality gates for every phase | §53, §57 |
| G | Spec coverage matrix + decision log | — |

The spec covers 11 independent subsystems. Following the writing-plans scope rule, only Phase 1 is broken down to step level here. Every later phase starts with its own plan, written against the code as it exists by then.

---

## Global Constraints

Every task follows these. Values are copied from the spec or the repo.

- PHP `^8.3` (runtime 8.4.19), Laravel `^13.17` (13.38.0), Livewire `^4.1` (4.4.8), Fortify `^1.37.2`, Pest `^5.3`, Larastan level `7`.
- **UI library: Flux UI free edition only.** Available: avatar, badge, brand, breadcrumbs, button, callout, card, checkbox, dropdown, field, heading, icon, input, modal, navbar, otp-input, pagination, profile, progress, radio, select, separator, skeleton, switch, table, text, textarea, toast, tooltip (plus the navlist/sidebar/header layout parts the starter kit already uses). Pro components (accordion, tabs, autocomplete, file upload, slider, date picker, chart, command, popover) are **not** available. Use native HTML or Alpine instead: `<details>` for accordions, `<input type="file">` with Livewire `WithFileUploads`, `<input type="range">`, links for tabs.
- **Component sourcing order (C6):** Flux free → Alpine plugins already bundled with Livewire 4 → native HTML → `@alpinejs/ui` headless widgets → copy-paste Alpine patterns restyled as Bookflix Blade components → single-purpose libraries. **Everything added is open source (MIT, Apache-2.0, ISC, OFL); no paid libraries; never a second full component library.** The one deliberate exception is Flux free, which is free but proprietary-licensed (kept by decision, Q17).
- **Animation: Motion (motion.dev), the free open-source core only, vanilla JS API** (`animate`, `inView`, `scroll`, `stagger`), wired in through small Alpine directives. Motion+ (paid) is not used. Hover and focus states use **CSS transitions**. Motion is only for what CSS can't do: springs, in-view staggers, hero transitions, preview-card expansion. Every animation checks `prefers-reduced-motion: reduce` and jumps straight to the end state. Page transitions use Livewire's native `wire:transition` / View Transitions, not Motion.
- Livewire pages follow the starter-kit convention: single-file `resources/views/pages/<area>/⚡<name>.blade.php`, routed with `Route::livewire('<path>', 'pages::<area>.<name>')`.
- Auth is Fortify with email/password. **No OAuth/Socialite** (§4).
- Database: Neon Postgres + pgvector (§4). **Tests run on Postgres, never SQLite**, because full-text search, trigram and vector features don't exist there.
- Vectors use Laravel 13's built-in support, confirmed in `vendor/laravel/framework`: `Schema::ensureVectorExtensionExists()`, `$table->vector($column, $dimensions)` with HNSW `vector_cosine_ops` indexes, and `whereVectorSimilarTo($column, $vector, $minSimilarity = 0.6)`. No pgvector PHP package.
- Use `php artisan make:* --no-interaction` to create files. Run `vendor/bin/pint --dirty --format agent` after PHP edits. Run `search-docs` (Boost) before relying on any Laravel-ecosystem API.
- No new Composer/npm/Python dependencies and no new top-level folders unless approved. The list awaiting approval is in A4.
- Primary navigation lives once in `config('bookflix.navigation')`; later phases add items there, not to the layouts. Red (Flux `primary`) is for CTAs only; Save buttons in settings/admin use `variant="filled"`. Livewire temporary uploads never use a public disk.
- Design tokens (§51): red `#e50914` is **only** for primary CTAs, active-state accents and the logo mark. No drop shadows; depth comes from stepping through surfaces (canvas → deep → card → input). Radii are 0 / 4 / 8 / 16 px. Spacing is 2, 3, 8, 12, 16, 24, 64 px, with a 148 px desktop gutter.
- The UI is dark only (D-4). Fonts: `'Netflix Sans', 'Helvetica Neue', 'Segoe UI', Roboto, Ubuntu, sans-serif`. Netflix Sans is **never shipped or embedded** because it's proprietary.
- No Netflix logos, wordmarks, posters, artwork or screen copies anywhere: code, Figma prototypes or demo data (§51 "not a license to reproduce Netflix's actual browse screens").
- The classification layer is named "Dewey-inspired" and is **never** presented as an official or licensed DDC (§11).
- Metadata and content distribution stay architecturally separate. No copyrighted content is hosted or streamed without a verified `content_sources` row (§6).
- Secrets stay server-side only. Only `VITE_APP_NAME` may carry a `VITE_` prefix (§59).
- Expensive NLP/ML work never runs inside a user request (§61). The one exception is the Phase 7 query embedding, which has a 300 ms timeout and a full-text fallback.
- After feature tests pass, ask the user to run the full `php artisan test --compact` (AGENTS.md).

## Review Focus

These are the five failure modes most likely to bite real users that no task's happy path covers. Each has a test pinned to the task that owns the code.

1. **The same work arrives from two providers, or two different works share a title.** "Berserk" from AniList and from Open Library must become *one* Story with two external IDs. "Monster" (Urasawa manga) and an unrelated novel called "Monster" must *never* merge. → Phase 2, task 2.5.
2. **Non-Latin, accented and alternative titles in search.** `進撃の巨人`, `Shingeki no Kyojin`, `Attack on Titan`, `Pokemon` vs `Pokémon`, and one-letter typos (`Berserl`) must all find the work. → Phase 4, task 4.1.
3. **A user with zero or one interaction.** The homepage is never empty and never errors: it falls back to popular, trending, editorial and new-release shelves (§33). → Phase 6, task 6.8.
4. **A provider fails mid-sync** (429, 5xx, timeout, malformed payload). The run is marked `partial`/`failed`, existing data is untouched, the job retries with backoff, and a retry never creates duplicate rows (idempotent upsert). → Phase 2, task 2.7.
5. **Deactivated accounts and personalization opt-out.** A deactivated user can't use the app by any login path (password, passkey, remember-me). An opted-out user generates no `user_interactions` rows. Deactivated and opted-out users are left out of model training. → Phase 1, task 1.6 · Phase 5, task 5.1 · Phase 6, task 6.2.

---

# Part A — Architecture deliverables (§56 items 1–16)

## A1. Baseline: what the starter kit already provides

| Area | Present today | Spec gap |
|---|---|---|
| Auth (Fortify) | register, login, logout, password reset, email verification, **2FA, passkeys**, tests | none (2FA and passkeys go beyond the spec; see D-2) |
| Profile | name, email | avatar, bio, languages, favorite genres/types, reading preferences (§8) |
| Account | permanent delete | **deactivation** + retention (§8, §60) |
| Settings | profile, security, **appearance (light/dark)** | privacy settings (§8); dark-only tokens (D-4) |
| Layout | sidebar layout active (`layouts/app.blade.php`), header layout available | top nav + mobile nav (§51) |
| Routes | `/` → `welcome` (name `home`), `/dashboard` | Home, Discover, Search, Library, Collections, History, Profile |
| DB | SQLite | Neon Postgres + pgvector |
| CI | GitHub Actions → `composer ci:check` (Pint, PHPStan L7, Pest) on SQLite | Postgres service, Python job |
| Tooling | Boost MCP, project skills (`.claude/skills`), Blaze, Sail, Pail, Pao | — |

## A2. Proposed architecture (§48, §62, §64)

```text
Browser ── Blade · Livewire 4 · Flux (free) · Alpine · Motion · Tailwind 4
   │
   ▼
Laravel 13 (one deployable, modular by domain)
   app/Catalog  app/Classification  app/Discovery  app/Ml  (app/Reader in Phase 9)
   Livewire pages ── Actions/Services ── Eloquent / Query Builder
   Queued jobs (database queue → Redis + Horizon from Phase 2) · Scheduler
   │                         │                                   │
   ▼                         ▼                                   ▼
Neon Postgres            S3-compatible storage            Python ML service (FastAPI, ml/)
 pgvector · pg_trgm ·     covers (cache policy), avatars,   /v1/embed /v1/summarize
 unaccent · FTS           licensed/PD files, ML artifacts   /v1/classify/dewey /v1/query/parse
   ▲                                                         /v1/jobs/* (batch training)
   └────────────── batch jobs write vectors, similarities, model_versions ─┘

External metadata providers ──► app/Catalog/Providers adapters ──► ingestion pipeline
(Open Library, AniList, MangaDex, Metron, VNDB, Taddy; Gutenberg/Standard Ebooks for public-domain files)
```

Runtime rules:
- **Request path:** Postgres + cache reads only. User interactions are recorded through a queued job. ML calls happen only in jobs, with one exception: in Phase 7, semantic search embeds the query (cached, 300 ms timeout, falls back to full-text search).
- **Scalability (§62):** start as one app with domain folders. Later splits (Catalog/Search/Recommendation APIs, separate Python workers) happen only when metrics justify them. The Python service is already a separate process because it's a different runtime.
- **Deployment:** Laravel app on Laravel Cloud (Boost `deploying-to-cloud` skill; `boost.json` has `cloud: true`), connected to a Neon project. ML service as a Docker container on a CPU host (Q8). Neon branch per PR for preview environments.

## A3. Decisions and spec deviations (need approval; defaults shown)

| ID | Decision | Spec | Reason | Default |
|---|---|---|---|---|
| D-1 | One `creators` table plus `media_creators.role` (author, artist, illustrator, writer, penciller, inker, colorist, translator, developer) instead of separate authors/artists/creators tables | §7 | One person often holds several roles (a mangaka is author and artist). Avoids duplicate people and three pivot tables. Publishers stay separate. | adopt |
| D-2 | Keep the starter kit's 2FA and passkeys | §8 | The spec excluded OAuth because of cost. These are free and already tested. | keep |
| D-3 | Replace permanent delete with deactivation plus a scheduled purge | §8, §60 | Spec asks for deactivation and retention | adopt, 30 days |
| D-4 | Dark-only UI; remove the appearance settings page | §51 | The token set only defines dark values | adopt |
| D-5 | Pest browser testing (`pestphp/pest-plugin-browser`, Playwright) instead of Dusk | §4 | Same runner and syntax as feature tests, Livewire-aware, no ChromeDriver to manage. §4 allows a change with a documented reason. | adopt |
| D-6 | Favorites stored as `user_library.favorited_at`, not a `favorites` table | §7 | Favoriting implies the story is in the library; one row per user × story | adopt |
| D-7 | Extra tables beyond §7: `media_relations`, `duplicate_candidates`, `ingestion_runs`, `content_sources`, `search_queries`, `media_similarities`, `reports`, `follows` | §6, §10, §40, §42, §43, §46, §61 | Each backs a spec requirement that §7 doesn't name a table for | adopt |
| D-8 | Classification tables stay Dewey-specific (`dewey_classes`, `media_dewey_classes`). A second scheme (LCC, BISAC; §49) gets sibling tables plus a shared contract, extracted when it actually lands. | §13, §49 | Keeping the module isolated in `app/Classification` keeps that later extraction cheap. Avoids an interface with only one implementation. | adopt |
| D-9 | Database queue and cache in Phase 1; Redis + Horizon from Phase 2 | §4 "use when required" | Ingestion is the first real queue load | adopt |
| D-10 | Admin UI built with Livewire + Flux free (no Filament) | §43 | Only a handful of screens; no new dependency; stays on the same stack | adopt |
| D-11 | Embedding model `intfloat/multilingual-e5-small`, 384 dimensions | §26 | Handles multiple languages (manga/manhwa/manhua), small, fast on CPU, free. Requires `query: ` / `passage: ` prefixes. | adopt |
| D-12 | Hybrid search merges result lists with Reciprocal Rank Fusion (k = 60), then applies a personalization boost | §21 | Needs no tuned weights and is robust across result lists | adopt |
| D-13 | The mobile bottom nav shows 5 items (Home, Discover, Search, Library, Profile). Collections and History live under Library. The desktop top nav shows all 7. | §51 | 7 tabs don't fit on a 390 px screen with 44 px touch targets | adopt |
| D-14 | Ratings stored as integers 1–10 and shown as 5 stars with half stars | §39 | Allows half stars without decimals | adopt |
| D-15 | Interaction weights and hybrid weights stored as `model_versions` rows (`name = interaction-weights` / `hybrid-weights`), seeded from `config/bookflix.php` | §29 | Configurable and versioned, never hard-coded, and learnable later | adopt |
| D-16 | Motion for animation; CSS for hover and focus | (user) | Using CSS where it's enough keeps the JS small; Motion handles the rest | adopt |
| D-17 | Optionally self-host Inter (OFL-1.1) ahead of the OS fonts in the stack | §51 | Netflix Sans can't ship; Helvetica Neue exists only on Apple devices and has no 900 weight | rejected for code; Inter is Figma-only |
| D-19 | `media.merged_into_id` (nullable self FK) records a merge; the loser is soft-deleted and its slug resolves to the winner (301) | §10, §42 | A9 promised reversible merges and slug redirects but A6 had no column for them | adopt |
| D-20 | ISBN-13s are stored as `media_external_ids` rows under a synthetic `external_sources` key `isbn` | §5, §9 | Reuses the unique `(source, external_id)` constraint for exact-match dedup; no new column or table | adopt |
| D-21 | Dedup: a same-family match that is not auto-merge-eligible (including title similarity ≥ 0.90 without a shared creator or year within ±1) and has similarity ≥ 0.75 becomes a `duplicate_candidates` row | §42, §43 | A9's bands left ≥ 0.90 without a creator or year match undefined; false merges are costlier than review work (R8) | adopt |
| D-22 | `search_queries` is created in Phase 4 (task 4.3), not Phase 5. Search click-through is a `?sq={id}` URL parameter until Phase 5's `InteractionRecorder` turns it into a `click` interaction. | §46 | Task 4.3 logs queries before `user_interactions` exists | adopt |
| D-23 | Phase 4 ships an interim `Media::scopeTrending()` (ratings_count, then recency); Phase 6 task 6.3 redefines it over `media_popularity` | §35 | The materialized view only exists in Phase 6 | adopt |
| D-24 | Natural-language parsing is vocabulary-driven and lives in PHP (`QueryParser`); the Python `/v1/query/parse` endpoint is dropped from A8 | §47 | The vocabulary lives in Postgres; a Python endpoint would need the whole catalog vocabulary round-tripped | adopt |
| D-25 | Tables and columns added beyond A6, each in the phase that needs it: `ml_job_runs` (6), `ltr_scores` (8), `ai_usage` (11), `profiles.handle` (10), `media_features.review_summary` (10), `media_features.ai_summary` + `ai_summary_model_version_id` (11), `creators.bio_summary`, `series.description_summary` (7); enum values `ReasonType::PopularWithFollowed` (10) and `ModelKind::Llm` (11); optional discussions tables (10, lowest priority) | various | Each backs a spec requirement A6 did not name a table or column for | adopt |
| D-26 | All pages except landing/auth sit behind `auth` + `verified`. "Guest-safe" queries means user-independent, not publicly reachable. | §8 | Matches the starter kit and Phase 1 as built | adopt |
| D-27 | The spec is not copied into the repo (public repo); plans reference its absolute local path | Q13 | Spec is private | adopt |
| D-28 | Interpretations of spec ambiguities: ε-greedy exploration = one fixed slot in ten on "Recommended For You" plus a randomized pool for "Discover Something New"; opted-out users get cold-start shelves only and no `recommendations` rows; comics use Bookflix-defined section codes `741.52`–`741.59` under `741.5` (Q1); admin "Users" monitoring (§43) is a read-only list in Phase 2 (task 2.10); extra ML job names `embed-media`, `train-dewey-classifier`, `train-ltr`, `score-ltr` | §32, §41, §43, §60 | Spec leaves these open | adopt |
| D-18 | Headless widgets come from `@alpinejs/ui` instead of being hand-built | (user: open source) | Alpine's own accessible implementations (combobox, tabs, dialog, popover, …); MIT; same version as Livewire's Alpine | adopt |

## A4. Dependency and folder approvals

| Addition | Phase | Purpose | Notes |
|---|---|---|---|
| `league/flysystem-aws-s3-v3` | deploy setup (deferred from Phase 1) | S3/R2 disk | Required by Laravel's `s3` driver. Until then `FILESYSTEM_DISK=local`; Python artifact storage uses its own boto3/`ARTIFACT_DIR` |
| `pestphp/pest-plugin-browser` + `playwright` (npm, dev) | 1 (first browser test in 4) | Browser/E2E tests (D-5) | — |
| `laravel/nightwatch` (optional) | deploy setup (deferred) | Error tracking and monitoring (§58) | Alternatives: Sentry, or logs only (Q12) |
| `laravel/horizon` | 2 | Queue monitoring (§4) | Needs Redis and the `phpredis` extension on hosts |
| `laravel/scout` | 4 | Search abstraction (§4), `database` engine | Lets a future Meilisearch swap avoid app changes |
| `motion` (npm) | 4 | Animation (D-16) | Pin the latest 12.x at install time. Free core only. |
| `foliate-js`, `pdfjs-dist` (npm) | 9 | EPUB and PDF readers | foliate-js (MIT) replaces epub.js, which is no longer maintained (C6). CBZ is handled with PHP's `ZipArchive`; CBR see Q11 |
| `embla-carousel` (npm, optional) | 4 | Hero/shelf carousel physics | Only if scroll-snap + Motion feels flat (Q16) |
| `chart.js` (npm, optional) | 6/8 | Admin metric charts | Only when time-series charts are needed; tables first |
| `@alpinejs/ui` (npm, MIT) | 4 | Headless accessible combobox, listbox, tabs, dialog, disclosure, menu, popover, radio, switch | Pin to Livewire's bundled Alpine version (3.17.5 today); needs Livewire's ESM bundling (C6) |
| `nouislider` (npm, MIT) | 4 | Two-handle range filters (year, length) | — |
| `tom-select` (npm, Apache-2.0, fallback) | 2/4 | Tagging and remote-search selects | Only if the `@alpinejs/ui` combobox can't handle it |
| `easymde` (npm, MIT, optional) | 10 | Markdown review editor | Textarea first |
| Inter font (OFL-1.1, optional) | 1 | Self-hosted font with a real 900 weight (D-17) | — |
| **Rule** | all | **Open-source licenses only** (MIT, Apache-2.0, ISC, OFL). No paid libraries | C6 |
| `laravel/ai` **or** direct Anthropic HTTP | 11 | LLM features | Decide at Phase 11 (claude-api skill) |
| Python (`ml/pyproject.toml`) | 1+ | fastapi, uvicorn, pydantic, pydantic-settings · (P6) scikit-learn, numpy, scipy, pandas, implicit, psycopg[binary], pgvector, joblib, boto3 · (P7) sentence-transformers (CPU torch) · (P8) lightgbm · dev: pytest, httpx, ruff, mypy | LightFM is **skipped**: unmaintained and fails to build on current Python. The spec only asks for it "where useful". |
| Folder `ml/` (top level) | 1 | Python service | New base folder; needs approval |
| Folder `docs/` (top level) | now | Plans and spec copy | Created by this plan |

Not needed: a pgvector PHP package (built into Laravel 13), a roles/permissions package (an `is_admin` flag plus a Gate is enough), an image-processing library (avatars are stored at ≤ 2 MB and sized with CSS; marked as a `ponytail:` shortcut).

## A5. Folder and project structure

```text
app/
  Actions/            existing Fortify actions; add Library/, Ratings/ single-purpose actions as needed
  Catalog/
    Providers/        MetadataProvider contract + OpenLibrary/, AniList/, MangaDex/, Metron/, Vndb/, Taddy/
    Data/             ProviderRecord, NormalizedMedia, NormalizedCreator (readonly DTOs)
    Ingestion/        IngestionPipeline, RecordValidator, TaxonomyMapper, Deduplicator, Enricher
  Classification/
    ClassificationService.php
    Dewey/            DeweyHierarchy, DeweyClassifier, DeweyMapper, DeweyValidator, DeweyResolver   (§49)
  Discovery/
    Search/           HybridSearch, QueryParser, SearchFilters
    Recommendations/  CandidateSource contract + Sources/, HybridRanker, RankingWeights, Diversifier,
                      ShelfBuilder, Explanation, InteractionRecorder
  Ml/                 MlClient, MlServiceUnavailable, response DTOs
  Reader/             (Phase 9) ContentAccessGate, format handlers
  Enums/              MediaFamily, ReadingMode, AgeRating, PublicationStatus, CreatorRole, ReadingStatus,
                      ReadingLength, Visibility, InteractionEvent, ClassificationType, ClassificationMethod,
                      ReviewStatus, ModelKind, ModelStatus, AccessType, ContentFormat, ReasonType
  Models/  Jobs/  Policies/  Console/Commands/  Http/Middleware/  Livewire/Actions/ (existing)
config/bookflix.php    tunables: languages, retention days, thresholds, interaction/hybrid weight seeds
database/data/         dewey_classes.csv, taxonomy mapping CSVs
resources/js/          app.js, motion.js (Alpine directives over Motion; Phase 4)
resources/views/
  components/          cover-tile, shelf, hero, story-info, rating-input, reading-progress,
                       dewey-breadcrumb, accordion (<details>), bottom-nav, cta
  pages/               home/ discover/ search/ stories/ series/ explore/ library/ collections/
                       history/ profile/ settings/ (existing) admin/ reader/ onboarding/
ml/
  pyproject.toml  uv.lock  Dockerfile  .env.example
  app/            main.py, settings.py, auth.py, routers/
  bookflix_ml/    preprocess.py, lsa.py, summarize.py, embeddings.py, item_cf.py, als.py,
                  dewey.py, evaluate.py, registry.py, db.py
  tests/
tests/
  Feature/<Area>/  Unit/<Area>/  Browser/
  Fixtures/providers/<provider>/*.json    recorded once from real APIs, no personal data
  Fixtures/ml-contracts/*.json            one source of truth for both Pest (Http::fake) and pytest
docs/superpowers/{specs,plans}/
```

## A6. Database schema (§7, §9, §10, §13, §36–§46, §63)

Notation: `column type [null] [default]`. Every table has `timestamps` unless noted. Every foreign key has an index and `cascadeOnDelete` from its owner (`user_id` → users, `media_id` → media) unless noted.

**Extensions (Phase 1):** `vector`, `pg_trgm`, `unaccent`.

**Identity and preferences (Phase 1; preferences in Phase 5)**
- `users` (existing) + `is_admin bool default false`, `deactivated_at timestamp null`.
- `profiles` (Phase 10 adds `handle string(32) null unique`, D-25): `user_id` unique · `avatar_path string null` · `bio text null` (≤ 1000) · `preferred_languages jsonb default []` (ISO 639-1) · `reading_length` enum(short, medium, long, any) default any · `profile_visibility` enum(private, public) default private · `library_visibility` same · `personalization_enabled bool default true` · `onboarded_at timestamp null`.
- `user_preferences`: `user_id` · `morphs(preferable)` (Genre, Theme, MediaType, Creator) · `weight smallint default 1` · unique(`user_id`, `preferable_type`, `preferable_id`). Backs favorite genres, themes, media types and authors (§8, §41).

**Catalog (Phase 2)**
- `media_types`: `slug` unique · `name` · `family` enum(prose, sequential_art, illustrated, interactive) · `reading_mode` enum(reflowable, paged, vertical_scroll, interactive). Seeded with the 10 types from §2.
- `media`: `slug` unique · `media_type_id` · `title` · `alternative_titles jsonb default []` · `description text null` · `cover_url text null` · `cover_path string null` · `published_on date null` · `publication_year smallint null` · `language string(8) null` · `country_of_origin char(2) null` · `volume_number string(16) null` · `chapter_number string(16) null` · `issue_number string(16) null` · `format string null` · `age_rating` enum(all, teen, mature, adult, unknown) · `content_warnings jsonb default []` · `status` enum(upcoming, ongoing, completed, hiatus, cancelled, unknown) · `page_count int null` · `chapter_count int null` · `average_rating decimal(4,2) null` · `ratings_count int default 0` · `search_text text` (denormalized: titles, creators, series, characters, genres, publisher, keywords) · `locked_fields jsonb default []` (fields an admin edited, which sync must not overwrite) · `merged_into_id` bigint null FK → `media.id` (D-19) · `softDeletes`.
  Indexes: GIN trigram on `title`; a GIN full-text index `media_search_fts` on `to_tsvector('simple', search_text)`, with `search_text` stored lowercase plus an accent-folded copy; GIN trigram indexes on `immutable_unaccent(lower(title))` and `immutable_unaccent(lower(alternative_titles::text))` (an immutable wrapper around `unaccent`). Task 4.1's `EXPLAIN` test proves the Scout query uses the FTS index; btree indexes on `media_type_id`, `publication_year`, `status`.
- `creators`: `name` · `slug` unique · `alternative_names jsonb` · `bio text null`. `media_creators`: `media_id`, `creator_id`, `role` enum (D-1), `position smallint`; primary key (media, creator, role).
- `publishers` (`name`, `slug`). `media_publishers`: `media_id`, `publisher_id`, `region string null`.
- `series`: `title`, `slug`, `description`, `status`. `media_series`: `media_id`, `series_id`, `position decimal(8,2) null` (allows 1.5), `label string null`.
- `media_relations`: `media_id`, `related_media_id`, `relation` enum(sequel, prequel, adaptation, spin_off, side_story, alternate_version, same_universe); primary key on all three.
- `genres`, `themes`, `tags`, `characters`: `name`, `slug` unique. Pivots `media_genres`, `media_themes`, `media_tags`, `media_characters` (+ `role` enum(main, supporting)).
- `external_sources`: `key` unique · `name` · `base_url` · `enabled bool` · `attribution text` · `cover_policy` enum(hotlink, cache, none) · `display_policy jsonb` (which provider fields we may show) · `sync_cursor jsonb null` · `last_synced_at null`.
- `media_external_ids`: `media_id` · `external_source_id` · `external_id` · `source_url null` · `payload_hash char(64)` · `imported_at` · `last_synced_at`; unique(`external_source_id`, `external_id`) (§5).
- `ingestion_runs`: `external_source_id` · `mode` enum(full, incremental, single) · `status` enum(running, succeeded, partial, failed) · `started_at`, `finished_at null` · `counts jsonb` {fetched, created, updated, merged, skipped, failed} · `error text null`.
- `duplicate_candidates`: `media_id`, `duplicate_media_id`, `score real`, `status` enum(pending, merged, dismissed), `resolved_by null`.
- `content_sources` (designed now, built in Phase 9; §6): `media_id` · `access_type` enum(public_domain, licensed, user_owned, external_link) · `format` enum(epub, pdf, cbz, cbr, images, url) · `storage_path null` · `url null` · `license text` · `rights_holder null` · `owner_user_id null` · `verified_by null` · `verified_at null`.

**Classification (Phase 3; §13)**
- `dewey_classes`: `code string(16)` unique ('800', '813', '741.5') · `name` · `description text null` · `parent_id null` · `level smallint` · `path string` ('800/810/813'), with a `varchar_pattern_ops` index for subtree `LIKE 'path/%'` queries.
- `media_dewey_classes`: `media_id` · `dewey_class_id` · `classification_type` enum(primary, secondary) · `confidence decimal(4,3)` · `source string` (admin | open_library | rules | dewey-classifier) · `classification_method` enum(manual, automatic, external) · `model_version_id null` · `review_status` enum(pending, accepted, rejected) · `reviewed_by null` · `reviewed_at null`. Constraints: unique(`media_id`, `dewey_class_id`), plus a **partial unique index on `media_id` WHERE classification_type = 'primary' AND review_status <> 'rejected'**.

**Library and signals (Phase 5)**
- `user_library`: `user_id` · `media_id` · `status` enum(want_to_read, reading, completed, paused, abandoned) · `favorited_at null` · `added_at` · `started_at null` · `completed_at null`; unique(user, media); index(user, status).
- `reading_progress`: primary key (`user_id`, `media_id`) · `content_source_id null` · `locator jsonb null` (EPUB CFI or page index) · `page int null` · `chapter string null` · `percent decimal(5,2)` (0–100) · `last_read_at`.
- `reading_history`: `user_id` · `media_id` · `started_at` · `ended_at null` · `seconds int` · `from_percent` · `to_percent` · `chapters_completed smallint`.
- `ratings`: primary key (`user_id`, `media_id`) · `score smallint` (CHECK 1–10).
- `collections`: `user_id` · `name` · `slug` · `description null` · `visibility` enum · `is_editorial bool default false` (editorial collections for cold start, §33); unique(user, slug). `collection_items`: `collection_id`, `media_id`, `position int`, `note null`.
- `user_interactions` (no updated_at): `id bigint identity` · `user_id` · `media_id null` · `event` enum (the 12 events in §44) · `value real null` · `context jsonb` {surface, shelf, position, recommendation_id, search_query_id} · `occurred_at`; indexes (user, occurred_at) and (media, event, occurred_at). `ponytail:` no partitioning; add monthly partitions past ~50M rows.
- `search_queries` (**created in Phase 4**, D-22): `user_id null` · `session_hash char(64)` · `query` · `normalized` · `filters jsonb` · `intent jsonb null` · `results_count int` · `previous_query_id null` (refinement chains, §46) · `created_at`.

**Intelligence (Phases 6–8; §22–§30, §63)**
- `model_versions`: `name` · `version` · `kind` enum(lsa, embedding, item_cf, als, ltr, dewey_classifier, hybrid_weights, interaction_weights, llm) · `status` enum(training, candidate, active, retired) · `trained_at null` · `dataset_ref text null` · `parameters jsonb` · `metrics jsonb` · `artifact_uri null`; unique(name, version); partial unique(name) WHERE status = 'active'.
- `media_features`: primary key (media, model_version) · `top_terms jsonb` · `summary text null` · `computed_at` (Phase 10 adds `review_summary`, `review_summary_count`; Phase 11 adds `ai_summary`, `ai_summary_model_version_id`).
- `media_lsa_vectors`: primary key (media, model_version) · `vector vector(200)`, HNSW index.
- `media_embeddings`: primary key (media, model_version) · `embedding vector(384)`, HNSW index. A new model with different dimensions gets a new column or table through a migration.
- `media_similarities`: `media_id` · `similar_media_id` · `method` enum(lsa, embedding, item_cf, als, dewey) · `score real` · `model_version_id`; primary key (media, method, similar); top 50 per method.
- `media_popularity` (materialized view, refreshed hourly with `CONCURRENTLY`): media_id, interactions_7d, interactions_30d, trending_score, popularity_percentile.
- `recommendations`: `user_id` · `media_id` · `shelf` · `score real` · `reason_type` enum(because_you_read, similar_themes, popular_with_similar_readers, matches_interest, trending, editorial, new_release, exploration, popular_with_followed) · `reason_media_id null` · `reason_payload jsonb` (always includes `features`, the eight ranker feature values, for Phase 8) · `model_version_id` · `generated_at`. Stores what was shown, for explanations and evaluation.
- `recommendation_events`: `recommendation_id` · `event` enum(impression, click, dismiss, library_add) · `occurred_at`.

**Changed since A6 was written (2026-10-27 to 2026-10-29):**
- `media.average_rating` is the blended **0–5** average of every source's rating, weighted by ratings count; `media.ratings_count` is the sum. Both come from `media_metrics` (`media_id`, `external_source_id`, `metric`, `value`, `observed_at`; unique per media, source and metric). `media.popularity_score` is the weighted percentile blend of the signals (`bookflix:score-popularity`).
- `media.age_rating` is enum(all, children, teen, mature_teen, mature, adult, unknown); the stricter rating wins on merge.
- `genres` is a tree: `parent_id`, `group`, `sort`, `is_mature`, `description`, plus `genre_secondary_parents`. New facets `tropes`, `demographics`, `content_warnings` with pivots; `media_content_warnings.status` is enum(present, absent, unknown, disputed).
- `profiles.show_mature bool default false`, `profiles.adult_confirmed_at timestamp null` (the mature access gate, D-35).

**Added by phases (D-25):** `ml_job_runs` (Phase 6: name, run_id, status queued|running|succeeded|failed, params, metrics, error, started_at, finished_at) · `ltr_scores` (Phase 8: user_id, media_id, score, model_version_id, generated_at) · `ai_usage` (Phase 11: user_id null, feature, model, input/output/cached tokens) · `creators.bio_summary`, `series.description_summary` (Phase 7).

**Social (Phase 10)**: `reviews` (user, media, body, has_spoilers, status enum(published, hidden), softDeletes, unique(user, media)) · `reports` (reporter, `morphs(reportable)`, reason, details, status enum(open, actioned, dismissed), handled_by, handled_at) · `follows` (follower_id, followed_id, primary key on both).

## A7. PHP interfaces and contracts (§56 item 7)

**Models** (`app/Models`): User, Profile, UserPreference, MediaType, Media, Creator, Publisher, Series, Genre, Theme, Tag, Character, ExternalSource, MediaExternalId, IngestionRun, DuplicateCandidate, ContentSource, DeweyClass, MediaDeweyClass, LibraryEntry (`user_library`), ReadingProgress, ReadingSession (`reading_history`), Rating, Collection, UserInteraction, SearchQuery, ModelVersion, MediaSimilarity, Recommendation, RecommendationEvent, Review, Report. Each model has a factory with useful states (`Media::factory()->manga()`, `->classified('813')`, `User::factory()->admin()`, `->deactivated()`).

**DTOs** (readonly classes, no data package):
```php
final readonly class ProviderRecord { public function __construct(public string $provider, public string $externalId, public array $payload, public ?string $sourceUrl, public CarbonImmutable $fetchedAt) {} }
final readonly class NormalizedMedia { /* title, mediaTypeSlug, alternativeTitles, description, creators (list<NormalizedCreator>), publishers, series (?array{title, position}), genres, themes, tags, characters, isbns, crossIds (array<string,string>), publicationYear, publishedOn, language, countryOfOrigin, ageRating, contentWarnings, status, pageCount, chapterCount, coverUrl, externalDeweyCode, sourceUrl, isAdult */ }
final readonly class SearchFilters { /* mediaTypes, genres, themes, languages, yearFrom, yearTo, minRating, statuses, length (short|medium|long), creatorIds, publisherIds, deweyCode */ }
final readonly class QueryIntent { /* mediaTypes, genres, themes, deweyCodes, ?lengthHint, semanticText */ }
final readonly class ClassificationCandidate { public function __construct(public string $code, public float $confidence, public ClassificationMethod $method, public string $source, public ?int $modelVersionId = null) {} }
final readonly class Candidate { public function __construct(public int $mediaId, public string $source, public float $score, public ReasonType $reason, public ?int $reasonMediaId = null) {} }
final readonly class RankedItem { /* mediaId, score, features (array<string,float>), explanation (Explanation) */ }
```

**Contracts**: interfaces only where at least two implementations exist from day one.
```php
interface MetadataProvider
{
    public function key(): string;
    /** @return LazyCollection<int, ProviderRecord> */
    public function changedSince(?CarbonImmutable $since): LazyCollection;
    public function find(string $externalId): ?ProviderRecord;
    public function normalize(ProviderRecord $record): NormalizedMedia;
}

interface CandidateSource
{
    public function key(): string;
    /** @return Collection<int, Candidate> */
    public function candidatesFor(User $user, int $limit): Collection;
}
```

**Services** (concrete classes resolved from the container):
- `IngestionPipeline::import(ProviderRecord $record, MetadataProvider $provider): ImportOutcome` (enum Created | Updated | Merged | Skipped)
- `Deduplicator::match(NormalizedMedia $media): ?Media` · `Deduplicator::merge(Media $winner, Media $loser): void`
- `ClassificationService::classify(Media $media): Collection<ClassificationCandidate>` · `assign(Media, DeweyClass, ClassificationType, ClassificationCandidate): MediaDeweyClass` · `accept(MediaDeweyClass, User): void` · `reject(MediaDeweyClass, User): void` · `replace(MediaDeweyClass, DeweyClass, User): MediaDeweyClass`
- `DeweyHierarchy::roots()`, `children(DeweyClass)`, `ancestors(DeweyClass)`, `findByCode(string)`, `similarity(DeweyClass $a, DeweyClass $b): float` (depth of lowest common ancestor ÷ the larger depth)
- `DeweyResolver::resolve(string $externalCode): ?DeweyClass` (deepest seeded prefix: `813.54` → `813`)
- `DeweyMapper::map(Media $media): list<ClassificationCandidate>` (rule-based)
- `DeweyValidator::filter(list<ClassificationCandidate> $candidates): list<ClassificationCandidate>` (≤ 1 primary, ≤ 3 secondary, confidence ≥ configured minimum; §15 "avoid excessive")
- `DeweyClassifier::predict(Media $media): list<ClassificationCandidate>` (calls ML; Phase 7)
- `HybridSearch::search(string $query, SearchFilters $filters, ?User $user, int $perPage = 24): LengthAwarePaginator`
- `HybridRanker::rank(User $user, Collection $candidates, RankingWeights $weights): Collection<RankedItem>`
- `Diversifier::rerank(Collection $ranked, float $lambda = 0.7, int $limit = 20): Collection` (MMR)
- `ShelfBuilder::forHome(User $user): Collection<Shelf>`
- `InteractionRecorder::record(User $user, InteractionEvent $event, ?Media $media = null, array $context = [], ?float $value = null): void` (does nothing when personalization is off; otherwise dispatches a queued insert)
- `MlClient` (see A8)

## A8. Python service interfaces (§48; §56 item 8)

All `/v1/*` routes require `Authorization: Bearer ${ML_SERVICE_TOKEN}` (checked with constant-time comparison). Laravel forwards `X-Request-Id`. JSON in and out. FastAPI docs/OpenAPI endpoints are **off** unless `ML_ENABLE_DOCS=true`. `ML_SERVICE_TOKEN` must be at least 32 characters; `MlClient` throws `MlServiceUnavailable` (and `health()` is false) when it is empty. (`/v1/query/parse` was dropped, D-24.) Contract fixtures live in `tests/Fixtures/ml-contracts/`.

| Endpoint | Phase | Request | Response | Laravel caller |
|---|---|---|---|---|
| `GET /health` (public) | 1 | — | `{"status":"ok"}` | `MlClient::health(): bool` |
| `GET /v1/models` | 1 | — | `{"embedding":{"name":"intfloat/multilingual-e5-small","dims":384}}` (Phase 6+ adds the active model versions) | `MlClient::models(): array` |
| `POST /v1/summarize` | 7 | `{text, max_sentences=3}` | `{summary, sentence_indices, method:"lsa", model_version}` | `MlClient::summarize(string $text, int $maxSentences = 3): SummaryResult` |
| `POST /v1/embed` | 7 | `{texts:[str], kind:"query"\|"passage"}` | `{model_version, dims, vectors:[[float]]}` | `MlClient::embed(array $texts, string $kind): EmbeddingResult` |
| `POST /v1/classify/dewey` | 7 | `{media_id, text, genres, themes, subjects, external_code}` | `{candidates:[{code, confidence}], model_version}` | `MlClient::classifyDewey(Media $media): list<ClassificationCandidate>` |
| `POST /v1/jobs/{name}` | 6 | `{params}`; name ∈ train-lsa, train-item-cf, train-als, build-similarities, refresh-popularity, evaluate, embed-media (7), train-dewey-classifier (7), train-ltr, score-ltr (8) | `{run_id}` (202) | `MlClient::startJob(string $name, array $params = []): string` |
| `GET /v1/jobs/{run_id}` | 6 | — | `{status, started_at, finished_at, metrics, error}` | `MlClient::job(string $runId): array` |

Batch jobs read interactions and catalog text **directly from Postgres** through `DB_DIRECT_URL` (not the pooler), write to `media_lsa_vectors`, `media_embeddings`, `media_similarities` and `model_versions`, and store artifacts (vectorizer, SVD, item-CF matrices; joblib) in S3 under `ml-artifacts/<name>/<version>/`. They only load artifacts they wrote themselves (pickle safety).

## A9. Ingestion architecture and external API integration points (§5, §42; §56 items 5 and 12)

```text
Scheduler → SyncProvider(job, per source) ──► adapter.changedSince(cursor) (LazyCollection, paged)
   └─ per record → ImportMediaRecord(job; RateLimited per provider; backoff [10, 60, 300]s; tries 5)
        Validate (RecordValidator: title, type, external id; skip isAdult = true, Q15)
        Normalize (adapter.normalize → NormalizedMedia)
        Transform (TaxonomyMapper: provider genres/tags → Bookflix genres/themes/tags via database/data CSV)
        Deduplicate (Deduplicator: exact ids/ISBN/cross ids → fuzzy trigram band)
        Enrich (Enricher: field precedence admin-locked > existing > provider priority; never touch locked_fields)
        Store (one DB transaction; upsert on (external_source_id, external_id); payload_hash short-circuit)
        → dispatch ClassifyMedia (Phase 3) → GenerateMediaFeatures (Phase 6/7)
   → IngestionRun counts / status; failures logged with request/run context (§58)
```

Dedup bands: an exact match on provider ID, ISBN-13 or a provider cross-ID (AniList ↔ MAL ↔ MangaDex links) merges automatically. Otherwise, normalized-title trigram similarity ≥ 0.90 **plus** a shared creator **plus** a publication year within ±1 merges automatically. Any other same-family match with similarity ≥ 0.75 (including ≥ 0.90 without the creator/year match) creates a `duplicate_candidates` row for admin review (D-21). Merges are reversible: the loser is soft-deleted with `merged_into_id` set and its slug 301-redirects (D-19). ISBN-13s are matched as `media_external_ids` under the `isbn` source (D-20).

| Provider | Covers | Auth | Change detection | Phase |
|---|---|---|---|---|
| Open Library | novels, picture books, graphic novels, some light novels; often has a DDC number | none; descriptive User-Agent with contact | recent-changes feed + stale re-sync | 2 |
| AniList (GraphQL) | manga, manhwa, manhua, light novels (format + `countryOfOrigin`: JP → manga, KR → manhwa, CN/TW → manhua, NOVEL → light novel) | none for public queries | stale re-sync (check for an updated-at sort at implementation) | 2 |
| MangaDex | manga, manhwa, webcomics, **metadata only, never chapter images** | none for public reads; follow their attribution terms | `updatedAt` ordering | 2b |
| Metron | comic books | account credentials | modified filter | 2b |
| VNDB (Kana API) | visual novels | optional token | stale re-sync | 2b |
| Taddy | webcomics | API key + user id | stale re-sync | 2b (optional) |
| Project Gutenberg / Standard Ebooks | **public-domain files** for the reader | none; use the official catalog feeds or mirrors, never scrape | catalog diff | 9 |

Before an adapter is enabled, record each provider's current rate limits, terms, attribution text, and cover/description display rights in `external_sources`. Don't assume redistribution rights (§5).

## A10. Dewey classification architecture (§11–§19, §49; §56 item 11)

- **Hierarchy seed:** `database/data/dewey_classes.csv`, written in-house. It covers the 10 main classes, all 100 divisions, and the sections that matter for stories: 813, 823, 833, 843, 853, 863, 891.7, 895.1, 895.6, 895.7, and the 741.5 comics subtree by country (Q1 default adopted: Bookflix-defined section codes 741.52 Japanese, 741.53 Korean, 741.54 Chinese, 741.55 American/English, 741.56 French/Belgian, 741.59 other). Class names are Bookflix's own wording, and the UI never says "DDC" (§11).
- **Resolution order and confidence:**
  1. manual (admin) → 1.0
  2. external (provider DDC through `DeweyResolver`) → 0.95
  3. rules (`DeweyMapper`: media family + fiction genre + language/country → literature or comics class; subject keywords → secondary classes) → 0.70–0.85
  4. model (`DeweyClassifier`, Phase 7: kNN over embeddings of accepted classifications, ensembled with logistic regression on LSA vectors; calibrated probability)
- **Policy:** every automatic classification starts as `pending`. The public site shows only `accepted` rows, plus `pending` rows with confidence ≥ `config('bookflix.classification.public_min_confidence')` (default 0.80). Rejected rows are never shown. The review queue is sorted by lowest confidence first (§19).
- **Navigation (§16):** `/explore` shows the 10 classes with story counts. `/explore/{code}` shows the children, a breadcrumb, and the stories in that subtree (paginated). Counts are cached and refreshed after classification changes.
- **Shelves (§17):** built on the fly by grouping stories (the catalog in Phase 3, the user's library in Phase 5) by top-level class and division.
- **Similarity for recommendations:** `DeweyHierarchy::similarity()` (A7).

## A11. NLP architecture (§23–§26, §63; §56 item 10)

- **Preprocessing:** NFKC normalization, strip HTML, lowercase, English stop words. For CJK text, character 2–3-grams instead of word tokens.
- **TF-IDF:** `TfidfVectorizer(sublinear_tf=True, min_df=2, max_df=0.6, ngram_range=(1, 2))` over title, alternative titles, description, genres, themes, tags and characters, with fields weighted by repetition (title ×3, genres/themes ×2).
- **LSA:** `TruncatedSVD(n_components=200, random_state=42)` → L2-normalize → `media_lsa_vectors`. Retrained weekly; the model version records the vocabulary size and explained variance.
- **Summarization (§25):** extractive LSA. Split sentences → TF-IDF sentence × term matrix → SVD → score each sentence by its weighted singular vectors (Steinberger & Ježek) → top-k sentences returned **in original order**. Deterministic (`random_state` fixed). It sits behind a `Summarizer` strategy so an abstractive LLM summarizer can be added later.
- **Embeddings:** e5-small (D-11). Passage text is `"passage: " + title + description + genres + themes + tags + characters`; queries are prefixed `"query: "`. Stored in `media_embeddings` with an HNSW index.
- **Versioning:** every artifact links to a `model_versions` row (name, version, trained_at, dataset_ref, parameters, metrics, status) (§63).

## A12. Recommendation architecture (§22–§35, §45; §56 item 9)

**Offline candidate generation** (Python, scheduled): LSA and embedding neighbors, item-item collaborative filtering (`implicit` cosine over a BM25-weighted user × item matrix), and ALS (Phase 8). Each writes the top 50 neighbors per item into `media_similarities`. `media_popularity` is refreshed hourly.

**Implicit feedback weights** (D-15 seed; tunable): view 1 · click 1 · recommendation_click 1.5 · library_add 3 · reading_start 4 · reading_progress 0.5 per 10% · reading_complete 8 · favorite 6 · rating (score − 5.5) × 1.2 · review 4 · abandon −4. Interactions decay with a 90-day half-life. Deactivated and opted-out users are excluded.

**Online ranking** (Laravel, per request, cached 10 minutes per user, cache cleared on library or rating changes):
`score = Σ wᵢ · fᵢ`, where every feature is normalized to [0, 1]:

| Feature | Definition |
|---|---|
| content_similarity | max over the user's seed items of (LSA or embedding neighbor score × seed strength) |
| collaborative_score | sum of item-CF neighbor scores over seeds, normalized |
| dewey_similarity | max `DeweyHierarchy::similarity` to a seed's primary class |
| popularity | `popularity_percentile` |
| freshness | `exp(−age_days / 180)` |
| personal_preference | genre/theme/type/creator affinity from preferences + behavior |
| context | 0 in the MVP (placeholder weight) |
| novelty | `1 − popularity_percentile` |
| diversity | applied by `Diversifier` (MMR, λ = 0.7) after scoring, not as a weight |

Weights come from the active `model_versions` row named `hybrid-weights`. The seed values in `config/bookflix.php` are content 0.30, collaborative 0.25, dewey 0.10, popularity 0.10, freshness 0.05, preference 0.15, novelty 0.05, context 0. LTR replaces these in Phase 8 (§29–§30).

**Cold start (§33):** an onboarding step (5.8) collects media types, genres and at least 3 seed stories. With fewer than 5 strong interactions, shelves lean on popular, trending, editorial, new-release and preference-filtered popularity.

**Exploration (§32):** the ε = 0.10 budget is realized as one fixed slot in ten (positions 10, 20) on "Recommended For You" plus a randomized pool for the "Discover Something New" shelf, both drawn from well-rated stories outside the user's top genres (D-28). Opted-out users get cold-start shelves only and no `recommendations` rows. Bandit methods only arrive in Phase 8, and only if the data justifies them.

**Explanations (§34):** each candidate carries `reason_type` + `reason_media_id`, rendered from copy templates: "Because you read {title}", "Similar themes to {title}", "Popular with readers who enjoyed {title}", "Matches your interest in {genre}".

**Homepage shelves (§35, order per §52):**

| Order | Shelf | Source |
|---|---|---|
| 1 | Continue Reading | `user_library` status = reading, ordered by `reading_progress.last_read_at` |
| 2 | Recommended For You | all CandidateSources → HybridRanker → Diversifier |
| 3 | Because You Read {X} | neighbors of the most recent completed or highly rated seed |
| 4 | New Releases | recent `published_on`, filtered by preferred types/languages |
| 5 | Similar To {Y} | embedding/LSA neighbors of a favorite |
| 6 | Trending Now (**the only gradient shelf**) | `media_popularity.trending_score` |
| 7 | Popular in Your Genres | popularity filtered by the top affinity genres |
| 8 | Discover Something New | exploration |
| 9 | Your Collections | the user's collections |
| 10 | Explore by Subject | Dewey roots with counts |

**Evaluation (§45):** offline time-split leave-last-out evaluation. Metrics: P@10, R@10, NDCG@10, MAP@10, HR@10, catalog coverage, intra-list diversity, novelty (mean self-information). Stored in `model_versions.metrics`. A candidate model is promoted only if NDCG@10 drops by no more than 2% and coverage doesn't fall. Online CTR comes from `recommendation_events`.

## A13. Search architecture (§20–§21, §46–§47)

1. **Keyword/full-text leg:** Scout `database` engine with full-text over `search_text`, plus a `pg_trgm` similarity fallback on `title` and `alternative_titles` (`unaccent` on both sides).
2. **Subject leg:** query terms matching Dewey class names or codes → subtree boost or filter.
3. **Semantic leg (Phase 7):** (a `SearchLeg` implementation like the keyword and subject legs, Phase 4) query embedding → `whereVectorSimilarTo` on `media_embeddings` (active version).
4. **Fusion:** RRF (D-12) → personalization boost (affinity × 0.1) → filters applied in SQL *before* fusion.
5. **Natural-language intent (Phase 7, then LLM in Phase 11):** `QueryParser` builds media type, genre, theme, Dewey and length hints from the catalog vocabulary. "Dark fantasy manga with political intrigue" → `{media_types:[manga], genres:[fantasy], themes:[politics], semantic_text:"dark fantasy political intrigue"}`.
6. **Analytics:** one `search_queries` row per search. Clicks are recorded in `user_interactions.context.search_query_id`. The admin page shows zero-result queries and refinement chains.

## A14. Environment variables (§56 item 6, §59)

| Variable | Where | Example / note |
|---|---|---|
| `APP_NAME` | app | `Bookflix` |
| `APP_URL` | app | `https://bookflix.test` locally (Herd) |
| `DB_CONNECTION` | app | `pgsql` |
| `DB_URL` | app | Neon **pooled** URL, `?sslmode=require` |
| `DB_DIRECT_URL` | deploy + ml | Neon direct URL. Migrations run with `DB_URL=$DB_DIRECT_URL php artisan migrate --force` |
| `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` | local, CI | local Postgres with pgvector |
| `SESSION_DRIVER` | app | `database` |
| `CACHE_STORE`, `QUEUE_CONNECTION` | app | `database` → `redis` from Phase 2 |
| `REDIS_URL` (or `REDIS_HOST`/`PORT`/`PASSWORD`), `REDIS_CLIENT=phpredis` | app | Phase 2 |
| `FILESYSTEM_DISK` | app | `local` (default). Avatars use `config('bookflix.avatar_disk')` (default `public`); Livewire temporary uploads never use a public disk. `s3` arrives with deploy setup. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_URL`, `AWS_USE_PATH_STYLE_ENDPOINT` | app + ml | R2: region `auto`, endpoint `https://<account>.r2.cloudflarestorage.com` |
| `MAIL_MAILER` + `MAIL_*` | app | verification and reset emails |
| `SCOUT_DRIVER` | app | `database` (Phase 4) |
| `ML_SERVICE_URL`, `ML_SERVICE_TOKEN`, `ML_SERVICE_TIMEOUT`, `ML_ENABLE_DOCS` | app + ml | local URL `http://localhost:8001`; token ≥ 32 characters; timeout `5`; docs off unless `ML_ENABLE_DOCS=true` |
| `BOOKFLIX_PROVIDER_USER_AGENT` | app | `Bookflix/1.0 (+contact email)`; required by Open Library and MangaDex etiquette |
| `METRON_USERNAME`, `METRON_PASSWORD` | app | Phase 2b |
| `VNDB_API_TOKEN` (optional), `TADDY_API_KEY`, `TADDY_USER_ID` | app | Phase 2b |
| `BOOKFLIX_DEACTIVATED_RETENTION_DAYS` | app | `30` |
| `LOG_STACK`, `LOG_LEVEL` | app | `json` channel in production (task 1.9) |
| `NIGHTWATCH_TOKEN` | app | only if approved |
| `ANTHROPIC_API_KEY` | app | Phase 11 |
| `DATABASE_URL`, `EMBEDDING_MODEL`, `LSA_COMPONENTS`, `ARTIFACT_BUCKET`, `HF_HOME` | ml | `intfloat/multilingual-e5-small`, `200` |

## A15. Testing strategy and quality gates (§4, §57; §56 item 13)

| Layer | Tool | Scope |
|---|---|---|
| Unit | Pest | pure logic: scoring, MMR, Dewey resolver/mapper/validator, normalizers, RRF |
| Feature | Pest on **Postgres** | Livewire pages, HTTP, policies, jobs, commands |
| Integration | Pest + `Http::fake` with recorded provider fixtures | ingestion pipeline end to end, Laravel ↔ ML contracts |
| API | pytest + FastAPI TestClient | every ML endpoint; contract fixtures checked against real responses |
| Browser/E2E | Pest browser plugin (D-5) | smoke flows per phase + `assertNoAccessibilityIssues()` (check it's available at install time) |
| ML offline eval | pytest + `evaluate` job | metric thresholds on a fixed fixture dataset |

- `tests/Pest.php` turns on `Http::preventStrayRequests()` for every test, so no test touches the network.
- Gates for every task: `composer test` (Pint check → PHPStan L7 → Pest) and `cd ml && uv run ruff check . && uv run mypy . && uv run pytest`.
- Gates for every phase: browser suite, `/code-review`, `security-review`, `design:accessibility-review`, a performance check (no N+1 via `Model::preventLazyLoading()` outside production; query count on home ≤ 25), and docs (this plan's decision log plus the phase plan) (§57).
- Failing tests are fixed at the root cause, never skipped (§57).

## A16. Cross-cutting concerns

- **Observability (§58):** a request ID is set via `Context` and automatically carried into logs and queued jobs; JSON logs in production; `ingestion_runs`; Horizon (from Phase 2); model metrics in `model_versions`; an admin health page (ML `/health`, queue backlog, last sync per provider). Optional Nightwatch.
- **Security (§59):** Fortify rate limits (already present); `is_admin` not mass-assignable; `access-admin` Gate on `/admin`; security headers middleware (1.9); a Content-Security-Policy added in Phase 9 when the reader embeds content; signed short-lived URLs for files; uploads checked for MIME type and size; provider credentials only in the server environment; every Livewire action re-authorizes.
- **Privacy (§60):** `personalization_enabled` opt-out; data minimization (no IP storage in interactions; `search_queries.session_hash` is a salted hash); deactivation plus a 30-day purge; data export (5.7); analytics use aggregates; deactivated and opted-out users are excluded from training.
- **Performance (§61):** shelves are plain Blade components (Blaze is installed); lazy Livewire shelves; `loading="lazy"` covers with explicit `width`/`height`; cursor/length-aware pagination; candidates precomputed; homepage shelves cached for 10 minutes; HNSW indexes; Neon pooled connections.

## A17. MVP scope (§55; §56 item 14)

**In:** Phase 1, Phase 2 (Open Library + AniList; 2b adapters optional), Phase 3, Phase 4, Phase 5, Phase 6 (TF-IDF, LSA, item-CF, Dewey similarity, hybrid scoring, explanations, personalized homepage, baseline evaluation).

**Out until after the MVP:** embeddings and semantic search (Phase 7), ALS/LTR/bandits (8), the integrated reader (9; the MVP tracks progress manually), social features and reviews (10), AI features (11).

**MVP exit test:** a new user registers → onboards → searches "Berserk" → opens the detail page → adds it to the library → logs progress → rates it → browses 800 → 810 → 813 → sees a personalized homepage with "Because you read…" explanations. An admin syncs the providers, reviews pending classifications and merges a duplicate.

## A18. Technical risks (§56 item 15)

| # | Risk | Mitigation |
|---|---|---|
| R1 | Copyright: hosting content, cover art, and provider descriptions (often publisher blurbs or user-written text) | `content_sources` gate; per-provider `cover_policy` / `display_policy` reviewed before launch; attribution; hotlink provider-sanctioned cover URLs instead of copying |
| R2 | Trade dress and trademark: Netflix-like visuals, the "‑flix" name, Netflix Sans | Original logo; no Netflix assets; Bookflix's own layouts; font fallback stack; a legal check on the name before public launch |
| R3 | DDC is an OCLC trademark | "Dewey-inspired", in-house class names, never "DDC" |
| R4 | Provider terms, rate limits, API changes or shutdowns | Adapters behind `MetadataProvider`, recorded fixtures, rate-limited jobs, `ingestion_runs` alerts |
| R5 | Neon: scale-to-zero cold starts, pooler limits, long batch transactions | Minimum compute in production; Python uses the direct URL; batches commit in chunks |
| R6 | pgvector: HNSW build time and memory; recall drops with filtered search | One active model version per table; check Neon's pgvector version for iterative scans; tune `ef_search` |
| R7 | Sparse interactions make collaborative filtering weak early on | Content-based and Dewey carry the MVP; evaluation gates; cold-start shelves |
| R8 | Dedup false merges or misses | Conservative thresholds, review band, reversible merges, Review Focus #1 |
| R9 | CJK and multilingual text hurts TF-IDF | Character n-grams for CJK; multilingual embeddings in Phase 7 |
| R10 | **The token set fails WCAG AA** (C2): `#808080` on `#232323` = 4.0:1, red text on black = 4.4:1, `#4061e7` text on black = 4.1:1, `#414141` input border on black = 2.1:1 | Semantic companion tokens from the Figma reference (`#B3B3B3`, `#448EF4`, `#6D6D6E`) |
| R11 | Running two runtimes; torch makes the image 1–2 GB | CPU torch wheels, a small model; ONNX/fastembed if hosting costs bite |
| R12 | Livewire morphing clobbers inline styles that Motion set; animations stop working after `wire:navigate` | Animate through Alpine directives (they re-initialize on new DOM), stable `wire:key`, `wire:ignore` on purely animated wrappers, animate `transform`/`opacity` only |
| R13 | Heavy homepage (10 shelves × 20 tiles) | Blade shelves, lazy loading, caching, image sizes |
| R14 | Privacy obligations for behavioral data | Opt-out, minimization, retention, export |
| R15 | Scope: 11 phases | MVP-first; each phase planned and gated on its own |
| R16 | Figma community file licensing and Netflix artwork inside it | Check the community license on the file page (typically CC BY 4.0 → credit it on the DS cover); remove all posters, logos and Netflix Sans before use |
| R17 | `@alpinejs/ui` isn't documented on alpinejs.dev, so its API could change without notice | Pin it to Livewire's bundled Alpine version; implementers read `packages/ui/src`; per-component browser tests catch regressions on upgrade |
| R18 | Flux free is free to use but proprietary-licensed, which conflicts with a strict open-source requirement | Accepted: keep Flux (Q17). Components stay behind Bookflix Blade wrappers, so swapping Flux later touches only the wrappers |

## A19. Unresolved questions (§56 item 16) — defaults apply if unanswered

| # | Question | Default |
|---|---|---|
| Q1 | Where do comics, graphic novels and manga go in Dewey: 741.5 by country (what libraries do) or 8xx literature by origin? | Primary 741.5x by country, plus a secondary 8xx when the work is literary |
| Q2 | Keep 2FA and passkeys? | Keep (D-2) |
| Q3 | Dark-only UI? | Yes (D-4) |
| Q4 | Pest browser plugin or Dusk? | Pest browser (D-5) |
| Q5 | Can a deactivated user log back in during the retention window? | No, login is blocked; reactivation is out of scope |
| Q6 | Length of the retention window | 30 days |
| Q7 | Hosting: Laravel Cloud plus an external Neon project, or Cloud's managed Postgres? | Laravel Cloud app + Neon project (spec §4); confirm pgvector, `pg_trgm` and `unaccent` on the chosen plan |
| Q8 | ML service host | Docker on a CPU host (Fly.io, Railway or Render), 2 GB RAM |
| Q9 | MVP providers | Open Library + AniList |
| Q10 | Cover images: hotlink or cache? | Hotlink provider-sanctioned URLs until each provider's terms are reviewed |
| Q11 | CBR support (needs the non-free `unrar`) | Convert CBR to CBZ on upload with `unar` if available, otherwise CBZ only |
| Q12 | Error tracking | Nightwatch, deferred to deploy setup |
| Q13 | Copy the spec into the repo? | **No** (public repo); plans cite the absolute local path (D-27) |
| Q14 | Reviews in the MVP? | No, they come in Phase 10 per §54; ratings are in the MVP |
| Q15 | Adult content | Skip provider records flagged adult at ingestion; show content warnings and age rating for the rest |
| Q16 | Motion versus plain CSS for the shelf scroll | Native `scroll-snap`; Motion only for the arrow-button scroll easing; Embla only if that feels flat |
| Q17 | Flux's free edition is free but its license is proprietary. Keep it, or go strictly open source by replacing Flux with `@alpinejs/ui` + Bookflix Blade components (~15 more component tasks)? | **Decided 2026-10-22:** keep Flux free |

---

# Part B — Skills, plugins and tools

## B1. Process skills (every phase, in this order)

| Skill | Use |
|---|---|
| `superpowers:brainstorming` | only to settle the open questions of the phase that's starting |
| `superpowers:writing-plans` | write the detailed plan for each phase → `docs/superpowers/plans/` |
| `superpowers:using-git-worktrees` | an isolated branch per phase (`phase-N-<name>`) |
| `ultraclaude:ultraclaude-milestone-execution` + `superpowers:subagent-driven-development` | one implementer subagent per task, a review between tasks, a whole-branch review before merge (your CLAUDE.md) |
| `opus-orchestration` | Opus 5.5 plans and reviews; Sonnet 5.5 workers implement (your default) |
| `superpowers:test-driven-development` | every task |
| `superpowers:dispatching-parallel-agents` | independent tasks, e.g. the Phase 2b adapters or Phase 9 format handlers |
| `superpowers:systematic-debugging` | any failing test or bug |
| `superpowers:requesting-code-review`, `code-review`, `ponytail:ponytail-review`, `simplify` | per task and per branch |
| `security-review` | Phases 1, 2, 5, 9, 10, 11 |
| `superpowers:verification-before-completion` | before any "done" claim |
| `superpowers:finishing-a-development-branch` | PR or merge |

## B2. Laravel build skills (project-local, `.claude/skills`) and Boost MCP

| Skill / tool | Use |
|---|---|
| `laravel-best-practices` | all PHP; rules files: architecture, eloquent, migrations, queue-jobs, http-client, caching, db-performance, scheduling, security, validation |
| `livewire-development` | Livewire 4 single-file pages, `#[Url]`, lazy components, `wire:navigate`, `wire:transition` |
| `fluxui-development` | **free components only**; check whether a component exists before building a custom one |
| `fortify-development` | tasks 1.3 and 1.6 (home path, `authenticateUsing`) |
| `tailwindcss-development`, `tailwind-design-system` | Tailwind v4 `@theme` tokens (task 1.2) |
| `testing-best-practices` | before writing any test |
| `deploying-to-cloud` | Laravel Cloud environments and deploys |
| `infer-conventions` | run once after Phase 1 **when you ask for it**; records conventions in `.ai/rules` |
| Boost `search-docs` | before using Scout, Horizon, queues, rate limiting, vectors, Livewire or Flux APIs |
| Boost `database-schema`, `database-query`, `last-error`, `read-log-entries`, `browser-logs`, `get-absolute-url` | inspection and debugging; the URL for the browser pane |

## B3. Design, Figma and motion

| Skill | Use |
|---|---|
| `figma:figma-create-new-file` | F0: Prototypes file + FigJam board |
| `figma:figma-use` | **required before every `use_figma` call** (F1–F5) |
| `figma:figma-generate-library` | F2–F3: variables, text styles, components from tokens |
| `figma:figma-generate-design` | F4: screens per phase |
| `figma:figma-use-motion`, `figma:figma-implement-motion` | F5: motion specs in Figma → Motion `animate()` code |
| `figma:figma-design-to-code` | **required before `get_design_context`** when implementing a screen |
| `figma:figma-spec`, `design:design-handoff` | F7: developer specs per screen |
| `figma:figma-use-figjam`, `figma:figma-generate-diagram` | architecture, pipeline, sitemap and journey diagrams |
| `figma:figma-code-connect` | **not used**: Code Connect doesn't support Blade. Each Figma component's description holds its Blade usage instead (F3) |
| `design:design-system` | audit tokens and components for consistency |
| `design:design-critique` | review each screen before handoff |
| `design:accessibility-review` | contrast, focus, target size, reduced motion (F6 + every phase gate) |
| `design:ux-copy` | CTAs, empty states, explanation strings, Dewey wording |
| `design:user-research`, `design:research-synthesis` | optional reader interviews before Phase 6 shelves |
| `design-motion-principles` | audit hover and motion (§51 leaves timing to the team) |
| `impeccable` | polish passes on built screens (within the tokens) |

## B4. AI/ML and agents

| Item | Use |
|---|---|
| `claude-api` | Phase 11 (intent extraction, assistant, summaries); default to current Claude models |
| `backend-architect` agent | schema and API review at the start of Phases 2, 6 and 9 |
| `Plan` / `Explore` agents | per-phase research and code location |

## B5. Plugins and connectors

| Plugin / connector | Status | Recommendation |
|---|---|---|
| **Neon** (partner: `neon-postgres`, `neon-postgres-branches` skills + Neon MCP) | available, **not enabled** | **Install.** Provision the project, branch per PR, check extensions |
| Design plugin (design:* skills + Figma MCP) | enabled | use as in B3 |
| Figma MCP | connected; `whoami`: Full seat, education plan | use as in Part C |
| Laravel Boost MCP | project `.mcp.json` | use as in B2 |
| Notion | connected | optional: mirror the roadmap and decision log |
| **TypeUI** (Claude plugin `typeui@bergside`; hosted MCP `https://mcp.typeui.sh/mcp`, one-time user sign-in) | available, needs sign-in | **Design-inspiration resource only** for the Figma and UI steps (F2-F4, C7). It never overrides the Bookflix tokens, D-17 font stack or any decision in this plan; anything it suggests is re-expressed in C2 tokens and Flux/open-source components |
| Linear / Asana / Atlassian | need `/mcp` authorization | optional task tracking |
| GitHub (`gh`) | CLI | PRs, CI |
| Vercel plugin, Next.js/React skills, Supabase MCP | installed | **don't use**: the spec replaced Next.js and Supabase, and Vercel functions' size limits don't fit a torch-based ML service |
| Aesthetic skills (`design-taste-frontend`, `gpt-taste`, `high-end-visual-design`, `minimalist-ui`, `industrial-brutalist-ui`, `stitch-design-taste`, `ui-ux-pro-max`) | installed | **don't use**: they impose their own look and would fight the fixed token system |
| `gsd-*` workflow | installed | **don't use** alongside superpowers + ultraclaude (pick one process) |
| Community `laravel-best-practices` plugin | available | redundant (Boost already ships it) |
| Community "AI Ready Design System" (`ds-ai-check`) | available | optional audit of the Bookflix Figma DS |

## B6. Skill matrix per phase

| Phase | Build skills | Design skills | Review |
|---|---|---|---|
| 1 Foundation | laravel-best-practices, fortify-development, livewire-development, fluxui-development, tailwindcss-development, testing-best-practices, Neon skills | F0–F3, design:design-system | security-review, accessibility-review |
| 2 Catalog | laravel-best-practices (queue-jobs, http-client, migrations), search-docs (horizon, rate limiting) | F4 admin screens | security-review |
| 3 Dewey | laravel-best-practices (advanced-queries) | F4 explore screens, ux-copy | — |
| 4 Discovery | livewire-development, fluxui-development, search-docs (scout) | F4–F5, figma-design-to-code, figma-implement-motion, design-motion-principles | accessibility-review, impeccable |
| 5 Library | livewire-development | F4 library screens | security-review (export) |
| 6 Recs MVP | backend-architect review | F4 shelves + explanations | ponytail-review |
| 7 NLP | — | F4 search NL chips | — |
| 8 Advanced recs | — | admin model dashboard | — |
| 9 Reader | security (CSP, signed URLs) | F4 reader from the VideoPlayer kit | security-review, accessibility-review |
| 10 Social | — | F4 profile and review screens, ux-copy | security-review (moderation) |
| 11 AI | claude-api | F4 assistant | security-review (prompt injection) |

---

# Part C — Figma design workstream (Netflix design system → Bookflix)

## C1. Inputs

- **Contract:** the §51 tokens and component mapping in the spec.
- **Reference:** Figma `vHWecJSYA5Afuwr0ntLzbA`.
  - *UI Kit* frames: Typography, About, Colors, Dropdowns, InputFields, Icons&Labels (MovieInfo), Buttons (Action, Media Control, Patterns), ContentBlocks (Header, Footer, FAQ accordion: Default/On hover/Open Answer, Landing blocks), HeroBanners (TitlePreview L/M/S1–S3, Hero Banner), MoviePreview (cards, samples, patterns), VideoPlayer (Next Episode, NextEpisodesPreview, Playback Speed, Subtitles), UserProfileAvatar.
  - *Screen Templates*: Landing, Authentication, Who's watching?, Account Home, Movie Details, Active Player (default/paused/preview), Browse, plus official Netflix poster sets (**not to be reused**).
  - *Styles found:* 40 color styles (Primary Red `#E50914`, Black, White; Secondary Red-100 `#EB3942`, Red-200 `#C11119`, Red-300 `#F50723`, Blue-100 `#0071EB`, Blue-200 `#448EF4`, Blue-300 `#54B9C5`, Green `#46D369`; 22 greys from Grey-10 `#E5E5E5` to Grey-900 `#141414`; translucent whites at 15/20/30/35/50/70% and blacks at 30/60/90%). 28 Netflix Sans text styles (Regular 11–50, Medium 12–33, Bold 20/48/55). These are **styles, not variables**, and the translucent ones store opacity separately.

## C2. Token reconciliation (spec = contract; reference fills the gaps)

| Semantic token (Figma ↔ CSS) | Value | Source | Contrast check (WCAG AA: 4.5 for normal text, 3 for large text and UI) |
|---|---|---|---|
| `bg/canvas` ↔ `--color-canvas` | `#000000` | spec | — |
| `surface/deep` ↔ `--color-surface-deep` | `#161616` | spec (closest reference greys: Grey-900 `#141414`, Grey-850 `#181818`) | — |
| `surface/card` ↔ `--color-surface-card` | `#232323` | spec = reference Grey-800 | — |
| `surface/input` ↔ `--color-surface-input` | `#2d2d2d` | spec | — |
| `text/primary` ↔ `--color-ink` | `#ffffff` | spec | 15.7:1 on card ✔ |
| `text/muted` ↔ `--color-ink-muted` | `#808080` | spec = reference Grey-200 | 5.3 on canvas ✔ · 4.6 on deep ✔ · **4.0 on card ✘ · 3.5 on input ✘** |
| `text/muted-on-surface` ↔ `--color-ink-subtle` | `#b3b3b3` | **reference Grey-100** | 7.5 on card ✔ · 6.6 on input ✔ (used for metadata and placeholders on cards and inputs) |
| `border/hairline` ↔ `--color-hairline` | `#414141` | spec = reference Grey-400 | 2.1 on canvas: decorative dividers only |
| `border/input` ↔ `--color-border-input` | `#6d6d6e` | **reference Grey-300** | 4.1 on canvas ✔ · 3.5 on deep ✔ (form-field boundaries, WCAG 1.4.11) |
| `action/primary` ↔ `--color-brand` | `#e50914` | spec | white label 4.8 ✔; **red text on black 4.4 ✘**, so red is never used for small text |
| `action/primary-hover` ↔ `--color-brand-hover` | `#c11119` | **reference Red-200** (§51 leaves hover states to Bookflix) | white label 6.2 ✔ |
| `link` ↔ `--color-link` | `#448ef4` | **reference Blue-200** | 6.4 canvas ✔ · 5.5 deep ✔ · 4.8 card ✔ |
| `accent/electric` ↔ `--color-accent-electric` | `#4061e7` | spec | **4.1 as text ✘**, so decorative and focus rings only (≥ 3:1 ✔) |
| `feedback/success` ↔ `--color-success` | `#46d369` | reference Green | "match" and success states |
| `gradient/featured` | `#192247 → #461518 → #6f181d` | spec | Trending Now shelf only |
| Spacing | 2, 3, 8, 12, 16, 24, 64; gutter 16 (< 768) / 32 (768–1023) / 64 (1024–1439) / 148 (≥ 1440) | spec + Bookflix breakpoints | — |
| Radius | none 0 · control 4 · card 8 · brand 16 | spec | — |
| Type | hero-display 56/900 · section-title 24/500 · button-label 24/500 · body-emphasis 20/500 · body-base 16/400 · body-sm 14/400 · legal-caption 13/400 | spec (closest reference styles: Bold/LargeTitle 55/700, Medium/Title3 24/500, Medium/Headline2 20/500, Regular/Body 16, Regular/SmallBody 14, Regular/Caption1 13) | weight 900 isn't available in Helvetica Neue on macOS, so it renders at 700 (matches the reference's 55/700) |
| Motion | `motion/fast` 150 ms · `motion/base` 250 ms · `motion/slow` 400 ms · ease-out `cubic-bezier(0.2, 0, 0, 1)` · spring for preview expansion (stiffness 300, damping 30) | Bookflix decision (§51) | all disabled under `prefers-reduced-motion` |

Run `design:accessibility-review` in F6 to recheck these ratios.

## C3. Files

1. **Reference:** `vHWecJSYA5Afuwr0ntLzbA` stays untouched and is only read via MCP (`get_design_context`, `get_screenshot`).
2. **Bookflix · Design System:** ✅ **duplicated by you (2026-10-22).** Rename it "Bookflix · Design System" and share its URL so the file key can go in G2. F1–F3 transform it. (It starts as a duplicate because MCP can't copy components between files unless they're published as a library.)
3. **Bookflix · Prototypes:** created with `figma-create-new-file`; screens and flows use components from file 2, published as a team library.
4. **Bookflix · Architecture & Flows (FigJam):** diagrams from A2, A9, A10 and A12, the sitemap, and the user journey (§65 loop).

## C4. Tasks

**F0 · Files.** Create files 3 and 4; record all four file keys in the G2 decision log. *Done when:* `create_session` succeeds on each key.

**F1 · Clean and rebrand** (file 2; `figma-use`).
- Delete: the Netflix logo instances, both official poster frames, the "Who's watching?" screen, and the landing "Screens Preview" device mockups.
- Replace poster fills with neutral 2:3 placeholder covers.
- Change every text style's font from Netflix Sans to Helvetica Neue, keeping size, line height and letter spacing.
- Add a cover page crediting the community file's author and license.

*Done when:* no missing-font warnings, and searching layers for "Netflix", "Logo" or "AAAA" (poster names) returns 0 results.

**F2 · Variables and styles** (`figma-generate-library` + `figma-use`).
- Collection `Primitives`: every reference color as an RGBA variable (rebuild the translucent ones with alpha) plus Bookflix-only values (`#161616`, `#2d2d2d`, `#4061e7`, the gradient stops).
- Collection `Semantic` (one mode, "Dark"): the C2 tokens as aliases of primitives.
- Collections `Spacing`, `Radius`, `Motion` (durations as numbers).
- Text styles renamed to the 7 spec tokens; reference styles that no screen needs are removed (add a style back only when a screen needs it).
- Figma variable names match the CSS tokens one-to-one (`surface/card` ↔ `--color-surface-card` ↔ `bg-surface-card`).

*Done when:* `get_variable_defs` lists every C2 token and `design:design-system` reports no raw hex values in components.

**F3 · Components** (adapt reference components; follow Flux anatomy wherever a free Flux component exists; auto layout; every fill bound to a variable; each description holds its Blade usage).

| Bookflix component | Adapted from (reference) | Code target | Variants |
|---|---|---|---|
| Button / Primary CTA | Buttons → Action ("Play") | `<flux:button variant="primary">` inside `<x-cta>` (24 px label, 8/22 padding) | default, hover (`#c11119`), focus, disabled |
| Button / Secondary | Buttons → "More Info" translucent grey | `<flux:button variant="filled">` | default, hover, focus |
| Icon button (add, favorite, rate) | Buttons → Media Control (circle) | `<flux:button icon="…" square>` | default, active, focus |
| Cover tile | MoviePreview cards (16:9) **re-proportioned to 2:3 portrait** | `<x-cover-tile>` | S/M/L · default/hover/focus · with progress · media-type badge |
| Story preview (hover expand) | MoviePreview patterns | `<x-story-preview>` (Alpine + Motion spring) | collapsed / expanded |
| Shelf | Account Home rails | `<x-shelf>` (CSS scroll-snap + Alpine arrows; optional "See all" `href`, C7 #5) | plain (`surface/deep`) / featured (gradient) |
| Hero | HeroBanners + TitlePreview | `<x-hero>` (C7 #1: next-3 card row, backdrop morph, line-by-line title reveal, prev/next, progress line, `01` counter, no autoplay) | L / M / S, with/without slide controls |
| Story info row | Icons&Labels → MovieInfo | `<x-story-info>` | media type · year · length · age rating · Dewey code |
| Accordion | ContentBlocks → FAQ (Default/Hover/Open) | `<x-accordion>` = native `<details>` | closed / hover / open |
| Top nav (desktop) | ContentBlocks → Header / HeaderLinear-gradient | `<flux:navbar>` in `layouts/app/header` | default / scrolled (flush black) |
| Bottom nav (mobile) | — (new; D-13) | `<x-bottom-nav>` | 5 items, active state |
| Footer | ContentBlocks → Footer | partial | — |
| Input / Search | InputFields | `<flux:input>` | default / focus / error |
| Select / language dropdown | Dropdowns → Browse by Language | `<flux:select>` / `<flux:dropdown>` | — |
| Avatar | UserProfileAvatar S/L | `<flux:avatar>` / `<flux:profile>` | — |
| Sign-in card | InputFields → SignIn Email + Password | auth pages | — |
| Reader chrome | VideoPlayer → Next Episode → **Next Chapter**, Playback Speed → **Reading settings**, Subtitles → **Display panel** | Phase 9 | — |
| New from C7: Search panel (#2), Quick links row (#3), View toggle grid/list + filter chips (#7), Path card with count (#12), Stat row (#11), Feature card (#5) | InputFields, Buttons, ContentBlocks (restyled) | `<x-search-panel>`, `<x-quick-links>`, `<x-view-toggle>`, `<x-path-card>`, `<x-stat-row>`, `flux:card` | default / hover / focus / empty |
| New: Dewey class tile + breadcrumb, Rating input (stars as a radio group), Reading progress, Reason label, Filter chip, Onboarding pick card, Empty state, Toast, Modal, Pagination | — | `flux:breadcrumbs`, `flux:radio`, `flux:progress`, `flux:badge`, `flux:checkbox`, `flux:card`, `flux:toast`, `flux:modal`, `flux:pagination` | — |
| New, open-source (C6): Command palette (⌘K search), Combobox (multi-select genres/themes/languages), Tabs, Range filter, Filter sheet, Stepper (onboarding), Story preview | `@alpinejs/ui` behavior + Pines/Basecoat markup, restyled; noUiSlider for ranges | `<x-command-palette>`, `<x-combobox>`, `<x-tabs>`, `<x-range-filter>`, `<flux:modal variant="flyout">`, `<x-stepper>` | default / open / focus / empty / loading |

*Done when:* the components are published as a library and `design:design-system` passes.

**F4 · Screens** (`figma-generate-design`; desktop 1440 + mobile 390, plus tablet 768 for Home and Search). Each phase's screens are designed **one phase ahead**. Reference templates serve as layout starting points: Landing → Bookflix landing; Authentication → sign in/up; Account Home → Home; Movie Details → Story detail; Browse → Discover; Active Player → Reader.

| Phase | Screens |
|---|---|
| 1 | Landing, Sign in, Register, Forgot/Reset, Verify email, Home shell (empty state), Settings: Profile (reading profile, avatar), Security, Privacy, Deactivate modal, Admin shell |
| 2 | Admin: Providers & sync runs, Media edit (lock fields), Duplicate review / merge |
| 3 | Explore the Library (Choose-your-path class cards with counts, C7 #12; classes → divisions → sections → stories), Classification shelves, Admin classification review queue |
| 4 | Home v1 (numbered hero slider, C7 #1; search panel #2; quick links #3), Discover (browse tiles #6, quick links), Search + filters (desktop sidebar / mobile sheet), zero results, Story detail (content warnings accordion, series, related, similar), Series page |
| 5 | Onboarding (types → genres → 3 stories), Library (status filters, sort, shelves), Collections (list/detail/edit), History, Rating control, Progress logging |
| 6 | Personalized Home (10 shelves, reason labels, per-shelf "See all", themed editorial banner), "Why this?" explanation popover |
| 7 | Natural-language search (intent chips), semantic results explanation |
| 8 | Admin model versions and metrics |
| 9 | Reader: EPUB, PDF, manga pager (LTR/RTL), webtoon vertical scroll, reading settings |
| 10 | Public profile, public library, reviews, report dialog, moderation queue, activity |
| 11 | Discovery assistant |

**F5 · Prototype flows and motion** (`figma-use-motion`; `design-motion-principles` audit).
- Flows: onboarding → home → detail → add to library → continue reading; search → filter → detail; explore 800 → 810 → 813 → story; admin review accept/reject.
- Motion specs use the C2 motion tokens: tile hover (CSS scale 1.05, `motion/fast`), preview expand (spring), hero cross-fade (`motion/slow`), shelf items revealed in view (stagger 30 ms, `motion/base`).

**F6 · Review gates:** `design:design-critique` → `design:accessibility-review` (C2 ratios, visible focus, 44 px targets, 200% zoom, reduced motion) → `design:ux-copy` → your sign-off in Figma comments. *A phase's UI tasks can't start until its F6 sign-off.*

**F7 · Handoff and design QA:** `figma-spec` / `design:design-handoff` per screen. Implementers load `figma:figma-design-to-code` → `get_design_context` and adapt the output to Blade + Flux + tokens. Never paste generated React or Tailwind as-is. After building, compare `get_screenshot` of the frame with a browser-pane screenshot at 1440 and 390.

## C5. Motion implementation contract (code side)

- `resources/js/motion.js` (Phase 4) imports `animate`, `inView`, `stagger` from `motion` and registers Alpine directives: `x-motion-reveal` (fade + 8 px rise when in view, staggered children), `x-motion-expand` (spring height/scale for previews), `x-motion-crossfade` (hero).
- One helper: `const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;`. When true, each directive sets the final styles and returns.
- Only `transform` and `opacity` are animated (fast to render, and they survive Livewire morphing). Animated wrappers either have a stable `wire:key` or sit outside Livewire-morphed regions.
- Motion tokens exist both as CSS custom properties (`--motion-fast` …) and as constants in `motion.js`, with a comment linking the pair. `ponytail:` two copies of three numbers; generate them from one source only if they ever drift.

## C6. Component sourcing: open-source libraries only

**Rule:** every added library is open source under a permissive license: MIT, Apache-2.0, ISC, or OFL-1.1 for fonts. No paid libraries. Licenses were checked at the source (GitHub API and npm) on 2026-10-22.

**License note on Flux:** Flux's free edition costs nothing, but `vendor/livewire/flux/composer.json` declares `"license": "proprietary"`. It's free to use, not open source. **Decided (Q17): Flux free stays the base UI library, and the open-source rule applies to everything added on top of it.**

**Already installed (MIT):**
- Livewire 4.4.8, plus Alpine 3.17.5 bundled with the plugins `x-collapse`, `x-anchor` (Floating UI), `x-trap`, `x-intersect`, `x-sort` (SortableJS), `x-mask`, `x-resize` and `$persist`.
- Blaze, Tailwind 4.
- Native platform: `<details name>`, `<dialog>`, Popover API, scroll-snap, range inputs, View Transitions, `inert`.
- Icons: Heroicons (MIT, inside Flux) + Lucide (ISC) via `php artisan flux:icon`.

**Open-source additions (verified):**

| Library | License | Activity (stars · last push) | What Bookflix uses it for | Phase | Verdict |
|---|---|---|---|---|---|
| **`@alpinejs/ui`**, Alpine's official headless UI (same 3.17.5 version as Livewire's bundled Alpine) | MIT | part of `alpinejs/alpine` (32k · Oct 2026) | Accessible headless `combobox`, `listbox`, `tabs`, `dialog`, `disclosure`, `menu`, `popover`, `radio`, `switch`. Covers instant search, multi-select genres/themes/languages, story-detail tabs, the command palette, accordions, hover and "why this?" popovers, and star ratings. | 4 | **Adopt.** It does the hard accessibility work (roles, keyboard, focus) and leaves all styling to our tokens. It isn't documented on alpinejs.dev, so implementers read `packages/ui/src` and keep its version pinned to Livewire's Alpine. It's registered by bundling Livewire's ESM build (`Alpine.plugin(ui)` before `Livewire.start()`; check `search-docs` "manually bundling alpine"). |
| Motion | MIT | 34k · Oct 2026 | C5 animations | 4 | Adopt (already decided) |
| Pines UI (DevDojo) | MIT | 3.3k · Aug 2025 | Copy-paste markup: slide-over, image gallery, marquee, text effects, context menu | 4+ | Pattern source (copy in, keep the license header) |
| Basecoat | MIT | 4.4k · Jul 2026 | shadcn-style Tailwind v4 markup reference (stepper, tables, empty states) | 4+ | Pattern source only. **Don't install its CSS**: it brings a second theme system |
| noUiSlider | MIT | 5.8k · Nov 2024 (stable) | Two-handle year and length range filters, with keyboard and ARIA support | 4 | Adopt for range filters (a native range input has only one handle) |
| Embla Carousel | MIT | 8.5k · Oct 2026 | Hero carousel and shelf drag physics | 4 | Only if scroll-snap + Motion feels flat (Q16) |
| Tom Select | Apache-2.0 | 2.2k · Oct 2026 | Tagging and remote-search selects (admin creator and tag pickers) | 2/4 | Fallback only, if the `@alpinejs/ui` combobox can't handle it |
| foliate-js | MIT | 1.1k · May 2026 | EPUB (+ MOBI/FB2) reader | 9 | Adopt (epub.js is unmaintained) |
| pdf.js | Apache-2.0 | 54k · Oct 2026 | PDF reader | 9 | Adopt |
| Chart.js | MIT | 68k · Oct 2026 | Admin metric charts | 6/8 | Adopt once charts are needed (tables first); uPlot (MIT) if only time series |
| EasyMDE | MIT | 3.1k · Aug 2026 | Review editor with a Markdown toolbar | 10 | Optional; a plain textarea with Markdown comes first |
| Inter (font) | OFL-1.1 | 20k | Self-hosted fallback with a real 900 weight and identical rendering on every OS | 1 | Optional (D-17); otherwise the spec's font stack |

**Considered but not used:**
- Splide (MIT, but nothing pushed since Jul 2024; Embla is actively maintained).
- AutoAnimate (MIT; Motion already covers animation, and we keep one animation library).
- Tiptap (MIT core; heavier than reviews need).
- Apache ECharts (Apache-2.0; heavy for a few admin charts).
- Shoelace / Web Awesome (web components with their own design language).

**Excluded:**
- *License can't be verified:* Penguin UI (license page returns 404), Pinemix (no public repo found).
- *Full suites with their own theme systems, regardless of license:* daisyUI, Preline, Flowbite, TallStackUI, Mary UI, WireUI.
- *Paid:* Flux Pro, Alpine UI Components, Tailwind Plus, Motion+.

**Gap map: what Bookflix needs → open-source implementation**

| Need (screen) | Implementation |
|---|---|
| Accordion: content warnings, help, Dewey explanations | `<details name>` + `x-collapse`; `@alpinejs/ui` disclosure when Livewire needs to control the state |
| Tabs: story detail (Overview / Series / Related), library | `@alpinejs/ui` tabs |
| Hero carousel and shelves (home) | scroll-snap + Alpine arrows + Motion; Embla only if needed |
| Hover preview card (shelves) | `@alpinejs/ui` popover + `x-anchor` + Motion spring |
| Instant search dropdown (top nav) | `@alpinejs/ui` combobox + `wire:model.live.debounce.300ms` |
| Command palette (⌘K) | `@alpinejs/ui` dialog + combobox, using the same Livewire search |
| Multi-select genres/themes/languages | `flux:checkbox` groups for short lists; `@alpinejs/ui` combobox (multiple) for long ones |
| Year and length range filters | noUiSlider |
| Mobile filter sheet / drawer | `flux:modal variant="flyout"` |
| Rating stars | `@alpinejs/ui` radio group styled as stars |
| Onboarding stepper | Blade component, using Basecoat or Pines markup as reference |
| Avatar and user-owned uploads with preview | native file input + Livewire `temporaryUrl()` |
| Library item actions | `flux:dropdown` + `flux:menu` |
| Collection reordering | `x-sort` (bundled) |
| Reviews editor (Phase 10) | `flux:textarea` + `Str::markdown` (HTML stripped); EasyMDE if needed |
| Admin charts (Phases 6/8) | tables first; then Chart.js (`dataviz` skill) |
| Readers (Phase 9) | foliate-js, pdf.js, CBZ pages extracted on the server |

**Rules for every sourced component:**
- Copied markup, or a thin wrapper around an approved library, becomes a Bookflix Blade component in `resources/views/components/`. It uses only C2 tokens and has a matching Figma F3 component. Keep license headers in copied code, and record each library's license in G2.
- Accessibility acceptance: the component follows its WAI-ARIA APG pattern (roles, keyboard, focus return), shows a visible focus ring, has 44 px touch targets, and respects reduced motion. A Pest browser test for the component proves it.
- Livewire safety: stable `wire:key`; third-party DOM inside `wire:ignore`; initialization in Alpine `x-init` so it survives `wire:navigate`.

---

## C7. Visual inspiration and interaction patterns

Researched 2026-10-25 by fetching each site and summarizing layout, typography, navigation, motion and signature moments (summaries only, nothing copied). **Unreadable:** `nypl.org` (empty response, likely JS-rendered or blocking) and `cinematypography.com` (title only); neither contributed a pattern. `nextjs-animated-slider` is a placeholder demo whose motion details were not visible; its numbered-slide hero pattern is the part adopted.

**Fixed constraints for every pattern:** dark-only C2 tokens; red only for CTAs, active accents and the logo mark; no shadows; Flux free plus open-source additions only (`@alpinejs/ui`, Motion vanilla, Embla if needed, CSS); code keeps the spec's font stack (D-17), so expressive type comes from the existing 7 text tokens through scale, weight, tracking and layout, never new fonts; WCAG AA, visible focus (`accent-electric`), `prefers-reduced-motion` respected; no site's brand assets, imagery or exact layouts are reused. Recurring type recipe, built from existing tokens: an **eyebrow** (`text-legal`, uppercase, `tracking-widest`, `text-ink-subtle`) over a **title** (`text-hero` or `text-section`), with numbered labels (`01`) in `text-meta` tabular figures.

**The Bookflix brand mark now exists** (PR #8): a red rounded square with a white "B" made of a book spine and stacked books, plus a white title-case wordmark. Use it only as supplied; the red mark is the one non-CTA red allowed besides active accents.

| # | Source site | Pattern | Where in Bookflix | How to build it here | Priority |
|---|---|---|---|---|---|
| 1 | nextjs-animated-slider (inspected live) | **Cinematic hero slider.** Layout: full-bleed backdrop of the current item; left column with eyebrow, large title, blurb and CTA; right column with a row of the **next 3 items as cards**; prev/next buttons, a progress line and a large `01` counter bottom right. **No autoplay.** Transition: the next or clicked card **morphs into the backdrop** (shared element); the remaining cards shift by one; the new title **reveals line by line** from a mask; counter and progress line advance | Phase 4.7 Home hero; `<x-hero>` | Native View Transitions API for the card-to-backdrop morph (`view-transition-name` on the active card and backdrop, set per transition) with a Motion crossfade fallback where unsupported; title reveal with Motion `animate` + `stagger` over per-line masked spans (`overflow: clip`, transform only); card row shifts by one with a transform; 2:3 cover cards; CTAs "Start reading" and "Add to Library" (`<x-cta>`); counter in tabular `text-hero`-scale figures. Reduced motion swaps instantly (no morph, no reveal). Carousel ARIA: `aria-roledescription="carousel"` / `"slide"`, labels "N of M", ←/→ keys, an `aria-live="polite"` region; no pause control because there is no autoplay | MVP |
| 2 | SPL | Search-first panel ("What would you like to find?") above the content, plus a catalogue entry point | Phase 4.3 Search, 4.6 Discover, Home top; new `<x-search-panel>` | A large `flux:input` bound to the same Livewire search with the `<x-combobox>` suggestions and a "Browse the catalogue" secondary CTA to Discover; ⌘K command palette stays | MVP |
| 3 | SPL | "Common tasks" shortcut row | Phase 4.6 Discover, Home empty state; `<x-quick-links>` | 4-6 `flux:card` links (Continue reading, New releases, Browse by subject, My collections, Surprise me), icons from Heroicons/Lucide, equal-height row, 44 px targets | MVP |
| 4 | SPL | Staff Picks as themed lists (seasonal, awareness weeks) | Phase 5.3 editorial collections, Phase 6.4 `EditorialSource`, Home shelf "Picked by our editors" | Editorial collections get `theme_label` (reuse the collection `name`/`description`) and a banner row on Discover; the shelf eyebrow shows the theme; no new table | MVP |
| 5 | YPRL | Per-shelf "See more" link and stacked feature cards (headline, blurb, button) | Phase 4.0b `<x-shelf>`, 4.7 Home, 4.6 Discover | `<x-shelf>` gets an optional `href` rendered as a "See all" text link in the header, to a pre-filtered Search or the collection; feature cards are `flux:card` blocks with eyebrow + title + CTA | MVP |
| 6 | YPRL | "Browse the Catalogue" as an explicit card leading into filtered browsing | Phase 4.6 Discover, Phase 3.4 Explore | Discover leads with browse-by media type, genre, subject tiles; each tile shows a live count (reuse `DeweyCounts`) | MVP |
| 7 | forms.world | Filter chips above a grid plus Grid/List toggle | Phase 4.3 Search results, 5.2 Library, 4.6 Discover; new `<x-view-toggle>` | Chip row of active filters (removable) above results; `<x-view-toggle>` is a two-option `@alpinejs/ui` radio group (icon buttons, `aria-label`s); the choice persists in the URL (`#[Url] view`) and `localStorage` as a convenience; list view = compact row with cover, title, creators, type, year | MVP |
| 8 | forms.world | Preview on hover/focus with an expandable viewer | Phase 4.0b `<x-story-preview>` | Existing spring-expand popover (Motion `x-motion-expand`); add a "Quick view" modal (`flux:modal`) for touch devices instead of hover | MVP |
| 9 | ethanandtom | Numbered index (`[01]`), breadcrumb trail and sequential "next" links | Phase 4.5 Series page, 4.4 Story detail; `<x-dewey-breadcrumb>` | Series volumes listed with bracketed index numbers in tabular figures; story page shows breadcrumb (Dewey path), then "Previous / Next in series" links; keyboard-reachable | MVP |
| 10 | theperformancelab | Numbered process steps (`001-004`) with bold uppercase headings | Phase 5.8 onboarding `<x-stepper>`, landing "How it works" | Stepper labels `01 Types`, `02 Genres`, `03 Stories`; landing section of three numbered cards; headings use the eyebrow + `text-section` recipe | MVP |
| 11 | theperformancelab | Stats row (large numbers with small labels) | Phase 5.6 History header, Phase 10 profile, landing (catalogue counts); new `<x-stat-row>` | 3-4 `text-hero`-scale numbers over `text-legal` uppercase labels on `surface-card`; values come from real queries (total reading time, completed, ratings), never invented marketing numbers | MVP (History) / later (landing, profile) |
| 12 | ciphermuseum | "Choose your path" entry cards with counts and an "exhibits / halls" framing | Phase 3.4 Explore index; new `<x-path-card>` | The 10 Dewey-inspired classes as large path cards (code, name, story count, "Enter" affordance), a hero stat line ("N stories across 10 classes"); the "halls" metaphor stays copy-only, never "DDC" | MVP |
| 13 | ciphermuseum | Live interactive playground and three-act story section | Landing page | "Try a search" demo on the landing page (anonymous, read-only, over editorial collection items only) and a three-step story block; no personalization, no logging | later |
| 14 | SPL | Skip link and accessible mega-menu with clear grouping | Layouts (Phase 1 already has the skip link), nav config | Keep the skip link; if a mega-menu is ever needed use `@alpinejs/ui` disclosure/menu; Bookflix's nav stays a flat list | MVP (verify only) |
| 15 | ethanandtom, forms.world | Media controls with live progress percentage and timecode | Phase 9 reader chrome; Phase 5.4 progress on cover tiles | Reader toolbar shows `% read` and estimated time left in tabular `text-meta`; tile progress bars show the same figure to screen readers (`aria-valuenow`) | later (reader) |
| 16 | theperformancelab | Parallax or layered hero imagery (inferred, not confirmed) | Phase 4.7 hero | Optional: Motion `scroll()` moves the cover layer by a few px with `transform` only; skipped entirely under reduced motion; drop it if it hurts performance | later |

**Not adopted:** live clock and decorative marquee elements (no purpose in a library), uppercase-everything headings (hurts readability; limited to eyebrows), any imagery or brand asset from the studied sites.

---

# Part D — Phase 1: Foundation — ✅ DONE

> **Status: implemented** in PR #4 (branch `phase-1-foundation`). The ledger of what was actually built, including deviations from the tasks below, is `docs/superpowers/plans/phase-1-ledger.md` (on that branch until merged). The tasks below are kept as written for reference; where they conflict with the ledger or with decisions D-26/D-27 (spec not copied; nav in `config('bookflix.navigation')`; `FILESYSTEM_DISK=local`), the ledger wins.

**Phase goal:** the starter kit becomes Bookflix's foundation: Postgres + extensions, design tokens, app shell and navigation, admin access, extended profile, deactivation, privacy settings, the ML service skeleton, request context and security headers. All green in CI.

**Parallel design work:** F0–F3 (needed by 1.2 and 1.3 for final visuals; the code tasks can use the tokens before the components are finished).

**Approvals needed before starting:** A3 (D-2, D-3, D-4), A4 (`league/flysystem-aws-s3-v3`, folder `ml/`, optional Nightwatch), Q5, Q6, Q13.

---

### Task 1.1: Postgres with extensions for app, tests and CI

**Files:**
- Create: `database/migrations/2026_10_23_000001_enable_postgres_extensions.php`
- Create: `tests/Feature/Database/PostgresExtensionsTest.php`
- Create: `docs/superpowers/specs/bookflix-specification.md` (copy of the spec, Q13)
- Modify: `phpunit.xml:26-28` (DB env), `.env.example` (DB block), `.github/workflows/tests.yml` (Postgres service + env)

**Interfaces:**
- Produces: a Postgres database with the `vector`, `pg_trgm` and `unaccent` extensions, used by every later migration and test.

- [ ] **Step 1: Start a local Postgres with pgvector and create the databases**

Run: `docker run -d --name bookflix-pg -e POSTGRES_PASSWORD=postgres -p 5432:5432 pgvector/pgvector:pg17 && sleep 3 && docker exec bookflix-pg createdb -U postgres bookflix && docker exec bookflix-pg createdb -U postgres bookflix_test`
(Herd Pro's Postgres also works if `create extension vector` succeeds there. Use the Neon plugin for the hosted project.)
Expected: both `createdb` commands exit 0.

- [ ] **Step 2: Write the failing test**

```php
<?php

use Illuminate\Support\Facades\DB;

test('required postgres extensions are installed', function () {
    $installed = collect(DB::select('select extname from pg_extension'))->pluck('extname');

    expect($installed)->toContain('vector', 'pg_trgm', 'unaccent');
});
```

- [ ] **Step 3: Point the tests at Postgres**

In `phpunit.xml`, replace the `DB_CONNECTION`/`DB_DATABASE` sqlite entries with `pgsql`, `bookflix_test`, and add `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_USERNAME=postgres`, `DB_PASSWORD=postgres` (keep `DB_URL` empty).

- [ ] **Step 4: Run the test to confirm it fails**

Run: `php artisan test --compact tests/Feature/Database/PostgresExtensionsTest.php`
Expected: FAIL. `vector`, `pg_trgm`, `unaccent` missing from the collection.

- [ ] **Step 5: Write the migration**

`up()`: `Schema::ensureVectorExtensionExists();` then `DB::statement('create extension if not exists pg_trgm')` and the same for `unaccent`. `down()`: `drop extension if exists` for all three.

- [ ] **Step 6: Run the test, then the whole existing suite on Postgres**

Run: `php artisan test --compact`
Expected: PASS, with all starter-kit tests green on Postgres.

- [ ] **Step 7: Update `.env.example` and CI**

`.env.example`: `APP_NAME=Bookflix`, `DB_CONNECTION=pgsql`, the `DB_HOST…DB_PASSWORD` local defaults, plus commented `DB_URL=` (Neon pooled) and `DB_DIRECT_URL=` with notes.
`tests.yml`: add `services.postgres` (`image: pgvector/pgvector:pg17`, env `POSTGRES_PASSWORD: postgres`, `POSTGRES_DB: bookflix_test`, port 5432, health check `pg_isready`). Add job-level env `DB_CONNECTION: pgsql`, `DB_HOST: 127.0.0.1`, `DB_USERNAME: postgres`, `DB_PASSWORD: postgres`, `DB_DATABASE: bookflix_test`. Real environment variables win over phpunit's `<env>` entries, so CI uses one database for both `composer setup` and the tests. Bump `php-version` to `'8.4'` to match the runtime.

- [ ] **Step 8: Copy the spec into the repo and commit**

```bash
mkdir -p docs/superpowers/specs && cp "/Users/gabe/Documents/Bookflix Documentation/Bookflix_Specification.md" docs/superpowers/specs/bookflix-specification.md
vendor/bin/pint --dirty --format agent
git add database/migrations tests/Feature/Database phpunit.xml .env.example .github/workflows/tests.yml docs/superpowers
git commit -m "chore: move to postgres with pgvector, pg_trgm, unaccent"
```

---

### Task 1.2: Design tokens and a dark-only theme

**Files:**
- Modify: `resources/css/app.css` (`@theme`)
- Modify: `resources/views/partials/head.blade.php` and every `resources/views/layouts/**` root `<html>` → `class="dark"` (the header layout already has it)
- Delete: `resources/views/pages/settings/⚡appearance.blade.php` (D-4)
- Modify: `routes/settings.php:12` (remove the appearance route), `resources/views/pages/settings/layout.blade.php:6` (remove the nav item), `tests/Feature/Auth/EmailVerificationTest.php:24` (retarget `appearance.edit` → `route('dashboard')`, which is also behind `verified`; task 1.3 then moves it to `route('home')`)

**Interfaces:**
- Produces Tailwind utilities used by every later view: `bg-canvas`, `bg-surface-deep`, `bg-surface-card`, `bg-surface-input`, `text-ink`, `text-ink-muted`, `text-ink-subtle`, `border-hairline`, `border-border-input`, `bg-brand`, `hover:bg-brand-hover`, `text-link`, `ring-accent-electric`, `text-hero|section|cta|lead|body|meta|legal`, `rounded-control|card|brand`, `px-gutter`.

This task is styling only, so it needs no new tests (AGENTS.md). Its check is visual.

- [ ] **Step 1: Replace the starter `@theme` block with the Bookflix tokens (values from §51 and C2)**

```css
@theme {
    --font-sans: 'Netflix Sans', 'Helvetica Neue', 'Segoe UI', Roboto, Ubuntu, sans-serif;

    --color-canvas: #000000;
    --color-surface-deep: #161616;
    --color-surface-card: #232323;
    --color-surface-input: #2d2d2d;
    --color-ink: #ffffff;
    --color-ink-muted: #808080;
    --color-ink-subtle: #b3b3b3;
    --color-hairline: #414141;
    --color-border-input: #6d6d6e;
    --color-brand: #e50914;
    --color-brand-hover: #c11119;
    --color-link: #448ef4;
    --color-accent-electric: #4061e7;
    --color-success: #46d369;
    --color-featured-from: #192247;
    --color-featured-via: #461518;
    --color-featured-to: #6f181d;

    /* Flux reads zinc + accent; map them onto Bookflix surfaces (starter kit already overrides zinc here) */
    --color-zinc-50: #fafafa;  --color-zinc-100: #e5e5e5; --color-zinc-200: #dcdcdc; --color-zinc-300: #d2d2d2;
    --color-zinc-400: #b3b3b3; --color-zinc-500: #808080; --color-zinc-600: #414141; --color-zinc-700: #2d2d2d;
    --color-zinc-800: #232323; --color-zinc-900: #161616; --color-zinc-950: #000000;
    --color-accent: var(--color-brand);
    --color-accent-content: var(--color-ink);      /* red is never small text (C2) */
    --color-accent-foreground: var(--color-ink);

    --text-hero: 3.5rem;      --text-hero--font-weight: 900;    --text-hero--line-height: 1.1;
    --text-section: 1.5rem;   --text-section--font-weight: 500;
    --text-cta: 1.5rem;       --text-cta--font-weight: 500;
    --text-lead: 1.25rem;     --text-lead--font-weight: 500;
    --text-body: 1rem;
    --text-meta: 0.875rem;
    --text-legal: 0.8125rem;

    --radius-control: 4px;  --radius-card: 8px;  --radius-brand: 16px;
    --spacing-gutter: 1rem;
    --breakpoint-desktop: 90rem;

    --shadow-*: initial;  --inset-shadow-*: initial;  --drop-shadow-*: initial;   /* §51: no drop shadows */

    --motion-fast: 150ms;  --motion-base: 250ms;  --motion-slow: 400ms;
    --ease-out-bookflix: cubic-bezier(0.2, 0, 0, 1);
}
```
Then the gutter steps: `@media (width >= 48rem) { :root { --spacing-gutter: 2rem } }`, `64rem → 4rem`, `90rem → 148px`. Delete the `@layer theme .dark { --color-accent… }` overrides, which are now redundant. Check every Tailwind v4 sub-property syntax (`--text-*--font-weight`, `--shadow-*: initial`) with `search-docs` before saving.

- [ ] **Step 2: Make dark-only and remove the appearance page**

Every layout root gets `<html … class="dark">` and `<body class="bg-canvas text-ink">`. Delete the appearance page, its route and its settings nav item. Run `grep -rn "appearance.edit" resources routes tests`, which should find nothing except the EmailVerificationTest retarget above.

- [ ] **Step 3: Build and check visually**

Run: `npm run build && php artisan test --compact`
Expected: the build succeeds and the tests PASS.
Open `get-absolute-url` → `/login` and `/settings/profile` in the browser pane at 1440 and 390 widths. The canvas is black, Flux primary buttons are red with white labels, inputs are `#2d2d2d` with a visible border, and there are no shadows.

- [ ] **Step 4: Commit**

```bash
git add resources routes tests/Feature/Auth/EmailVerificationTest.php
git commit -m "feat: bookflix design tokens, dark-only theme"
```

---

### Task 1.3: Landing, Home and primary navigation

**Files:**
- Modify: `routes/web.php` (rename `/` → `landing`, add `home`), `config/fortify.php:76` (`'home' => '/home'`)
- Create: `resources/views/pages/home/⚡index.blade.php` (heading + empty-state CTA; the real shelves come in Phases 4 and 6)
- Modify: `resources/views/welcome.blade.php` → Bookflix landing (hero with the product line "Your Library. Every Story.", Sign in / Get started CTAs)
- Modify: `resources/views/layouts/app.blade.php` (switch to `layouts::app.header`), `resources/views/layouts/app/header.blade.php` (Bookflix nav: Home + Profile now, the rest added by their phases; remove the starter repo/docs links)
- Create: `resources/views/components/bottom-nav.blade.php` (mobile, below `lg`, items for D-13 as they exist)
- Modify: `resources/views/layouts/auth/{simple,card,split}.blade.php` (`route('home')` → `route('landing')`)
- Rename: `tests/Feature/DashboardTest.php` → `tests/Feature/HomeTest.php` (`git mv`; same intent, retargeted)
- Modify: `tests/Feature/Auth/AuthenticationTest.php:22,64`, `tests/Feature/Auth/RegistrationTest.php:24`, `tests/Feature/ExampleTest.php:4`

**Interfaces:**
- Produces: route names `landing` (`/`, guests) and `home` (`/home`, `auth` + `verified`). Later phases add their nav items to `layouts/app/header.blade.php` and `components/bottom-nav.blade.php`.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/HomeTest.php`)

```php
<?php

use App\Models\User;

test('guests see the landing page', function () {
    $this->get(route('landing'))->assertOk()->assertSee('Bookflix');
});

test('authenticated users are sent from the landing page to home', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('landing'))
        ->assertRedirect(route('home'));
});

test('guests are redirected to login from home', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('authenticated users can visit home', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertSeeInOrder(['Home', 'Profile']);
});
```
Run `grep -rn "dashboard\|route('home')" tests resources app routes config` and retarget every hit: post-login and post-registration redirects → `route('home', absolute: false)`, the logout assertion and `ExampleTest` → `route('landing')`, `EmailVerificationTest` (from 1.2) → `route('home')`. Delete `resources/views/dashboard.blade.php`.

- [ ] **Step 2: Run to confirm failure**

Run: `php artisan test --compact tests/Feature/HomeTest.php tests/Feature/Auth`
Expected: FAIL, route `landing` not defined.

- [ ] **Step 3: Implement the routes, Fortify home, layouts and pages**

`Route::get('/', fn () => auth()->check() ? redirect()->route('home') : view('welcome'))->name('landing');` and inside the `auth`/`verified` group, `Route::livewire('home', 'pages::home.index')->name('home');`.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes config resources tests
git commit -m "feat: landing, home route and bookflix navigation shell"
```

---

### Task 1.4: Admin flag, Gate and admin area

**Files:**
- Create: `database/migrations/2026_10_23_000002_add_is_admin_to_users_table.php` (`boolean('is_admin')->default(false)`)
- Modify: `app/Models/User.php` (cast `is_admin` → bool; **not** added to `#[Fillable]`), `database/factories/UserFactory.php` (`admin()` state)
- Modify: `app/Providers/AppServiceProvider.php` (`Gate::define('access-admin', fn (User $user): bool => $user->is_admin)`)
- Create: `routes/admin.php` (prefix `admin`, name `admin.`, middleware `auth`, `verified`, `can:access-admin`), required from `routes/web.php`
- Create: `resources/views/pages/admin/⚡index.blade.php` (heading + link list that later phases extend)
- Create: `app/Console/Commands/GrantAdmin.php` (`bookflix:grant-admin {email}`)
- Test: `tests/Feature/Admin/AdminAccessTest.php`, `tests/Feature/Admin/GrantAdminCommandTest.php`

**Interfaces:**
- Produces: Gate `access-admin`, route `admin.index`, the `routes/admin.php` group every admin screen joins, and `User::factory()->admin()`.

- [ ] **Step 1: Write the failing tests**

```php
test('guests are redirected away from admin', function () {
    $this->get(route('admin.index'))->assertRedirect(route('login'));
});

test('non admins are forbidden from admin', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.index'))->assertForbidden();
});

test('admins can open admin', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))->assertOk();
});

test('is_admin cannot be mass assigned', function () {
    expect((new User)->isFillable('is_admin'))->toBeFalse();
});

test('grant admin promotes the user with that email', function () {
    $user = User::factory()->create();

    $this->artisan('bookflix:grant-admin', ['email' => $user->email])->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeTrue();
});

test('grant admin fails for an unknown email', function () {
    $this->artisan('bookflix:grant-admin', ['email' => 'nobody@example.com'])->assertFailed();
});
```

- [ ] **Step 2: Run to confirm failure.** Run: `php artisan test --compact tests/Feature/Admin`. Expected: FAIL, route `admin.index` not defined.
- [ ] **Step 3: Implement the files listed above** (`php artisan make:migration`, `make:command`).
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: admin gate, admin area, grant-admin command"`

---

### Task 1.5: Profiles: reading profile, avatar and privacy columns

**Files:**
- Create: `database/migrations/2026_10_23_000003_create_profiles_table.php` (columns in A6 `profiles`)
- Create: `app/Models/Profile.php`, `database/factories/ProfileFactory.php`, `app/Enums/ReadingLength.php` (`Short`, `Medium`, `Long`, `Any`), `app/Enums/Visibility.php` (`Private`, `Public`)
- Create: `config/bookflix.php` (`languages` => `['en','ja','ko','zh','fr','de','es','it','pt','ru']`, `avatar_max_kb` => `2048`, `privacy.deactivated_retention_days` => `env('BOOKFLIX_DEACTIVATED_RETENTION_DAYS', 30)`)
- Modify: `app/Models/User.php` (`profile(): HasOne` with `->withDefault([...column defaults])`), `app/Concerns/ProfileValidationRules.php` (add `readingProfileRules(): array`)
- Modify: `resources/views/pages/settings/⚡profile.blade.php` (a "Reading profile" form section: `bio`, `preferredLanguages` as a `flux:checkbox.group`, `readingLength` as `flux:radio.group`, `avatar` via `WithFileUploads` and a native file input, and method `updateReadingProfile(): void`)
- Test: `tests/Feature/Settings/ReadingProfileTest.php`

**Interfaces:**
- Produces: `$user->profile` always returns a `Profile` (unsaved defaults if there's no row; saved with `$user->profile()->updateOrCreate([], $data)`), `ReadingLength`, `Visibility`, and `config('bookflix.*')`.
- Favorite genres and media types (§8) need catalog tables; they arrive through `user_preferences` in task 5.8.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\ReadingLength;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake(config('filesystems.default')));

test('reading profile can be updated', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.profile')
        ->set('bio', 'I read everything.')
        ->set('preferredLanguages', ['en', 'ja'])
        ->set('readingLength', 'short')
        ->call('updateReadingProfile')
        ->assertHasNoErrors();

    $profile = $user->refresh()->profile;
    expect($profile->bio)->toBe('I read everything.')
        ->and($profile->preferred_languages)->toBe(['en', 'ja'])
        ->and($profile->reading_length)->toBe(ReadingLength::Short);
});

test('unsupported languages are rejected', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.profile')
        ->set('preferredLanguages', ['xx'])
        ->call('updateReadingProfile')
        ->assertHasErrors(['preferredLanguages.0']);
});

test('bio is limited to 1000 characters', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.profile')
        ->set('bio', str_repeat('a', 1001))
        ->call('updateReadingProfile')
        ->assertHasErrors(['bio' => 'max']);
});

test('a new avatar replaces the previous file', function () {
    $this->actingAs($user = User::factory()->create());
    $disk = Storage::disk(config('filesystems.default'));

    Livewire::test('pages::settings.profile')->set('avatar', UploadedFile::fake()->image('a.jpg', 400, 400))->call('updateReadingProfile');
    $first = $user->refresh()->profile->avatar_path;
    Livewire::test('pages::settings.profile')->set('avatar', UploadedFile::fake()->image('b.png', 400, 400))->call('updateReadingProfile');

    $disk->assertMissing($first);
    $disk->assertExists($user->refresh()->profile->avatar_path);
});

test('avatar must be a jpg, png or webp under 2 MB', function (UploadedFile $file) {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.profile')
        ->set('avatar', $file)
        ->call('updateReadingProfile')
        ->assertHasErrors(['avatar']);
})->with([
    'pdf' => fn () => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
    'too big' => fn () => UploadedFile::fake()->image('big.jpg')->size(3000),
]);
```

- [ ] **Step 2: Run to confirm failure.** Run: `php artisan test --compact tests/Feature/Settings/ReadingProfileTest.php`. Expected: FAIL, property `bio` not found on the component.
- [ ] **Step 3: Implement.** Rules: `bio` nullable string max 1000; `preferredLanguages` array, each `Rule::in(config('bookflix.languages'))`; `readingLength` `Rule::enum(ReadingLength::class)`; `avatar` nullable `image|mimes:jpg,jpeg,png,webp|max:2048`. Store at `avatars/{user_id}/{ulid}.{ext}` and delete the old file after the new one saves. `ponytail:` no resizing; add an image library if avatar bandwidth becomes a problem.
- [ ] **Step 4: Run the tests.** Expected: PASS (along with the existing `ProfileUpdateTest`).
- [ ] **Step 5: Commit** — `git commit -m "feat: reading profile, avatar upload, profiles table"`

---

### Task 1.6: Account deactivation replaces deletion (Review Focus #5)

**Files:**
- Create: `database/migrations/2026_10_23_000004_add_deactivated_at_to_users_table.php`
- Modify: `app/Models/User.php` (cast `deactivated_at` datetime; `isDeactivated(): bool`; `scopeActive(Builder $query): void`), `database/factories/UserFactory.php` (`deactivated()` state)
- Rename + modify: `resources/views/pages/settings/⚡delete-user-form.blade.php` → `⚡deactivate-account-form.blade.php` and `⚡delete-user-modal.blade.php` → `⚡deactivate-account-modal.blade.php` (method `deactivateAccount(Logout $logout): void`: validate the current password, set `deactivated_at`, delete the user's rows in `sessions`, log out, redirect `/`; copy for deactivation and the 30-day purge); update the include in `⚡profile.blade.php`
- Modify: `app/Providers/FortifyServiceProvider.php` (`Fortify::authenticateUsing`: valid credentials + deactivated → `ValidationException` on `email`: "This account has been deactivated.")
- Create: `app/Http/Middleware/EnsureAccountIsActive.php` (a deactivated user who's logged in → log out, invalidate the session, redirect to `login`). Append it to the `web` group in `bootstrap/app.php`. This is the one check every login path goes through: password, **passkey** and remember-me.
- Create: `app/Console/Commands/PurgeDeactivatedAccounts.php` (`bookflix:purge-deactivated-accounts`: delete users where `deactivated_at < now() - retention days`, deleting the avatar file too); schedule it `daily()` in `routes/console.php`
- Test: `tests/Feature/Settings/DeactivateAccountTest.php`; remove the two delete-account tests from `tests/Feature/Settings/ProfileUpdateTest.php` (**approval: they're replaced by the deactivation tests, not dropped**)

**Interfaces:**
- Produces: `User::isDeactivated()`, `User::active()` scope (used by every public surface from Phase 10 on, and by training exports in Phase 6), and the `deactivated()` factory state.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('user can deactivate their account', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.deactivate-account-modal')
        ->set('password', 'password')
        ->call('deactivateAccount')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh()->deactivated_at)->not->toBeNull()
        ->and(auth()->check())->toBeFalse();
});

test('correct password is required to deactivate', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.deactivate-account-modal')
        ->set('password', 'wrong-password')
        ->call('deactivateAccount')
        ->assertHasErrors(['password']);

    expect($user->fresh()->deactivated_at)->toBeNull();
});

test('deactivating removes the users other sessions', function () {
    $this->actingAs($user = User::factory()->create());
    DB::table('sessions')->insert(['id' => 'other', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

    Livewire::test('pages::settings.deactivate-account-modal')->set('password', 'password')->call('deactivateAccount');

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('deactivated users cannot log in with a password', function () {
    $user = User::factory()->deactivated()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a deactivated user with a live session is logged out on the next request', function () {
    $this->actingAs(User::factory()->deactivated()->create())
        ->get(route('home'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('purge deletes only accounts past the retention window', function () {
    $expired = User::factory()->create(['deactivated_at' => now()->subDays(31)]);
    $recent = User::factory()->create(['deactivated_at' => now()->subDays(29)]);
    $active = User::factory()->create();

    $this->artisan('bookflix:purge-deactivated-accounts')->assertSuccessful();

    expect($expired->fresh())->toBeNull()
        ->and($recent->fresh())->not->toBeNull()
        ->and($active->fresh())->not->toBeNull();
});
```

- [ ] **Step 2: Run to confirm failure.** Run: `php artisan test --compact tests/Feature/Settings/DeactivateAccountTest.php`. Expected: FAIL, component `pages::settings.deactivate-account-modal` not found.
- [ ] **Step 3: Implement the files listed above** (load `fortify-development` before editing `FortifyServiceProvider`).
- [ ] **Step 4: Run the tests.** Run: `php artisan test --compact tests/Feature/Settings tests/Feature/Auth`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: account deactivation with retention purge"`

---

### Task 1.7: Privacy settings page

**Files:**
- Create: `resources/views/pages/settings/⚡privacy.blade.php` (`flux:radio.group` for profile and library visibility; `flux:switch` for personalization; method `updatePrivacy(): void`)
- Modify: `routes/settings.php` (`Route::livewire('settings/privacy', 'pages::settings.privacy')->name('privacy.edit')` in the `auth` + `verified` group), `resources/views/pages/settings/layout.blade.php` (nav item)
- Modify: `app/Models/User.php` (`personalizationEnabled(): bool` → `$this->profile->personalization_enabled`)
- Test: `tests/Feature/Settings/PrivacySettingsTest.php`

**Interfaces:**
- Produces: `User::personalizationEnabled(): bool`, which `InteractionRecorder` checks (task 5.1) and which the training export filters on (task 6.2).

- [ ] **Step 1: Write the failing tests**

```php
test('privacy page is displayed', function () {
    $this->actingAs(User::factory()->create())->get(route('privacy.edit'))->assertOk();
});

test('privacy defaults are private with personalization on', function () {
    $user = User::factory()->create();

    expect($user->profile->profile_visibility)->toBe(Visibility::Private)
        ->and($user->profile->library_visibility)->toBe(Visibility::Private)
        ->and($user->personalizationEnabled())->toBeTrue();
});

test('privacy settings can be updated', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.privacy')
        ->set('profileVisibility', 'public')
        ->set('libraryVisibility', 'private')
        ->set('personalizationEnabled', false)
        ->call('updatePrivacy')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->profile->profile_visibility)->toBe(Visibility::Public)
        ->and($user->personalizationEnabled())->toBeFalse();
});

test('visibility must be private or public', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.privacy')
        ->set('profileVisibility', 'friends')
        ->call('updatePrivacy')
        ->assertHasErrors(['profileVisibility']);
});
```

- [ ] **Step 2: Run to confirm failure** (route `privacy.edit` not defined) → **Step 3: Implement** → **Step 4: Run the tests (PASS)** → **Step 5: Commit** `feat: privacy settings`

---

### Task 1.8: ML service skeleton and `MlClient` with shared contract fixtures

**Files:**
- Create: `ml/pyproject.toml` (Python `>=3.12,<3.13`; deps `fastapi`, `uvicorn[standard]`, `pydantic-settings`; dev `pytest`, `httpx`, `ruff`, `mypy`), `ml/uv.lock`, `ml/.env.example`, `ml/Dockerfile` (`python:3.12-slim`, `uv sync --frozen --no-dev`, `uvicorn app.main:app --host 0.0.0.0 --port 8000`)
- Create: `ml/app/__init__.py`, `ml/app/settings.py` (`ML_SERVICE_TOKEN`, `EMBEDDING_MODEL="intfloat/multilingual-e5-small"`, `EMBEDDING_DIMS=384`), `ml/app/auth.py` (`require_token` dependency using `hmac.compare_digest`), `ml/app/main.py` (`GET /health`; a `/v1` router with `require_token`; `GET /v1/models`)
- Create: `ml/tests/conftest.py`, `ml/tests/test_health.py`, `ml/tests/test_auth.py`, `ml/tests/test_contracts.py`
- Create: `tests/Fixtures/ml-contracts/models.json` → `{"embedding": {"name": "intfloat/multilingual-e5-small", "dims": 384}}`
- Create: `app/Ml/MlClient.php`, `app/Ml/MlServiceUnavailable.php`
- Modify: `config/services.php` (`'ml' => ['url' => env('ML_SERVICE_URL'), 'token' => env('ML_SERVICE_TOKEN'), 'timeout' => (int) env('ML_SERVICE_TIMEOUT', 5)]`), `.env.example`, `tests/Pest.php` (chain `->beforeEach(fn () => Http::preventStrayRequests())` onto the existing `pest()->extend(TestCase::class)…->in('Feature')`; Unit tests don't boot the app), `.github/workflows/tests.yml` (job `ml`: `astral-sh/setup-uv`, then in `ml/` `uv sync --frozen && uv run ruff check . && uv run mypy . && uv run pytest`)
- Test: `tests/Feature/Ml/MlClientTest.php`

**Interfaces:**
- Produces: `MlClient::health(): bool` (false on any non-2xx or connection error) and `MlClient::models(): array{embedding: array{name: string, dims: int}}` (throws `MlServiceUnavailable`). The pending request is built as `Http::baseUrl(url)->withToken(token)->timeout(timeout)->acceptJson()`. Phases 6–7 add the A8 methods on top of it.

- [ ] **Step 1: Write the failing Python tests**

```python
# ml/tests/test_auth.py
def test_health_is_public(client):
    response = client.get("/health")
    assert response.status_code == 200
    assert response.json() == {"status": "ok"}

def test_v1_requires_a_token(client):
    assert client.get("/v1/models").status_code == 401

def test_v1_rejects_a_wrong_token(client):
    assert client.get("/v1/models", headers={"Authorization": "Bearer nope"}).status_code == 401

# ml/tests/test_contracts.py
def test_models_response_matches_the_shared_fixture(client, auth_headers, contract):
    assert client.get("/v1/models", headers=auth_headers).json() == contract("models.json")
```
`conftest.py`: set `ML_SERVICE_TOKEN=test-token` before importing the app, and provide fixtures `client` (TestClient), `auth_headers` and `contract(name)`, which reads `../tests/Fixtures/ml-contracts/<name>`.

- [ ] **Step 2: Write the failing Pest tests**

```php
<?php

use App\Ml\MlClient;
use App\Ml\MlServiceUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => 'secret']));

test('models are read with the service token', function () {
    Http::fake(['ml.test/v1/models' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/ml-contracts/models.json')), true))]);

    expect(app(MlClient::class)->models()['embedding']['dims'])->toBe(384);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer secret'));
});

test('health is false when the service is down', function () {
    Http::fake(['ml.test/health' => Http::response(null, 503)]);

    expect(app(MlClient::class)->health())->toBeFalse();
});

test('models throws when the service is unavailable', function () {
    Http::fake(['ml.test/v1/models' => Http::response(null, 503)]);

    expect(fn () => app(MlClient::class)->models())->toThrow(MlServiceUnavailable::class);
});
```

- [ ] **Step 3: Run both suites to confirm failure.** Run: `cd ml && uv run pytest` (module `app.main` missing) and `php artisan test --compact tests/Feature/Ml` (class `MlClient` missing). Expected: FAIL on both.
- [ ] **Step 4: Implement the files listed above.**
- [ ] **Step 5: Run.** Run: `cd ml && uv run ruff check . && uv run mypy . && uv run pytest` and `php artisan test --compact`. Expected: PASS on both. Also run `docker build ml` and `docker run -e ML_SERVICE_TOKEN=x -p 8000:8000 …`, then `curl localhost:8000/health` → `{"status":"ok"}`.
- [ ] **Step 6: Commit** — `git commit -m "feat: ml service skeleton, MlClient, shared contract fixtures"`

---

### Task 1.9: Request context, JSON logs and security headers

**Files:**
- Create: `app/Http/Middleware/AssignRequestId.php` (reuse the incoming `X-Request-Id` if it's a valid UUID, otherwise generate `Str::uuid()`; `Context::add('request_id', $id)`; set the response header)
- Create: `app/Http/Middleware/SetSecurityHeaders.php` (`X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: DENY`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`)
- Modify: `bootstrap/app.php` (append both to the `web` group; `AssignRequestId` to `api` too when an API exists), `config/logging.php` (`json` channel: `single`/stderr driver with `Monolog\Formatter\JsonFormatter`), `app/Ml/MlClient.php` (`->withHeaders(['X-Request-Id' => Context::get('request_id')])` when present)
- Test: `tests/Feature/Http/RequestIdTest.php`, `tests/Feature/Http/SecurityHeadersTest.php`

**Interfaces:**
- Produces: `Context::get('request_id')`, available in logs and queued jobs automatically (Laravel `Context`). Every outgoing ML call carries the same ID.

- [ ] **Step 1: Write the failing tests**

```php
test('responses carry a generated request id', function () {
    expect(Str::isUuid($this->get(route('landing'))->headers->get('X-Request-Id')))->toBeTrue();
});

test('a valid incoming request id is reused', function () {
    $id = (string) Str::uuid();

    $this->withHeader('X-Request-Id', $id)->get(route('landing'))->assertHeader('X-Request-Id', $id);
});

test('an invalid incoming request id is replaced', function () {
    $header = $this->withHeader('X-Request-Id', '<script>')->get(route('landing'))->headers->get('X-Request-Id');

    expect($header)->not->toBe('<script>')->and(Str::isUuid($header))->toBeTrue();
});

test('the request id is shared through context', function () {
    Route::middleware('web')->get('/_test/context', fn () => Context::get('request_id'));

    $response = $this->get('/_test/context');

    expect($response->getContent())->toBe($response->headers->get('X-Request-Id'));
});

test('the ml client forwards the request id', function () {
    config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => 'secret']);
    Http::fake(['ml.test/health' => Http::response(['status' => 'ok'])]);
    Context::add('request_id', 'abc-123');

    app(MlClient::class)->health();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Request-Id', 'abc-123'));
});

test('security headers are present', function () {
    $this->get(route('landing'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'DENY');
});
```

- [ ] **Step 2: Run to confirm failure** (no `X-Request-Id` header) → **Step 3: Implement** → **Step 4: Run the tests (PASS)** → **Step 5: Commit** `feat: request id context, json logs, security headers`

---

### Phase 1 exit criteria
- [ ] `composer test` is green on Postgres; the `ml` job is green; CI is green on the PR.
- [ ] F0–F3 done; the 1.2/1.3 screens match the Figma frames at 1440 and 390 (F7 design QA).
- [ ] `security-review` and `design:accessibility-review` pass on the branch.
- [ ] The user runs the full `php artisan test --compact`.
- [ ] Optional: you ask for `infer-conventions` to record the conventions Phase 1 established in `.ai/rules`.

---

# Part E — Phases 2–11 (milestone outlines; each gets its own detailed plan)

Each outline gives the goal, prerequisites, tables (A6), tasks (each a single TDD unit with its key tests) and exit criteria. When a phase starts, `superpowers:writing-plans` expands it to the Phase 1 level of detail, against the code as it exists then.

## Phase 2 — Catalog and ingestion (§5, §9, §10, §42, §43)
**Needs:** Phase 1; F4 admin screens; Q9, Q10, Q15 answered; Horizon approved. **Tables:** catalog block of A6.
1. **Catalog schema + models + factories + seeding of the 10 `media_types`.** Tests: relationships; families and reading modes seeded; slug uniqueness; `alternative_titles` cast to an array.
2. **Redis + Horizon (D-9).** Horizon dashboard behind `access-admin`. Tests: non-admins get 403 on `/horizon`.
3. **`MetadataProvider` + Open Library adapter.** Tests with recorded fixtures: work + edition → `NormalizedMedia` (ISBN-13, DDC `813.54`, subjects, cover URL, creators with roles).
4. **AniList adapter.** Tests: format + country → manga, manhwa, manhua or light novel; `isAdult` skipped (Q15); staff roles → creator roles.
5. **Deduplicator** (A9 bands). **Review Focus #1** tests: the same work from both providers becomes one media with 2 external IDs; "Monster" manga vs novel don't merge; 0.75–0.90 similarity creates a `duplicate_candidates` row.
6. **`IngestionPipeline` + `ImportMediaRecord`.** Tests: re-import with the same payload hash changes nothing; a field in `locked_fields` isn't overwritten; an invalid record is skipped and counted; everything happens in one transaction.
7. **`SyncProvider` job, scheduler, rate limiting, retries, `ingestion_runs`.** **Review Focus #4** tests: 429 → released with backoff; 5xx until tries run out → run `failed`, existing media untouched; malformed payload → `skipped` +1; retries don't duplicate rows.
8. **Admin: providers and sync runs, media edit (with field locking), duplicate review and merge, remove invalid record (soft delete).** Tests: a merge moves external IDs, pivots and relations, and the loser's slug redirects with a 301; non-admins get 403.
9. **(2b, parallel) MangaDex, Metron, VNDB, Taddy adapters,** one task each with the task 3/4 test template.
10. **Admin users list** (read-only; spec §43 "Users"). Tests: admins only; active/deactivated status; search; no secrets in HTML.
**Exit:** 10k+ stories synced from 2 providers with no duplicates; runs visible in admin; Horizon green.

## Phase 3 — Dewey classification (§11–§19, §49)
**Needs:** Phase 2; Q1 answered; F4 explore screens. **Tables:** `dewey_classes`, `media_dewey_classes`.
1. **Hierarchy seed (CSV) + `DeweyHierarchy`.** Tests: 10 roots; children of 800 include 810; ancestors of 813 = [800, 810]; subtree query by path; `similarity(813, 823)` = 1/3 (they share only 800 out of 3 levels).
2. **`media_dewey_classes` + `ClassificationService::assign` + `DeweyValidator`.** Tests: a second primary is rejected (partial unique index + service); a fourth secondary is dropped; confidence below the minimum is dropped.
3. **`DeweyResolver` + `DeweyMapper` + the classify step in ingestion.** Tests from §14/§15: Dune → primary 813, external, confidence 0.95; The Martian → primary 813 with secondaries 500 and 600 from subjects; unknown code `999.99` → null; manga → per Q1.
4. **Explore pages** (`/explore`, `/explore/{code}`, breadcrumbs, counts). Tests: class → division → section → stories; pending with confidence below the public minimum is hidden; rejected is hidden.
5. **Classification shelves (§17)** for the catalog; Phase 5 reuses them for the library. Test: shelves are grouped by root and division and are empty-safe.
6. **Admin review queue:** review, accept, reject, change, add, remove (§19). Tests: each action updates `review_status`/`reviewed_by`/`reviewed_at`; changing the primary swaps it inside a transaction; non-admins get 403.
**Exit:** ≥ 80% of the catalog has an accepted or confidently public primary class; the review queue works.

## Phase 4 — Discovery (§20, §21 keyword leg, §35 non-personal, §51–§52)
**Needs:** Phase 3; Scout, Motion, `@alpinejs/ui` and noUiSlider approved; F4/F5 discovery screens signed off.
0. **Component kit.** It starts with one setup task, run by a single subagent: bundle Livewire's ESM build in `resources/js/app.js`, register `@alpinejs/ui` and the `motion.js` directives on that Alpine instance, and switch the layouts to `@livewireScriptConfig` (check with `search-docs`). Once setup lands, **one implementer subagent per component, run in parallel** (`superpowers:dispatching-parallel-agents`; independent files): `cover-tile`, `shelf`, `hero`, `story-info`, `cta`, `accordion`, `tabs`, `rating-input`, `combobox`, `command-palette`, `range-filter`, `stepper`, `story-preview`, plus the filter sheet on `flux:modal variant="flyout"`. Sources come from the C6 gap map. Every component gets the same acceptance tests: it renders with tokens only (a grep check finds no raw hex), passes its APG keyboard pattern, `assertNoAccessibilityIssues()`, and doesn't animate under reduced motion. The reviewer compares it with its F3 frame and checks that any copied code keeps its license header.
1. **Scout database engine + `search_text` maintenance + indexes.** **Review Focus #2** tests: `Pokemon` finds `Pokémon`; `進撃の巨人` and `Shingeki no Kyojin` find Attack on Titan through alternative titles; `Berserl` finds Berserk through trigram; the generated SQL matches the GIN index (`toRawSql` assertion).
2. **`SearchFilters` + filters** (type, genre, theme, language, year range, rating, status, length bucket, author, publisher, Dewey subtree). Tests: one per filter, plus a combination.
3. **Search page** (`#[Url]` state, debounce, pagination, zero-results state) + `search_queries` logging + click-through. Tests: a query creates a row; refinement links `previous_query_id`; zero results logged; opted-out users logged with `user_id` null.
4. **Story detail page** (identity, format, classification, genre, themes, tags, series, related, content-warnings accordion, attribution, simple "similar" through SQL: same series, genre and Dewey). Tests: sections render; soft-deleted → 404; a merged slug → 301.
5. **Series page with ordering.** Test: order 1, 1.5, 2.
6. **Discover page** (by media type, genre, subject entry). Test: filters by family.
7. **Home v1** (hero, Trending gradient shelf via the interim `Media::scopeTrending()`, New Releases, Explore by Subject) + components `cover-tile`, `shelf`, `hero`, `story-info`, `cta` + `motion.js` directives (C5). Tests: user-independent queries (D-26); shelves render without N+1 (query count assertion); reduced motion respected (browser test).
8. **Browser smoke tests + accessibility** (Pest browser): landing → login → home → search → detail at 1440 and 390.
**Exit:** discovery works end to end; Lighthouse performance ≥ 90 on Home at 390.

## Phase 5 — Personal library (§36, §38, §39 ratings, §41, §44, §60 export)
**Needs:** Phase 4; F4 library screens. **Tables:** the library and signals block.
1. **`user_library` + actions** (add, remove, status, favorite) + **`InteractionRecorder`**. **Review Focus #5** test: when personalization is off, no `user_interactions` rows are created; when it's on, one queued insert per event with context.
2. **Library page** (status, type and favorite filters; sort by added, title, last read, rating; classification shelves). Tests: each filter and sort; only the owner sees a private library.
3. **Collections CRUD + ordering + visibility + `is_editorial` for admins.** Tests: only the owner edits; positions reorder; non-admins can't set editorial.
4. **Reading progress (manual) + `reading_history` + Continue Reading shelf.** Tests: percent is limited to 0–100; reaching 100 sets `completed` and records `reading_complete`; the shelf is ordered by `last_read_at`.
5. **Ratings 1–10 + an aggregate job.** The readers' aggregate is written to `media_metrics` under a synthetic `bookflix` source (`ratings_count`, `average_rating` = mean score ÷ 2), then the media's blended `average_rating`/`ratings_count` are recomputed with the same blend the ingestion pipeline uses (D-36). Tests: upsert per user; the aggregate is recomputed from active users only; rating outside 1–10 rejected; removing the last rating leaves the external average in place.
6. **History page.** Test: sessions are listed with total reading time.
7. **Data export** (queued JSON zip, signed link that expires in 24 h). Tests: contains profile, library, collections, ratings, history and interactions; another user's link → 403; an expired link → 403.
8. **Onboarding (§33):** types → genre families and subgenres (the taxonomy tree; the mature family only for readers past the gate) → ≥ 3 stories → `user_preferences` + `onboarded_at`. Accounts that exist before the phase are backfilled as onboarded (D-37). Tests: fewer than 3 stories → validation error; preferences saved; skipping is allowed.
9. **Merge safety for user data:** `Deduplicator::merge` carries library, ratings, progress, history, collection items and interactions to the winner without unique-key crashes.
**Every list in this phase goes through `Media::visibleTo($viewer)`** (library, collections, Continue Reading, history, onboarding picks); gated entries stay stored and reappear when the reader opts in. The export holds all of the reader's own rows.
**Exit:** the MVP library flow works; export works.

## Phase 6 — Recommendation MVP (§22–§24, §27–§29, §31–§35, §45 baseline)
**Needs:** Phase 5; F4 personalized-home screens; `backend-architect` review of A12. **Tables:** the intelligence block.
1. **ML preprocessing + TF-IDF + LSA training job** → `media_lsa_vectors` + `model_versions`. pytest: on a fixed fixture corpus, two space-opera synopses are closer to each other than to a cookbook; vectors have 200 dimensions; reruns are deterministic.
2. **Item-CF job** using the `interaction-weights` model version. pytest: tiny matrix → expected neighbors; **Review Focus #5**: deactivated and opted-out users are excluded from the export query.
3. **`media_similarities` writer (top 50 per method) + popularity materialized view + refresh.** Tests: no self-neighbors; top-k ordering.
4. **CandidateSources:** ContentSimilar, Collaborative, DeweySimilar, Trending, PopularInGenres, NewReleases, Editorial, Exploration. Unit tests per source on factory data.
5. **`HybridRanker` + `RankingWeights`** (from the active `hybrid-weights` version, falling back to config). Unit tests: changing a weight changes the order; a missing feature counts as 0; scores fall in [0, 1].
6. **`Diversifier` (MMR) + ε-exploration.** Unit test (§31): 20 fantasy candidates plus a user with broad interests → at most 4 fantasy items in the top 10.
7. **Explanations.** Tests: each `reason_type` renders its template with titles; no reason → no label.
8. **`ShelfBuilder` + personalized Home + caching + recommendation and event logging.** **Review Focus #3** test: a user with zero interactions gets at least 4 non-empty shelves; a user with one interaction doesn't error. Cache cleared when the library changes.
9. **Scheduling** (popularity hourly, item-CF nightly, LSA weekly) + admin model-version page + ML health. Tests: the schedule is registered; a job failure is shown in admin.
10. **Offline evaluation baseline** (A12 metrics written to `metrics`; promotion gate). pytest: metrics on a fixture with known answers; a regression above 2% blocks promotion.
**Exit:** the MVP is complete (A17 exit test passes).

## Phase 7 — NLP and semantic intelligence (§21, §25, §26, §47 rule-based)
1. **`/v1/embed` + batch embed job → `media_embeddings` (HNSW).** pytest: e5 prefixes applied; 384 dimensions. Pest: job upserts per active version.
2. **Semantic search leg + RRF.** Tests: fusion order on fixtures; ML timeout → FTS-only results (no exception); query embeddings cached.
3. **Rule-based `QueryParser`.** Tests with the §47 queries: "dark fantasy manga with political intrigue" → manga + fantasy + politics; "short science-fiction novel I can finish this weekend" → novel + sci-fi + length short.
4. **`/v1/summarize` (LSA extractive) + summaries for long descriptions, author bios and series descriptions.** pytest: deterministic output; sentences kept in original order; text shorter than the limit comes back unchanged.
5. **Semantic recommendation source.** Unit test: the source contributes candidates with reason `similar_themes`.
6. **`DeweyClassifier` (kNN + LSA logistic regression) → pending candidates.** pytest: accuracy above baseline on a fixture split; Pest: low confidence → pending and not public.

## Phase 8 — Advanced recommendations (§30–§32, §45)
1. **ALS model + candidate source.** Promote only if the evaluation gate passes.
2. **LTR (LightGBM LambdaMART)** on the §30 features, with labels from `recommendation_events`. Starts only once there are ≥ 50k labeled impressions; nightly precomputed ranking for active users; `HybridRanker` remains the fallback.
3. **Novelty and diversity in evaluation + exploration upgrade** (Thompson sampling per shelf) only if the data supports it (§32).
4. **Admin model comparison** across versions (§45 "compare model versions over time").

## Phase 9 — Reader (§6, §37)
1. **`content_sources` + `ContentAccessGate` policy + admin verification + public-domain importer** (Gutenberg / Standard Ebooks feeds). Tests: unverified source → 403; `user_owned` → only the owner.
2. **Signed, short-lived streaming route with HTTP range support; CSP introduced.** Tests: expired signature → 403; range request → 206.
3. **EPUB reader (foliate-js; C6) with progress sync** (debounced; stores foliate-js's location, using a CFI where it provides one). Tests: progress saved; resumes at the saved locator; switches between paginated and scrolled without reloading.
4. **PDF reader (pdf.js).**
5. **CBZ/image/manga reader:** pages extracted server-side; RTL for manga, vertical scroll for webtoons (from `reading_mode`).
6. **CBR → CBZ conversion** (Q11).
7. **User-owned uploads** (MIME and size checks, private storage).
8. **Reader chrome from the VideoPlayer kit** (next chapter, reading settings, display panel) + accessibility (keyboard paging, focus, reduced motion).

## Phase 10 — Social (§39 reviews, §40)
Tasks: public profiles and libraries (respect privacy settings; `User::active()` only) · following · reviews CRUD with spoilers + LSA aggregate review summary · reports + moderation queue (hide, dismiss) · shared collections · discussions (per-story threads; lowest priority) · social recommendation source ("Popular with readers you follow") · activity feed. Key tests: private stays private; a deactivated user is invisible; hidden reviews are excluded from aggregates; only admins moderate.

## Phase 11 — Bookflix AI (§47, §25 abstractive, §34)
Tasks: LLM intent extraction behind the `QueryParser` interface, falling back to rules (cached, per-user rate limit) · discovery assistant with server-side tools (search, filters, recommendations) where **every title mentioned is checked against the catalog** · abstractive summaries labeled "AI-generated", stored with a model version · richer explanations. Use the `claude-api` skill and current Claude models; run `security-review` for prompt injection through catalog text.

---

# Part F — Delivery loop (every phase; §53, §57)

1. **Design gate:** the phase's F4 screens pass F6 (critique → accessibility → copy → your sign-off).
2. **Settle questions:** `superpowers:brainstorming` only for that phase's open Q-items.
3. **Plan:** `superpowers:writing-plans` → `docs/superpowers/plans/YYYY-MM-DD-phase-N-<name>.md`.
4. **Isolate:** `superpowers:using-git-worktrees` → branch `phase-N-<name>`; a Neon branch for the PR preview.
5. **Execute:** `ultraclaude:ultraclaude-milestone-execution` / `superpowers:subagent-driven-development` with `opus-orchestration`; `superpowers:test-driven-development` per task; review between tasks.
6. **Branch review:** `/code-review`, `security-review` (when relevant), `design:accessibility-review`, `ponytail:ponytail-review`, F7 design QA screenshots.
7. **Verify:** `superpowers:verification-before-completion`: `composer test`, the ML suite, the browser suite; you run the full `php artisan test --compact`.
8. **Ship:** `superpowers:finishing-a-development-branch` → PR → CI → merge → Laravel Cloud deploy (`deploying-to-cloud`); ML service deployed via a GitHub Action on `ml/**` changes.
9. **Record:** update the G2 decision log and mark the phase done in this plan.

---

# Part G — Coverage and decisions

## G1. Spec coverage matrix

| Spec § | Where |
|---|---|
| 1–3 vision, philosophy | Goal, A2, Global Constraints |
| 4 stack | Tech Stack, A2, A4, D-5, D-9 |
| 5 providers | A9, Phase 2 |
| 6 legal model | Global Constraints, A6 `content_sources`, R1, Phase 9 |
| 7 domain model | A6, D-1, D-6, D-7 |
| 8 auth and profiles | A1, Tasks 1.3–1.7, 5.8 |
| 9 catalog fields | A6 `media`, Phase 2 |
| 10 series | A6, 4.5 |
| 11–19 Dewey | A10, Phase 3, 7.6 |
| 20–21 search | A13, Phase 4, 7.2–7.3 |
| 22–29 recommendations | A11, A12, Phase 6 |
| 30–32 LTR, diversity, exploration | 6.6, Phase 8 |
| 33 cold start | A12, 5.8, 6.4 |
| 34 explainability | A12, 6.7 |
| 35, 52 homepage | A12 shelf table, 4.7, 6.8 |
| 36 library | Phase 5 |
| 37 reader | Phase 9 |
| 38 history | 5.4, 5.6 |
| 39 ratings and reviews | 5.5, Phase 10 |
| 40 social | Phase 10 |
| 41 personalization | 1.5, 1.7, 5.8 |
| 42 ingestion | A9, Phase 2 |
| 43 admin | 1.4, 2.8, 2.10, 3.6, 6.9, 8.4, Phase 10 moderation |
| 44 analytics | A6 `user_interactions`, 5.1 |
| 45 evaluation | A12, 6.10, Phase 8 |
| 46 search analytics | A13, 4.3 |
| 47 NL discovery | 7.3, Phase 11 |
| 48, 64 architecture | A2 |
| 49 classification service | A5, A7, A10, D-8 |
| 50 story representation | A6 (separate tables for type, classification, genre, theme, tag, vectors) |
| 51 UI/UX | Part C, 1.2, 1.3, C5 |
| 53 methodology | Part F |
| 54 phases | Parts D, E |
| 55 MVP | A17 |
| 56 initial deliverable | Part A (items 1–16), Parts D–E (17–18) |
| 57 quality gates | A15, Part F |
| 58 observability | A16, 1.9 |
| 59 security | A16, 1.4, 1.6, 1.9, 9.2 |
| 60 privacy | A16, 1.6, 1.7, 5.1, 5.7 |
| 61 performance | A16, 4.7 |
| 62 scalability | A2 |
| 63 model versioning | A6 `model_versions`, A11 |
| 65 product goal | A17 exit test |

## G2. Decision log (fill in as you decide)

| Date | Item | Decision | By |
|---|---|---|---|
| 2026-10-22 | UI library | Flux UI free edition only | user |
| 2026-10-22 | Animation | Motion (motion.dev), free core, vanilla API through Alpine | user |
| 2026-10-22 | Design reference | Figma `vHWecJSYA5Afuwr0ntLzbA` (Netflix DS 2024 Website) | user |
| 2026-10-22 | Execution method | **Subagent-driven**: one implementer subagent per task, a review between tasks, a whole-branch review before merge (`superpowers:subagent-driven-development` + `ultraclaude:ultraclaude-milestone-execution`, Opus 5.5 steering, Sonnet 5.5 workers) | user |
| 2026-10-22 | Bookflix · Design System file | Reference duplicated by the user; **file key pending** (share the URL) | user |
| 2026-10-22 | Component sourcing | **Open-source libraries only** (MIT / Apache-2.0 / ISC / OFL); C6 list adopted (`@alpinejs/ui`, Motion, noUiSlider, foliate-js, pdf.js, Chart.js; Pines/Basecoat as markup references); paid suites dropped | user |
| 2026-10-22 | Flux free's proprietary license (Q17) | **Keep Flux free as the base UI library; the open-source-only rule applies to everything added** | user |
| 2026-10-23 | D-19 `merged_into_id` for reversible merges | adopt: column on `media`, loser soft-deleted, 301 from its slug. Rationale: A9 required redirects with no storage for them. | user (via lead session) |
| 2026-10-23 | D-20 ISBN storage | adopt: synthetic `isbn` external source. Rationale: reuses the unique constraint, no new schema. | user (via lead session) |
| 2026-10-23 | D-21 dedup gap (≥ 0.90 without creator/year) | adopt: review candidate, never auto-merge. Rationale: false merges cost more than review time (R8). | user (via lead session) |
| 2026-10-23 | D-22 `search_queries` timing | adopt: created in Phase 4; click-through via `?sq=` until Phase 5. Rationale: task 4.3 needs it before `user_interactions` exists. | user (via lead session) |
| 2026-10-23 | D-23 interim trending | adopt: `scopeTrending` by `ratings_count`/recency in Phase 4, redefined in 6.3. Rationale: `media_popularity` arrives in Phase 6. | user (via lead session) |
| 2026-10-23 | D-24 `/v1/query/parse` | adopt: dropped; PHP `QueryParser`. Rationale: vocabulary lives in Postgres. | user (via lead session) |
| 2026-10-23 | D-25 schema additions beyond A6 | adopt (list in A6 "Added by phases"). Rationale: each backs a spec requirement. | user (via lead session) |
| 2026-10-23 | D-26 guest-safe meaning | adopt: user-independent queries, pages stay behind `auth` + `verified`. | user (via lead session) |
| 2026-10-23 | D-27 / Q13 spec not in repo | adopt: no copy; absolute path references. Rationale: public repo. | user |
| 2026-10-23 | D-28 spec interpretations (ε-greedy, opted-out recs, comics codes, admin users, extra ML job names) | adopt as written in A10/A12/A8/Phase 2.10. Rationale: spec silent. | user (via lead session) |
| 2026-10-23 | Phase 1 done (PR #4) | Part D marked done; navigation in `config('bookflix.navigation')`, `FILESYSTEM_DISK=local`, `flysystem-s3`/Nightwatch deferred to deploy, ML docs off by default | user |
| 2026-10-23 | D-13 reaffirmed | Phone bottom nav stays Home, Discover, Search, Library, Profile. Phase 1's placeholder bar (Home, Library, Option 1, Option 2, Settings) is replaced as Phases 4, 5 and 10 add their items to `config('bookflix.navigation')`; each phase must also mark which items show on the phone bar. | user |
| 2026-10-23 | D-17 font | Code keeps the spec's font stack (`'Netflix Sans', 'Helvetica Neue', 'Segoe UI', Roboto, Ubuntu, sans-serif`); Inter is used only in the Figma design system, because Figma couldn't load Helvetica Neue. Design QA (F7) ignores font-rendering differences. | user |
| 2026-10-25 | Design inspiration (C7) | 16 patterns adopted from SPL, YPRL, forms.world, ethanandtom, theperformancelab, ciphermuseum and the slider demo; NYPL and cinematypography unreadable; TypeUI added to Part B as inspiration-only; Bookflix logo (PR #8) recorded. Rationale: borrow interaction patterns within the fixed tokens/stack, no assets or layouts. | user (via lead session) |
| 2026-10-26 | C7 #1 hero slider motion | Updated from live inspection of nextjs-animated-slider: backdrop + left copy column + next-3 card row, card-to-backdrop morph via View Transitions with Motion fallback, line-by-line title reveal, progress line and large counter, no autoplay (no pause control), Bookflix 2:3 covers with "Start reading" and "Add to Library". Rationale: user preference; the earlier row came from a text-only fetch. | user |
| 2026-10-27 | Catalog expansion wave 1 (PR #11) | adopt: `media_metrics` per source, `popularity_score` blend, nine sources; exact normalized title + format family + year auto-merges | user |
| 2026-10-27 | Pager label | `__('Pages')`; a test rejects `__()` keys that name a translation group in any letter case (macOS crash, PR #10) | user |
| 2026-10-28 | D-29 MangaDex covers | `Referrer-Policy: same-origin`: other sites get no Referer, so hotlink-protected hosts serve the real cover (PR #12) | user |
| 2026-10-28 | D-30 Landing page | Guest `/`: hero with 3D books, trending Top 10 marquee with Netflix-style numerals, problem and solution sections in different layouts, Netflix-style FAQ with an email box (`POST /start`, email kept in the session), closing CTA, footer (PR #13) | user |
| 2026-10-28 | D-31 No three-card layouts | Never use a row of three cards for content sections; it reads as AI-generated design. Applies to every later page (stats, features, onboarding) | user |
| 2026-10-29 | D-32 Landing hero | Centred eyebrow, title and description; no buttons in the hero (the header keeps Sign in and Get started); seven books in a bowl around the text, five in a fan below 1024 px | user |
| 2026-10-28 | D-33 Taxonomy | `genre-taxonomy-spec.md` adopted: genre families and subgenres as a tree with secondary parents, independent themes, tropes, demographics, audience ratings and content warnings with present/absent/unknown/disputed statuses; filters OR within a group and AND between groups; excluding a warning keeps unknown ones | user |
| 2026-10-28 | D-34 Media-type classification | `MediaTypeClassifier` decides format from format fields, publishers, subjects, page vs chapter counts and country; prose wins ties; `bookflix:reclassify` re-derives stored data, respecting locked fields. Fixes novels labelled comics | user |
| 2026-10-28 | Prohibited content (hard rule) | Sexual content involving minors, or sexualized depictions of minors, is never supported: such works are rejected at ingestion (`ProhibitedContent`) | user |
| 2026-10-28 | D-35 Mature access gate | Adult-rated works and works tagged with a mature-family genre are hidden unless the reader turns on Settings → Privacy → "Show mature and adult titles" with an 18+ confirmation; guests are always gated; enforced in the query layer (`Media::visibleTo()`), a gated story URL returns 403 | user (via lead session) |
| 2026-10-29 | Merge order | #12 → #14 → #15 → #13, squash; each branch brought up to date with main before merging | user |
| 2026-10-29 | D-36 Reader ratings | `ratings.score` 1–10 per reader; the readers' aggregate is one more `media_metrics` source (`bookflix`), blended into `media.average_rating` (0–5) like any provider; Phase 6 decides its weight in the popularity score | user (via lead session) |
| 2026-10-29 | D-37 Onboarding for existing accounts | Accounts created before Phase 5 are backfilled `onboarded_at = now()` so nobody is forced through it; onboarding stays reachable from Settings → Preferences | user (via lead session) |
| — | D-1 … D-18, Q1 … Q17, A4 approvals | pending | — |
| — | Figma file keys: Bookflix · Design System / Prototypes / Architecture & Flows | pending (F0) | — |
