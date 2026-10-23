# Phase 2 execution ledger (append-only)

Plan: `2026-10-23-phase-2-catalog-ingestion.md`. It's on PR #3's branch, and until that merges executors read a copy in the controller's scratchpad. Branch: `phase-2-catalog`, cut from `phase-1-foundation` (PR #5). The PR opens only after PR #5 merges, then gets rebased onto `main`.

## Conflict scan
| Pair / task | Shared file or interface | Ruling |
|---|---|---|
| 2.3 × 2.5 × 2.7 | `config/bookflix.php` | Sequential |
| 2.2 × 2.6 × 2.7 | `AppServiceProvider` (Horizon gate, rate limiters) | Sequential |
| 2.6 × 2.7 | `ImportMediaRecord` | 2.6 creates it, 2.7 adds run counting |
| 2.3 × 2.4 | `MetadataProvider` contract and the DTOs | Same batch, contract first |
| 2.8 × 2.10 | `routes/admin.php`, admin index links | Same batch |
| 2.2 (self) | tests vs Redis | Tests stay Redis-free (phpunit uses array cache and sync queue); Redis only for local dev and Horizon |
| 2.3 (self) | fixture recording calls the live APIs once | Allowed: public read-only endpoints |
| 2.9 | 2b adapters | **Deferred** |

## Rulings (with the cost if wrong)
- R1. Batches: {2.1+2.2}, {2.3+2.4}, {2.5+2.6}, {2.7}, {2.8+2.10}. Cost: coarser review granularity.
- R2. `BOOKFLIX_PROVIDER_USER_AGENT=Bookflix/0.1 (+https://github.com/NightServant/bookflix)`, so no personal email goes to third parties. Cost: a provider wanting a mailbox might rate-limit us harder.
- R3. 2.9 (MangaDex, Metron, VNDB, Taddy) is deferred to save tokens; MVP providers per Q9 are Open Library and AniList. Cost: no comics, visual novels or webcomics until then.
- R4. `laravel/horizon` is approved by the phase start (master plan A4, Phase 2 row).
- R5. Local Redis runs in Docker as `bookflix-redis` (redis:7-alpine). The disk has about 2.2 GB free, so there are no other new images.

## Tasks
| Batch | Commits | Status | Open findings |
|---|---|---|---|
| 2.1+2.2 | `98770cd`, `cb32e8b` | DONE: 93 tests pass; Horizon gated by `access-admin`; tests Redis-free | F1: manhwa/manhua seeded as `paged`, fix in the next batch (R6). F2: `duplicate_candidates` has no unique key, add it in 2.5 |

| F1+2.3+2.4 | `48982fb`, `5786abd`, `f58ce02` | DONE: 111 tests pass; fixtures recorded live with the R2 User-Agent and trimmed | none |

| 2.5+2.6 | `11a2b12`, `0ae49a8` | DONE: 132 tests pass; Review Focus #1, #3 and #5 tested; F2 unique key with (min, max) pairs | C1: `RateLimited` releases use up `$tries`, so 2.7 uses `retryUntil` + `$maxExceptions`. C2: `merge()` must refresh `search_text` and resolve candidates that point at the loser, in 2.8 |

| 2.7 | `a643fe4` | DONE: 146 tests pass; C1 proven with a worker test (a negative control fails without `retryUntil`); Review Focus #2 covered | F3: Redis `retry_after` 90 < Horizon timeout 120. F4: scheduled `SyncProvider` can overlap. Both go to the 2.8 batch |

| C2+F3+F4, 2.8, 2.10 | `c547cc3`, `fe20b3a`, `5ed3f6a` | DONE: 193 tests pass. C2: the merge rebuilds `search_text`, resolves candidates and repoints chains. F3: `retry_after` 180. F4: `ShouldBeUnique` per provider | Incident: dev DB `bookflix` wiped by `migrate:fresh --env=testing` (no `.env.testing`) |

- **Whole-branch review (Opus): MERGE AFTER FIXES.** No critical findings.
  - I1: the rate limit was on the I/O-free import job; the fetch was one unthrottled, unresumable job, so the first sync loops on 429s and timeouts.
  - I2: a single permanently failing record froze the cursor.
  - I3: runs got stuck in `running`, and every retry added a row.
  - M1: no row locks in merge or import.
  - M2: merge closed third-party pairs nobody had reviewed.
  - M3: the creator guard was untested.
  - M4: provider URLs and HTML weren't sanitized.
  - M5: two sources of truth for `enabled`.
  - M6: provider authority flipped on merge.
  - All go to one fix wave.
