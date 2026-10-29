# Phase 4 final review (fix wave + redesign, `2d22d9f..HEAD`, 64 commits)

## Verdict: MERGE AFTER FIXES

- 0 Critical, 3 Important, 9 Minor, plus a design-polish list.
- R1–R17 are fixed, except two partial fixes: R8 (the search facets) and R10 (a stale guard message). Design items 1–22 are fixed, except item 20 (heading case, partial). Item 5 (F12, Figma) is out of scope by ruling. See the status table at the end.
- **What I checked and found sound:**
  - Livewire ids: `mediaId`, `seriesId`, `lastQueryId` and `loggedSignature` stay `#[Locked]`.
  - New caches hold only ints and arrays: hero ids, similar ids, discover counts and covers, year bounds, admin counts, showcase books.
  - No `{!! !!}` or `x-html` was added. Inline styles are built from constants or `addcslashes`, inside escaped attributes.
  - R2 matches the ruling: all three `library-add` sites sit behind `Route::has('library.store')`.
  - The skip link, the Escape capture guards (R4, R15) and the Meta-click fix (R16) are correct.
- **Screenshots reviewed** (all in `scratchpad/shots/`):
  - `home-after/home-{desktop,phone,desktop-motion}`
  - `ss-after/story-series-*` and `ss-after/series-demo-*`
  - `des-after/{discover,search-q,explore-700}-*`
  - `saa-after/{settings-profile,login,admin-index}-desktop`
  - `rd1a-after/discover-*`

## Findings

### N1 — Important: the full-text leg no longer ranks by relevance
- **Where:** `app/Discovery/Search/Legs/KeywordLeg.php:34` (commit `7e1ad9c`).
- **Problem:**
  - `->orderBy('media.id')` is called on the Scout builder, not inside the query callback. So `$builder->orders` is non-empty.
  - Scout's `DatabaseEngine::shouldOrderByRelevance()` (`vendor/laravel/scout/src/Engines/DatabaseEngine.php:321-329`) then skips `ts_rank`.
  - The Scout list therefore holds the 100 *lowest ids* among the matches, ordered by id, not the 100 most relevant. For a broad term (over 100 hits, the case R7 was about), relevant titles with high ids drop out of that list entirely.
  - The commit message says "breaks ties", but the change replaces the ranking.
- **Fix:**
  - Drop the Scout-level `orderBy`.
  - Inside `->query(fn ($media) => ...)`, add `->orderByRaw("ts_rank(to_tsvector('simple', media.search_text), plainto_tsquery('simple', ?)) desc", [$query])->orderBy('media.id')`. Scout then sees no orders, but the explicit rank comes first and id breaks ties.
  - Alternatively, keep `orders` empty and accept Scout's rank-only order.
- **Test (`SearchTest`):** seed 101 media whose `search_text` contains "dragon", with the strongest match (title "Dragon Dragon Dragon") created last, so it has the highest id. `KeywordLeg::ids('dragon', new SearchFilters, 100)` contains its id.

### N2 — Important: the cover proxy serves third-party bytes with a sniffed type and no `nosniff`
- **Where:** `app/Http/Controllers/CoverController.php:34-37` and `:78-81`.
- **Problem:**
  - Only the upstream `Content-Type` header is checked. The stored body is never checked.
  - The response type comes from `$disk->mimeType($path)`, which sniffs the file content (the file has no extension). If an allowed host ever returns HTML or SVG labelled `image/png`, it is stored and served from our authenticated origin as `text/html` or `image/svg+xml`, which is same-origin script with the reader's session.
  - The allowed hosts include the whole `openlibrary.org` and `anilist.co`, not just the cover CDNs.
  - There is no `X-Content-Type-Options` header and no CSP on the response.
- **Fix:**
  - Validate the body before storing: `$info = getimagesizefromstring($body)`, and require `$info !== false` and `in_array($info['mime'], self::MIME_TYPES)`. Store that type next to the file (for example `covers/<sha>.<ext>`).
  - Serve with the validated type, `X-Content-Type-Options: nosniff` and `Content-Security-Policy: default-src 'none'; sandbox`.
  - Optionally narrow the hosts to the cover CDNs (`s4.anilist.co`, `covers.openlibrary.org`).
- **Test (`CoverProxyTest`):**
  - Upstream `Content-Type: image/png` with an HTML body and with an SVG body: 404, nothing stored.
  - The happy path asserts the `nosniff` header.

### N3 — Important: the CI `browser` check now reports green when browser tests fail
- **Where:** `.github/workflows/tests.yml` (the `changes` job and `continue-on-error` on "Run browser tests").
- **Problem:**
  - On every PR that doesn't touch `package*.json` or `composer.lock`, a failing browser suite ends with a green `browser` check (step-level `continue-on-error`).
  - Before, the job went red, just not required. Now a regression in the hero, the preview or the palette (the parts R10 worried about) is invisible unless someone opens the log.
  - The comment also asks the owner to make `browser` required, which only makes the green misleading.
