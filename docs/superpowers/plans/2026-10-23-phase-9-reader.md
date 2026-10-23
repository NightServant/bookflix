# Phase 9 — Reader Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Refresh against the code at phase start.** This plan is at task level (Files, Interfaces, test names with key assertions). Re-read Phases 1–8 as built, expand each task to step level with `superpowers:writing-plans`, and reconcile names with the code. Reader screens need F4/F6 sign-off (Reader from the VideoPlayer kit) first.

**Goal:** Read licensed or public-domain EPUB, PDF and CBZ/image content inside Bookflix, with progress synced to the library, behind a strict legal/verification gate.

**Architecture:** Metadata and content distribution stay separate (§6). A `content_sources` row, verified by an admin, is the only way content reaches a reader. `ContentAccessGate` authorizes; a signed, short-lived, range-capable route streams files from private storage; format readers are Alpine components over foliate-js (EPUB), pdf.js (PDF) and server-extracted page images (CBZ). Progress writes go through Phase 5's `LogProgress` so history, completion and interactions stay consistent.

**Tech Stack:** `foliate-js` (MIT), `pdfjs-dist` (Apache-2.0), PHP `ZipArchive`, `finfo`, `unar` (optional, CBR→CBZ), Laravel signed URLs, private S3/R2 disk, Pest browser tests.

**Spec:** `/Users/gabe/Documents/Bookflix Documentation/Bookflix_Specification.md` §6, §37 (in-repo copy `docs/superpowers/specs/bookflix-specification.md`). **Master plan:** A6 `content_sources`/`reading_progress`, A9 (Gutenberg/Standard Ebooks), A16 (CSP, signed URLs), C3 (reader chrome), C6, Q11, R1, Part E Phase 9.

**Depends on:** Phase 5 (`LogProgress`, `ReadingProgress.locator/content_source_id`, library), Phase 2 (`content_sources` table already designed, `ingestion` queue, admin), Phase 4 (components, story page), Phase 1 (security headers middleware, `access-admin`).

## Global Constraints

- **No copyrighted content is hosted or streamed without a verified `content_sources` row** (§6, R1). Verified = `verified_by` and `verified_at` set by an admin. `external_link` sources are links out, never streamed.
- Access rules: `public_domain`/`licensed` → any authenticated, active user; `user_owned` → only `owner_user_id`; unverified → 403 for everyone (admins may *preview* from admin only); deactivated users → 403.
- Files live on a **private** disk, only reachable through signed URLs expiring in ≤ 10 minutes, bound to the authenticated user; no public URLs, no directory listing, no path parameters from the client.
- Public-domain sources come only from the official Project Gutenberg / Standard Ebooks catalog feeds or mirrors; **never scrape** pages. Record license text and rights holder on each source.
- CSP (A16) is introduced in this phase: `default-src 'self'; script-src 'self' 'nonce-…'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; frame-src 'self' blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'`. Livewire/Alpine inline scripts use per-request nonces. EPUB content renders in a sandboxed iframe without `allow-scripts`.
- Uploads: MIME verified with `finfo` (not the client header or extension), size caps from config, stored under `content/{owner}/{ulid}` with the original name never used in paths.
- Archives (CBZ/EPUB): guard against zip-slip (reject `..`, absolute paths, symlinks), zip bombs (max entries, max uncompressed size, max ratio), non-image entries ignored.
- Reader UI follows C2 tokens, 44 px targets, visible focus, keyboard paging, `prefers-reduced-motion`, no red small text. Flux free + Phase 4 components.
- Run `security-review` and `design:accessibility-review` on the branch.

## Review Focus

1. Unverified, expired-signature, wrong-user and traversal requests all fail closed (403/404) — no content ever leaks. → 9.1, 9.2.
2. Malicious archives and files (zip-slip, zip bomb, polyglot/HTML-as-EPUB, 0-byte, huge image count) can't harm the server or other users. → 9.5, 9.7.
3. Progress sync under flaky conditions (offline retries, out-of-order writes, two tabs, finishing a book twice). → 9.3.
4. Resume lands in the right place after the file or the reader settings change; missing/corrupt locator falls back to percent. → 9.3.
5. A removed/unverified source while someone is mid-read ends gracefully (clear message, progress kept). → 9.1, 9.2.

---

### Task 9.1: `ContentSource` policy, admin verification, public-domain importer