- R10. I1 redesign: `MetadataProvider::page()` returns a `ProviderPage`. `FetchProviderPage` jobs, one per page, are rate-limited and resumable through the run's `page_token`, import inline, and are capped by `max_pages_per_run` (default 20). This changes the A7 contract, so the Phase 2b adapters must implement `page()`. Cost: the master plan's A7 text is out of date until it's updated.
- R11. The cursor advances when a run's pages finish, even if some records fail (`partial`), and the failed IDs are kept in `counts.failed_ids`. Cost: a record that fails permanently is only retried when its provider updates it.
- The fix wave is `8cde07e`; 227 tests pass.
  - I1 as built: `SyncProvider` dispatches `FetchProviderPage`, the run resumes from its `page_token`, and `ImportMediaRecord` and `changedSince()` are deleted.
  - Added: stale runs (idle for 3h) are marked failed; the `SanitizesProviderData` trait.
  - Open Library pages hold 10 changes, at 2 page jobs per minute. Re-check against Open Library's etiquette before enabling it.
- Follow-up: the cursor moves to `run.started_at − overlap` instead of the newest `updatedAt` seen, closing the gap where a record updated mid-walk was missed.
- Dev DB repair (controller): migrations edited in place left the dev catalog tables stale. Fixed by dropping only the catalog tables (in `down()` order, minus the column drop), deleting their 2 migration rows, re-migrating, and re-seeding `MediaTypeSeeder` and `ExternalSourceSeeder`. `users` is intact. Lesson: `migrate:rollback --path` only acts on the latest batch; and never pipe artisan into `tail`, because it hides the exit code.
- R7. "Reversible merge" = master plan A9's definition: the loser is soft-deleted (kept) and its slug 301s to the winner. There's no unmerge action or move log (spec §43 asks only for "merging duplicates"). Cost if wrong: a bad merge needs a manual DB fix.
- R8. **Hard rule for every future brief: never run `migrate:fresh`/`db:wipe`/`migrate:refresh` or `--env=testing` artisan commands. Tests reset `bookflix_test` through `RefreshDatabase`; the dev DB `bookflix` is never reset.** Cost of the incident: local dev data lost (seed only); the user must re-register and re-run `bookflix:grant-admin`.
- R9. Exit criterion "10k+ stories synced" is replaced by a small live smoke sync at deploy time. A 10k live sync would hammer free public APIs and the nearly full disk. Cost: real-world volume behavior is unproven until then.
- Accepted deviations from 2.8/2.10:
  - The minimal `stories.show` route only does the 301 (Phase 4 replaces it).
  - "Sync now" refuses disabled sources.
  - Admin `boot()` authorizes on every request.
  - Only fields that actually changed get locked.
  - The `ShouldBeUnique` lock doesn't cover `dispatchSync` or the import batch (imports are idempotent).
- Accepted deviations from 2.7:
  - "Partial" status is tested via the batch `finally` callback.
  - A run is `failed`, `partial` or `succeeded` by its failure count, and the cursor only advances on `succeeded`.
  - A 5xx fails the run, and the next tick retries.
  - The schedule test uses `filtersPass()`.
  - `bookflix:sync` fetches in the foreground.
  - The whole fetch is held in memory (`ponytail:`, chunk large `--full` runs later).
- Accepted deviations from 2.5/2.6:
  - The band-test titles were swapped, because the plan's pair scored 0.967.
  - `validate(ProviderRecord, NormalizedMedia)`.
  - Enricher: the first provider owns overwrites, later providers only fill blanks, locked fields are never touched.
  - Cross-IDs are matched but not stored; only ISBNs are stored as `isbn` IDs.
  - Provider classes are resolved from config.
  - Title matching is a sequential scan (`ponytail:`, add an index at about 50k rows).
- After PR #5 merged, the branch was rebased onto `main` (`f614ba2`); the tree is unchanged.
- Accepted deviations from 2.3/2.4:
  - Test helper `providerFixture()`, because Pest already has `fixture()`.
  - `SendsProviderRequests` trait.
  - Providers disabled by default through env.
  - Open Library: follows redirect stubs; authors cost one extra request each (max 5); ISBNs and Dewey come from the editions feed; `series` is null; the recent-changes window is 7 days when there's no cursor (`ponytail:`).
  - AniList: sorts by `UPDATED_AT_DESC` and stops at the cursor; creators are Author/Artist/Illustrator only; an unknown country gives an empty slug, which the validator skips.
- R6. manhwa, manhua and webcomic default to `reading_mode = vertical_scroll`; manga, comics and graphic novels stay `paged`. Most manhwa and manhua are webtoon-format. Cost: physically printed volumes read as a scroll until the Phase 9 reader adds a toggle.
- Accepted deviations from 2.1/2.2:
  - `restrictOnDelete` on `media_type_id` and `external_source_id`.
  - `search_text` defaults to `''`; `status` and `age_rating` default to `unknown`.
  - Pivot tables have timestamps.
  - `ContentSource` has a migration only, no model until Phase 9.
  - Horizon stays open in `APP_ENV=local` (Horizon's own default). Production uses the gate.