- **Fix:** keep the browser job honest (no `continue-on-error`) and add a small required gate job:
  ```yaml
  browser-gate:
    needs: [changes, browser]
    if: always()
    runs-on: ubuntu-latest
    steps:
      - run: test "${{ needs.changes.outputs.dependencies }}" != "true" || test "${{ needs.browser.result }}" = "success"
  ```
  Make `browser-gate` the required check. `browser` then shows red on every PR, but blocks only on dependency PRs.
- **Test:** a draft PR with a deliberately failing browser test shows `browser` red and `browser-gate` green. A PR that also touches `package.json` shows both red.

### N4 — Minor: cover proxy hardening (rate limit, cache busting, atomic writes)
- **Where:** `routes/web.php:16` and `CoverController.php:28-37`.
- **Problem:**
  - **No throttle.** Any signed-in user can walk `/covers/1..N`. Each cold hit holds a PHP worker for up to 11 s on an upstream fetch and writes up to 5 MB to disk. Files are never pruned.
  - **Stale covers.** The response is `public, max-age=86400, immutable` on a URL keyed only by media id. When `cover_url` changes (resync, or a better cover), browsers keep the old texture for a day.
  - **Partial reads.** `put()` is not atomic. A concurrent request can stream a half-written file, which is then cached as immutable.
- **Fix:**
  - Add `->middleware('throttle:120,1')` to the route.
  - Version the URL: `route('covers.show', [$media, 'v' => substr(sha1($media->cover_url), 0, 8)])` in `book-3d.blade.php:31` and `hero.blade.php:35`.
  - Write to a temp name, then `move()`.
  - Prune `covers/` in the scheduler, or key the file by media id so a new cover replaces the old one.
- **Test:**
  - The 121st request in a minute gets a 429.
  - Changing `cover_url` changes the rendered `data-scene-cover`.

### N5 — Minor: three.js lifecycle leaks and an always-running loop
- **Where:** `resources/js/book-scene.js`.
- **Problem:**
  - **Per-turn growth.** `prepare()` and `wrapMaps()` push every texture into `disposables` (`:95`, `:142`). Each hero `turnTo` adds 3 entries that are never removed. GPU memory is freed, but the array keeps every cover `Image` and two canvases alive for the life of the page.
  - **Probe contexts.** `supportsWebGL()` (`:25-33`) creates a WebGL context per book per page view and never releases it. Chrome caps live contexts at about 16, and they're only reclaimed on GC.
  - **Failure path.** If `start()` throws after the `WebGLRenderer` is created (`:149`) but before the real `dispose` is assigned (`:458`), `.catch(() => dispose())` (`:69`) calls the stub, and the renderer and its PMREM leak.
  - **Endless loop.** `draw` re-arms `requestAnimationFrame` forever while the book is visible (the idle float). That keeps the GPU busy at 60 fps on Home and Story even when nothing moves.
- **Fix:**
  - Remove disposed textures from `disposables` (or track the jacket maps separately).
  - Memoize `supportsWebGL` and call `getExtension('WEBGL_lose_context')?.loseContext()` on the probe.
  - Wrap the post-renderer setup in `try` and dispose the renderer in `catch`.
  - Stop the loop once the pointer has settled and no turn is running, and restart it on `pointermove` or a turn (or cap the frame rate at about 30 while idle).
- **Test (`tests/Browser/Components/Book3dTest`):**
  - After 10 `turnTo` calls, a test hook (`el.bookScene.stats()`) reports a bounded texture count.
  - With the pointer still for 2 s, no `requestAnimationFrame` callbacks are pending.

### N6 — Minor: R8 only partly done for Search
- **Where:** `resources/views/pages/search/⚡index.blade.php:233-257` and `:319,337`.
- **Problem:**
  - Only the year bounds are cached. Every keystroke still runs the `MediaType`, `Genre` and `Theme` pluck queries.
  - `filters.blade.php` is still included twice (sidebar plus sheet), so every genre and theme is serialized into `x-data` twice. R8 named both points.
- **Fix:**
  - Cache the three `[slug => name]` maps (10 minutes, plain arrays).
  - Render the sidebar filters only at `lg` and the sheet only below `lg`, or share one data island.
- **Test:** a second search render runs 3 fewer queries than the first.