**Files:**
- Create: `app/Models/ContentSource.php` (+ factory, states `verified()`, `publicDomain()`, `userOwned()`), enums `AccessType`, `ContentFormat` (A5), `app/Reader/ContentAccessGate.php`, `app/Policies/ContentSourcePolicy.php`, `resources/views/pages/admin/content/⚡index.blade.php` (list, verify/unverify with license + rights holder required), `app/Catalog/Providers/Gutenberg/GutenbergCatalog.php` + `StandardEbooksCatalog.php` (read official feeds only), `app/Jobs/ImportPublicDomainSources.php`, config `bookflix.reader.*`
- Test: `tests/Feature/Reader/{ContentAccessGateTest,AdminContentVerificationTest,PublicDomainImportTest}.php`

**Interfaces:**
- `ContentAccessGate::allows(User $user, ContentSource $source): bool` (also exposed as policy `view`), `ContentAccessGate::preview(User $admin, ContentSource $source): bool` (admin only). Verification action `VerifyContentSource::handle(ContentSource, User $admin, string $license, ?string $rightsHolder): void` requires a non-empty `license`. Importer maps catalog entries to `Media` via `IngestionPipeline` (provider keys `gutenberg`, `standardebooks`, `external_sources` rows) and creates `content_sources` (`access_type = public_domain`, `format = epub`, `license`, `url`, **unverified** until an admin verifies).

- [ ] **Tests:** `unverified source is denied to all users incl. owner`; `verified public domain is allowed to any active user`; `user_owned only for owner`; `deactivated user denied`; `external_link never streamable (allows() false) but exposes a link`; `admin preview works for unverified, regular streaming still denied`; `verify requires a license string`; `unverify immediately blocks access`; `non admins get 403 on the admin page`; importer: `creates unverified sources and media via the pipeline, is idempotent on re-run, skips entries without a file URL, never performs requests outside the feed hosts (Http::preventStrayRequests with fakes)`; catalog feed parse failures are logged and don't abort the run.
- [ ] **Commit:** `feat: content sources, access gate, admin verification and public domain import`

---

### Task 9.2: Signed streaming route with range support; CSP (Review Focus #1, #5)

**Files:**
- Create: `app/Http/Controllers/Reader/StreamContentController.php`, `app/Reader/ByteRangeResponse.php`, `app/Http/Middleware/SetContentSecurityPolicy.php`, `routes/reader.php` (included in `routes/web.php`; names `reader.*`)
- Modify: `bootstrap/app.php` (append CSP middleware to `web`), layouts (nonce on scripts via `Vite::useCspNonce()` / `@livewireScriptConfig`), `config/bookflix.php` (`reader.url_ttl_minutes=10`)
- Test: `tests/Feature/Reader/{StreamContentTest,CspTest}.php`

**Interfaces:** `ContentUrl::for(User $user, ContentSource $source): string` (temporary signed route `reader.stream`, params `source`, `user`, expires in `url_ttl_minutes`); `GET reader/stream/{source}` middleware `auth`, `signed`; handler re-checks `ContentAccessGate` **and** that the signed `user` equals `auth()->id()`; supports `Range: bytes=a-b` (206 with `Content-Range`, `Accept-Ranges: bytes`), invalid ranges → 416; `Content-Type` from the stored verified format, `Content-Disposition: inline`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`.

- [ ] **Tests:** `valid signed url streams the file (200) with correct headers`; `range request returns 206 and the exact bytes`; `open-ended range (bytes=100-)`; `suffix range (bytes=-50)`; `multiple ranges → full 200 or 416 (documented)`; `invalid/out-of-bounds range → 416`; `expired signature → 403`; `tampered signature → 403`; `other user with a valid URL → 403`; `unsigned → 403`; `source unverified after URL issued → 403`; `deleted file on disk → 404 with friendly body`; `path traversal impossible (no client path param; source id only)`; `HEAD request works`; CSP: `header present on web responses with a nonce that changes per request`; `inline script without nonce is blocked (browser test)`; `Livewire and Alpine still work under CSP (browser smoke)`; `frame-ancestors none`.
- [ ] **Commit:** `feat: signed range streaming and content security policy`

---

### Task 9.3: EPUB reader (foliate-js) with progress sync (Review Focus #3, #4)

**Files:**
- Create: `resources/js/reader/epub.js` (Alpine component `readerEpub`, wraps `foliate-js` `<foliate-view>`), `resources/views/pages/reader/⚡show.blade.php` (route `reader.show` → `/read/{slug}`), `app/Http/Controllers/Reader/ProgressController.php` (`POST reader/{source}/progress`), `app/Reader/ProgressWriter.php`
- Modify: `package.json` (`foliate-js` pinned; record license in G2), `resources/js/app.js`, story page ("Read" CTA when the user can access a verified source)
- Test: `tests/Feature/Reader/{ReaderPageTest,ProgressSyncTest}.php`, `tests/Browser/EpubReaderTest.php`

**Interfaces:** `ProgressWriter::write(User $user, ContentSource $source, array $payload): ReadingProgress` where payload = `{locator: {cfi?, index?, fraction?}, percent: float, chapter?: string, seconds?: int, client_ts: int}` → delegates to `LogProgress::handle` with `locator`, `content_source_id`; ignores payloads whose `client_ts` is older than the stored `last_read_at` (out-of-order safety), clamps nothing — invalid percent → 422. Client: debounce 2 s and flush on `visibilitychange`/`pagehide` (`navigator.sendBeacon` fallback). Resume order: `locator.cfi` → `locator.index+fraction` → `percent`. Layout toggle paginated ⇄ scrolled without reload; settings (font size, theme, line height) kept in `localStorage` (with try/catch) only.

- [ ] **Tests:** `reader page requires access and shows the reader container with a signed url`; `progress saved with locator, percent and content_source_id`; `out-of-order older payload ignored`; `invalid percent/locator shape → 422, nothing written`; `other user's source → 403`; `finishing sets completed once; re-finishing does not duplicate reading_complete`; `opted-out user: progress stored, no interactions`; `seconds feed reading_history`; browser: `opens a fixture EPUB, pages with ArrowRight, saves progress, reload resumes at the saved location`, `switches paginated ↔ scrolled without a page reload`, `missing CFI falls back to percent`, `corrupt EPUB shows an error panel and a way back`, `unverified source mid-session: next progress POST returns 403 and the UI shows "no longer available" while keeping local position`, `assertNoAccessibilityIssues()`; EPUB content iframe has `sandbox` without `allow-scripts`.
- [ ] **Commit:** `feat: epub reader with progress sync`

---

### Task 9.4: PDF reader (pdf.js)

**Files:** `resources/js/reader/pdf.js`, partial in `reader/⚡show.blade.php`, `package.json` (`pdfjs-dist`, Apache-2.0; worker served from `/build`, same origin for CSP); Test: `tests/Browser/PdfReaderTest.php`, `tests/Feature/Reader/PdfProgressTest.php`
**Interfaces:** same `ProgressWriter` payload with `locator: {page}` and `percent = page / pageCount × 100`; password-protected or corrupt PDFs show an error panel. Range streaming (9.2) is used by pdf.js (`rangeChunkSize`).
- [ ] **Tests:** `renders page 1 of a fixture PDF`; `page navigation by keyboard and input`; `progress saved with page locator; resume goes to that page`; `zoom controls keyboard accessible`; `encrypted PDF → friendly error`; `huge page count (1000) does not freeze (virtualized rendering)`; `assertNoAccessibilityIssues()`.
- [ ] **Commit:** `feat: pdf reader`

---

### Task 9.5: CBZ / image / manga reader (Review Focus #2)

**Files:** `app/Reader/Cbz/{CbzExtractor,CbzPageIndex}.php`, `app/Http/Controllers/Reader/PageController.php` (`GET reader/{source}/page/{n}` signed), `resources/js/reader/pages.js`, partials for LTR/RTL pager and vertical webtoon scroll; Test: `tests/Feature/Reader/{CbzExtractorTest,PageRouteTest}.php`, `tests/Browser/MangaReaderTest.php`
**Interfaces:** `CbzExtractor::index(ContentSource $source): CbzPageIndex` (cached list of natural-sorted image entries `jpg|jpeg|png|webp|gif`, validated names, count/size/ratio limits from `bookflix.reader.cbz.*`: ≤ 2 000 entries, ≤ 1 GB uncompressed, ratio ≤ 100); `PageController` streams a single entry **from the zip without extracting to disk** (or via a temp stream) with image `Content-Type` from `finfo`. Reading mode from `media_types.reading_mode`: `paged` → pager (RTL when `country_of_origin` = JP or media setting), `vertical_scroll` → webtoon strip with lazy loading; `locator: {page}`.
- [ ] **Tests:** natural sort (`2.jpg` before `10.jpg`); `__MACOSX` and non-image entries ignored; **zip-slip entry (`../../evil.jpg`, absolute path, symlink) rejected**; **zip bomb (ratio/size/entries) rejected with a friendly error**; empty zip; corrupt zip; page index out of range → 404; signed + gate on each page request; page response `nosniff`; RTL manga keys (ArrowLeft = next) in browser test; webtoon lazy-loads (`loading=lazy` + IntersectionObserver) and saves `page` progress; double-page spreads are not split (documented); `assertNoAccessibilityIssues()` (images have `alt="Page n"`).
- [ ] **Commit:** `feat: cbz and image reader with manga and webtoon modes`