### N7 — Minor: invalid UTF-8 in a filter value is a 500
- **Where:** `app/Discovery/Search/SearchFilters.php:145-150` and `search/⚡index.blade.php:129`.
- **Problem:** R1 scrubs `q`, but not filter strings. `?q=ab&filters[genres][]=%FF` reaches `json_encode(..., JSON_THROW_ON_ERROR)` in `recordSearch()` (a `JsonException`), and then Postgres (`22021`).
- **Fix:** in `strings()`, map each item through `mb_scrub()` and drop anything whose scrubbed form differs from the original.
- **Test (`SearchPageTest`):** `get(route('search', ['q' => 'ab', 'filters' => ['genres' => ["\xFF"]]]))->assertOk()`.

### N8 — Minor: the R10 guard is stale and three is not pinned
- **Where:** `tests/Unit/AlpineUiPinTest.php:11` and `package.json` (`"three": "^0.186.1"`).
- **Problem:**
  - The failure message points at `__buttonEl` in `story-preview.blade.php`. Since R12 it is set in `story-previews.blade.php:24`.
  - Livewire's bundled Alpine is still unguarded (R10 asked for both).
  - `three` is a caret range, while every other runtime dependency is exact. The scene imports `examples/jsm` internals, which change between minors.
- **Fix:**
  - Correct the path in the message.
  - Add a matching assertion on `composer.lock`'s `livewire/livewire` version.
  - Pin `"three": "0.186.1"`.
- **Test:** the guard tests themselves.

### N9 — Minor: the story page still loads every volume of its series
- **Where:** `stories/⚡show.blade.php:56` (`'series.orderedMedia'`) and `:317-319`.
- **Problem:** design item 7 limited the Series tab to ±5 volumes, but the page still hydrates the whole series (1,000+ models for a long manga) on every story view. The page's own `neighbours()` needs only 3 of them.
- **Fix:**
  - Load series without their media.
  - Fetch the window with a positioned query: `orderedMedia()->unmerged()` with a window function, or `where position between`. Also fetch the count.
- **Test:** a story in a 300-volume series stays within the ≤ 25 query budget and has at most 11 `Media` models hydrated (`Model::retrieved` counter).

### N10 — Minor: search h1 and guide edge cases
- **Where:**
  - `search/⚡index.blade.php:302`
  - `stories/⚡show.blade.php:179`
  - `series/⚡show.blade.php:151`
- **Problem:**
  - A single character reads "Results for “a”" while the page says nothing ran. The h1 should follow `hasQuery`, not `filled(trim($q))`.
  - A 200-character query becomes a 200-character h1, with no clamp.
  - The story and series guides are boilerplate placed *between* the h1 and its own metadata ("Also known as", the creators), so the title is cut off from its facts.
- **Fix:**
  - Use `$this->hasQuery ? __('Results for “:q”', ...) : __('Search')`.
  - Add `line-clamp-2 break-words` to the page-header h1.
  - Move the story and series guides after the metadata row, or write them as specific lines (for example, ":count volumes, in reading order. Start with volume 1.").
- **Test (`PageGuidesTest`):** `?q=a` is titled "Search".

### N11 — Minor: test quality
- **Where:** `tests/Feature/PageGuidesTest.php`, `tests/Feature/Components/{Cta,PageHeader,Chip}Test.php` and `tests/Feature/Http/CoverProxyTest.php`.
- **Problem:**
  - **Guides coverage.** "story, series and class pages guide the reader" never requests the series page. The signed-in dataset omits `security.edit` and Home.
  - **Class-string assertions.** `CtaTest` asserts `px-5!`, `ps-4!`, `gap-2.5!` and `py-2!`. `PageHeaderTest` asserts `mt-2`, `text-pretty` and `max-w-prose`. These break on any restyle without catching a behaviour change. Assert the rendered behaviour instead (the 44 px box in the browser suite, the icon position, the aria-label requirement).
  - **No relevance test.** Nothing tests ranking inside a leg, which is why N1 got through.
  - **Proxy gaps.** The proxy has no size-cap test and no wrong-body test (N2).
  - **Flakiness.** The browser hero, preview and scene tests rely on fixed `wait(1–3)`. The new LCP and chunk-timing test (`HomeTest`) depends on `loadEventEnd` ordering, which is flaky on a busy runner. Prefer polling `waitForScript` / `assertScript` until true.
- **Fix:** as above. Add the series and security rows to the guides dataset.

### N12 — Minor (note): the trending index blocks writes while it builds
- **Where:** `database/migrations/2026_10_27_000001_add_trending_index_to_media.php:16`.
- **Problem:** a plain `create index` takes a `SHARE` lock on `media`. That's fine at 3k rows, but run after catalog wave 1 it blocks the provider syncs.
- **Fix:** set `public $withinTransaction = false;` and use `create index concurrently if not exists` (and `drop index concurrently if exists` in `down()`).
- **Test:** none (the migration still runs in the suite).

## Design polish