---

### Task 9.6: CBR → CBZ conversion (Q11)

**Files:** `app/Reader/Cbz/CbrConverter.php`, job `ConvertCbrToCbz`; config `bookflix.reader.cbr.unar_path`; Test: `tests/Feature/Reader/CbrConverterTest.php`
**Interfaces:** `CbrConverter::available(): bool` (binary exists); `convert(ContentSource $source): ContentSource` runs `unar` via `Process` with timeout and a temp directory, rebuilds a CBZ with the same extraction safety checks, replaces `format` to `cbz`; when unavailable, CBR uploads are rejected at upload time with a clear message ("CBZ only on this server").
- [ ] **Tests:** `process mocked: converts and swaps format`; `unavailable → rejected with message`; `timeout/non-zero exit → source marked failed, original untouched, temp dir removed`; `hostile archive entries rejected as in 9.5`; `job is idempotent`.
- [ ] **Commit:** `feat: cbr to cbz conversion`

---

### Task 9.7: User-owned uploads (Review Focus #2)

**Files:** `resources/views/pages/library/⚡upload.blade.php` (Livewire `WithFileUploads`, native file input), `app/Reader/UploadContentSource.php`, config caps (`bookflix.reader.upload.{epub_mb=100,pdf_mb=200,cbz_mb=500}`, per-user quota 2 GB), route `library.upload`
**Interfaces:** `UploadContentSource::handle(User, Media, UploadedFile): ContentSource` → validates by `finfo` MIME + magic bytes (`PK` zip with `mimetype` entry for EPUB; `%PDF-`; zip with images for CBZ), size, quota; stores on the private disk at `content/{user_id}/{ulid}`; creates `content_sources` (`access_type = user_owned`, `owner_user_id`, `license = 'user-provided'`, verified automatically **for the owner only** — `verified_by` = system marker; never visible to others). The user must attach it to an existing `Media` (picked via search).
- [ ] **Tests:** `valid EPUB/PDF/CBZ accepted`; `renamed .exe/.html/.svg rejected (extension lies)`; `PDF with wrong magic bytes rejected`; `HTML disguised as EPUB rejected`; `over-size rejected`; `quota exceeded rejected`; `0-byte rejected`; `stored path contains no client filename`; `other users cannot stream it (403)`; `deleting the source deletes the file`; `deactivated-account purge deletes the user's files`; `opted-out and normal users alike can upload`; `rate limit 10 uploads/hour`.
- [ ] **Commit:** `feat: user owned content uploads`

---

### Task 9.8: Reader chrome and accessibility

**Files:** `resources/views/components/reader/{toolbar,settings-panel,chapter-list,next-chapter}.blade.php` (adapted from the VideoPlayer kit: Next Episode → Next Chapter, Playback Speed → Reading settings, Subtitles → Display panel; F3), `resources/js/reader/chrome.js`; Test: `tests/Browser/ReaderChromeTest.php`
**Interfaces:** consumes the three readers' common event contract (`reader:relocate`, `reader:ready`, `reader:error`); settings keys `fontSize`, `lineHeight`, `theme` (dark only per D-4 → `dark`, `sepia-dark`), `layout`, `direction`.
- [ ] **Tests:** `toolbar auto-hides and reappears on mouse move/focus (not under reduced motion: no fade)`; `keyboard: arrows page, Esc closes panels, F toggles fullscreen, / opens chapter list`; `focus trap in settings panel and focus returns to trigger`; `next chapter button at end of book disabled with label`; `touch targets ≥ 44 px (computed size assertion)`; `200% zoom does not hide controls`; `screen reader labels for all controls`; `assertNoAccessibilityIssues()` on epub, pdf, manga and webtoon pages.
- [ ] **Commit:** `feat: reader chrome and accessibility`

---

## Phase 9 exit criteria
- [ ] `composer test`, browser suite (all readers), ML suite unaffected; CSP enforced with no console violations on core pages.
- [ ] `security-review` (signed URLs, ranges, archive handling, CSP, uploads) and `design:accessibility-review` pass; `/code-review` on the branch.
- [ ] A public-domain EPUB imported, verified in admin, read end to end with synced progress; an unverified source cannot be opened by anyone.
- [ ] Ask the user to run the full `php artisan test --compact`.