1. **Story breadcrumb on phone** (`ss-after/story-series-phone.png`): each crumb wraps onto its own line with a dangling leading chevron. Truncate the middle crumbs on phone ("Explore the Library › … › 741.52 Japanese Comics"), or keep `flex-nowrap overflow-x-auto` and use `truncate` on the crumbs.
2. **Heading case** (decision f, item 20): these h2s are still sentence case beside Title Case shelves:
   - "Also shelved in", "Related stories" and "Content warnings" (`stories/⚡show.blade.php:254,344,291`)
   - "About this series" (`series/⚡show.blade.php:191`)
   - the settings card titles ("Name and email", "Reading profile", "Danger zone")
3. **Pagination** (`des-after/search-q-desktop.png`, `des-after/explore-700-desktop.png`):
   - The Flux pager buttons are about 24 px, under the 44 px target, and the smallest control on the page.
   - Explore keeps "Showing 1 to 24 of 966 stories" while Search hides it.
   - Style one pager component (`min-h-11`, `text-meta`) and use it everywhere.
4. **Empty states** (redesign §1): no empty state has the tilted `<x-book-3d>`:
   - Search's no-results and throttled states. The throttled state also has no icon and no action (add `arrow-path` "Try again").
   - Series "No volumes yet", where the header still shows a flat `cover-fallback`.
   - `empty-catalog`
5. **Icons on buttons** (redesign §3 table): Search's "Clear all" (`search/⚡index.blade.php:314,361`) has no `x-mark`. Discover's and Story's "Explore the Library" and "All N volumes" are text links, which is fine, but the series "No volumes" CTA and the Discover link should match.
6. **Depth (§2) not delivered:**
   - `--shadow-overlay` and `--shadow-glow-brand` are defined (`app.css:77-78`) but unused anywhere.
   - The preview card, palette and sheet have no elevation.
   - Cards lack the inset top highlight.
   - The header is opaque `bg-canvas` over the story, series and hero bands, not the specified `bg-canvas/70 backdrop-blur-md`.
7. **Band seams** (`home-after/home-desktop.png`, `ss-after/story-series-desktop.png`): 48–64 px black canvas strips between consecutive bleed bands (hero → panel → Trending → New Releases → Subject) read as seams. Either butt the bands together or give the gaps the band surface.
8. **Control heights on auth and settings** (`saa-after/login-desktop.png`, `settings-profile-desktop.png`):
   - Flux inputs and buttons are 40 px, while `<x-cta>` is 44 px elsewhere. "Log in", "Sign in with a passkey" and "Save" should use `<x-cta>` sizing.
   - "Deactivate account" uses the brand-red primary. Use Flux `danger` with an outline, so red keeps meaning "the one primary action".
9. **Series on phone** (`ss-after/series-demo-phone.png`): `order-first` puts a long "About this series" above the volumes list. On a phone, show the volumes first, or collapse About to 3 lines.
10. **Contrast:** `text-meta text-ink-muted` (#808080) on `bg-surface-card` (#232323) is about 4.0:1, below AA. It appears in `admin/classifications:256,262`, `admin/duplicates:96`, `admin/providers:128,131` and `admin/users:79`. Use `text-ink-subtle`.
11. **Admin stat pills:** "1305 to review" is unformatted (`admin-index-desktop.png`). Use `Number::format`.
12. **Story header:** "Published by" (`stories/⚡show.blade.php:197`) has no top margin under the creators list.

## Status of R1–R17 and design items 1–22

| Item | Status |
|---|---|
| R1 | Fixed: `cleanQuery`, logger cap, `MAX_VALUES`. N7 covers the filter-string UTF-8 gap. |
| R2 | Fixed per ruling. |
| R3 | Fixed, with a browser test before and after `wire:navigate`. |
| R4 | Fixed. |
| R5 | Fixed. |
| R6 | Fixed, test extended. |
| R7 | Fixed (`SearchResults::hasMore`). |
| R8 | Partial: see N6. |
| R9 | Fixed: warm ≤ 20, `deweyClass.parent`. |
| R10 | Fixed, with a stale message: see N8. Browser gating is now misleading: see N3. |
| R11 | Fixed (dropped). |
| R12 | Fixed: one card and one dialog per shelf, inert `<template>` summaries. |
| R13 | Fixed. |
| R14 | Fixed, with a status in the page and the palette. |
| R15 | Fixed, with an in-modal test. |
| R16 | Fixed. |
| R17 | Fixed: ≤ 22 for both the list view and a pure filter browse. |
| Design 1–4, 6–19, 21, 22 | Fixed. |
| Design 5 (F12) | Out of scope by ruling. |
| Design 20 | Partial: see polish 2. |
| Design 3 | Its `line-clamp` belongs on the shared page-header h1 too: see N10. |
