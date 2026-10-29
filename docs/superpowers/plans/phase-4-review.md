# Phase 4 whole-branch review (`main...phase-4-discovery`, 57 commits)

## Verdict: MERGE AFTER FIXES

- 0 Critical, 4 Important, 13 Minor.
- Public scope holds everywhere I traced: legs and browse go through `unmerged()` plus soft deletes (merge losers are also soft-deleted, `Deduplicator.php:147-148`). Classes go through `publicPrimary()` / `public()`, and adult records are rejected at ingestion.
- No `{!! !!}` or `x-html`. The only `HtmlString` escapes its parts (`story-info.blade.php:22`). Provider URLs pass `safeUrl()` at ingestion.
- Every page-level Livewire id is `#[Locked]`.
- Caches hold only ids, arrays or attribute rows (works with `serializable_classes=false`).
- Plan exit criteria still open:
  - Lighthouse ≥ 90 at 390 px on Home has not been measured (see R12).
  - Figma design QA at 1440 and 390 (F12).
  - `design:accessibility-review` and the `impeccable` pass.
  - Licenses in G2.
- Screenshots (1440 wide, full page, reduced motion, real covers): `review-{home,search,story,series,discover,explore}.png` in this folder.

## Findings

### R1 — Important: a long or malformed search text causes a 500 when the search is logged
- **Where:**
  - `resources/views/pages/search/⚡index.blade.php:123` logs `trim($this->q)` uncapped.
  - `app/Discovery/Search/SearchLogger.php:20-21`.
  - `database/migrations/2026_10_25_000002_create_search_queries_table.php:22-23`: `query` and `normalized` are `varchar(255)` (confirmed in the dev DB).
- **Problem:**
  - `HybridSearch` caps the text at 200 characters (`HybridSearch.php:14,64`), but the logger writes the raw text. Any `q` over 255 characters (pasted text, or `?q=` in the URL) throws "value too long" and the page 500s.
  - Invalid UTF-8 (`?q=%FFab`) passes `hasQuery` (`mb_strlen` ≥ 2) and reaches the insert. A read-only probe shows that Postgres rejects it (`SQLSTATE 22021`).
  - Review Focus #2 is only proven for `HybridSearch` (`SearchTest.php:44`), not for the page.
- **Fix:** sanitize once, at the boundary.
  - In `mount()` and `updatedQ()`, set `$this->q = mb_substr(mb_scrub($this->q), 0, HybridSearch::MAX_QUERY_LENGTH)` (make the const public).
  - Have `SearchLogger::log()` store `mb_substr(..., 0, 255)` as belt and braces.
  - Also cap list filters at about 50 values in `SearchFilters::strings()` (`SearchFilters.php:142-147`). A Livewire payload of ~65k distinct slugs (fits under the 1 MB limit) exceeds Postgres's 65,535 bind parameters and 500s.
- **Tests (`SearchPageTest`):**
  - `->set('q', str_repeat('ab ', 200))` stays OK and logs a row with `mb_strlen(query) <= 200`.
  - `get(route('search', ['q' => "\xFFab"]))->assertOk()`.
  - A `SearchFilters::fromArray(['genres' => range(1, 500)])` unit test asserts `count(genres) <= 50`.

### R2 — Important: "Add to Library" is a dead primary CTA
- **Where:**
  - `resources/views/pages/stories/⚡show.blade.php:190`: the page's only red CTA.
  - `components/hero.blade.php:94`.
  - `components/story-summary.blade.php:34`.
- **Problem:**
  - All three dispatch `library-add`, and nothing in `app/` or `resources/` listens (only the browser tests). A reader clicks the story page's focal action and gets no response and no feedback.
  - On top of that, "Start reading" (hero `:93`, summary `:33`, series `:83`) opens the details page, where there is nothing to read. Two broken promises on the main journey.
- **Fix:**
  - Use the pattern the branch already uses for "Continue reading" and "My collections" (`discover/⚡index.blade.php:221-226`): render the library CTA only when its Phase 5 route exists (`Route::has('library.store')`).
  - Rename "Start reading" to "View story", and the series CTA to "See volume 1", until a reader exists.
  - The story page then has no primary CTA until Phase 5. That is better than a dead one.
- **Test:** a feature test that the story page, hero and summary render no `library-add` and no "Add to Library" while `Route::has('library.store')` is false. Update the browser tests that listen for `library-add` to register the route first.

### R3 — Important: no skip link (WCAG 2.4.1, Level A)
- **Where:** `resources/views/layouts/app/header.blade.php:6-7` and `layouts/app.blade.php:7` (`<main>` has no id).
- **Problem:** a keyboard user tabs through the logo, 4 nav items, the palette trigger and the user menu before any content, on every page. Home adds a hero with 6+ stops before the shelves.
- **Fix:**
  - Make the first child of `<body>` this link: `<a href="#main" class="sr-only focus:not-sr-only focus-ring fixed start-4 top-2 z-50 rounded-control bg-surface-card px-4 py-2 text-meta text-ink">{{ __('Skip to content') }}</a>`.
  - Change `<main>` to `<main id="main" tabindex="-1" class="pb-24 outline-none lg:pb-12">`.
- **Test (`tests/Browser/LayoutTest`):** the first Tab focuses "Skip to content", and Enter makes `document.activeElement.id === 'main'`, both before and after a `wire:navigate`.

### R4 — Important: Escape in a filter combobox never closes the filter sheet (the F5 class, found in the sheet)
- **Where:**
  - `resources/views/components/combobox.blade.php:34`.
  - `components/filter-sheet.blade.php:44`.
  - The plugin handler is `node_modules/@alpinejs/ui/src/combobox.js:337`: `@keydown.escape.prevent` plus `stopPropagation`, even while the list is closed.
- **Problem:**
  - A native `<dialog>` (the Flux flyout) closes only when the Escape keydown is not default-prevented.
  - With focus in Genres, Themes or Languages, the first Escape closes the list (correct), but every later Escape is swallowed too. The sheet can only close through Tab and the close button.
  - The palette already solved the same thing (`⚡command-palette-results.blade.php:44`). The sheet test only presses Escape on the close button (`FilterSheetTest.php:59`).
  - F5 itself (the popover) has no instance inside the palette or the sheet today; see R15.
- **Fix:** on the `x-combobox` element, add `@keydown.escape.capture="$combobox.isOpen || $event.stopPropagation()"`. While the list is closed, the plugin's handler never runs, so `preventDefault` never fires and the dialog's close request goes through.
- **Test (`FilterSheetTest`, 375 px):** open the sheet, type in Genres, press Escape twice. The first closes the listbox and the sheet stays open; the second closes the sheet with focus back on "Filters".

### R5 — Minor: Discover's intro overstates the genres
- **Where:** `discover/⚡index.blade.php:238`.
- **Problem:** "and :count genres" counts `$this->genres`, the top 12. With 300 genres it says "12 genres".
- **Fix:** count `Genre::has('media')` (cached with the counts, see R8), or drop the genre clause.
- **Test:** with 13 genres that have stories, the intro reads "13 genres".

### R6 — Minor: the merge redirect lets the query string overwrite the slug
- **Where:** `stories/⚡show.blade.php:31`, `['slug' => $media->slug, ...request()->query()]`.
- **Problem:** a later `slug` key wins. `/stories/<merged>?slug=other` 301s to `/stories/other`.
- **Fix:** `[...request()->query(), 'slug' => $media->slug]`.
- **Test:** extend "a merged slug redirects with 301" (`StoryPageTest.php:182`). Get `[$loser->slug, 'sq' => 5, 'slug' => 'x']` and `assertRedirect(route('stories.show', [$winner->slug, 'sq' => 5]))`. Today's test doesn't assert that the query string survives either.

### R7 — Minor: result totals are silently capped near 200
- **Where:**
  - `app/Discovery/Search/HybridSearch.php:28-39`.
  - `Legs/KeywordLeg.php:30,51` (100).
  - `Legs/SubjectLeg.php:23` (100).
- **Problem:**
  - `total()` is the size of the fused id list, so "action" across 5,000 matches reads "137 stories" and pagination ends at page 6.
  - That's fine as a ranking ceiling, but the copy states a false exact count. It matters for the catalog-breadth goal.
- **Fix:** expose `hasMore` (any leg returned `$limit` ids) and render ":count+ stories". Alternatively raise the leg limits to 250 and document the ceiling.
- **Test:** a `SearchTest` with 120 title matches gives `total() === 100` and `hasMore === true`, and the page shows "100+ stories".

### R8 — Minor (fix before catalog wave 1): per-request aggregates with no cache and no index
- **Where:**
  - `app/Discovery/HomeShelves.php:57-60`: the hero runs on every Home load, not cached, sorting by `ratings_count`/`published_on`, which have no index.
  - `discover/⚡index.blade.php:71-80`: type counts with `group by` over `media`.
  - `discover/⚡index.blade.php:130-141`: `withCount` over **every** genre, then `limit 12`.
  - `search/⚡index.blade.php:219-239`: min/max year plus every genre and theme, recomputed on each keystroke and serialized into `x-data` twice (sidebar plus sheet).
  - `app/Discovery/SimilarMedia.php:60-77`: a `group by` over the union of every story sharing a genre or theme, on each story view.
- **Problem:** cheap at 3k rows, but each is a full scan per request once wave 1 lands.
- **Fix:**
  - Put the hero ids in the existing `home:shelves:v1` pick.
  - Cache the type and genre counts as `[id => count]` arrays for 10 minutes.
  - Cache the year bounds.
  - Cache `SimilarMedia` ids per media for 1 hour (ids only, like HomeShelves).
  - Add a partial index: `(ratings_count desc, published_on desc nulls last, id desc) where deleted_at is null and merged_into_id is null`.
- **Test:** a second Home render costs the same as or less than the first minus 1 query (hero cached), and a Discover second render runs ≤ 6 queries.

### R9 — Minor: the story page has 1 query of headroom (F6, still open)
- **Where:** `stories/⚡show.blade.php:110-114` and `SimilarMedia.php:84-101`. The page runs 24 queries against a budget of 25 (`StoryPageTest.php:261`).
- **Fix:**
  - Caching the SimilarMedia ids (R8) removes 3 to 4 queries.
  - Resolve the division from the already-loaded class path: eager-load `deweyClass.parent` in `media()`, instead of `DeweyClass::where('code', ...)`.
- **Test:** keep the cold budget at 25 and add a warm-cache assertion of ≤ 20.

### R10 — Minor: private `@alpinejs/ui` internals have only a non-required guard
- **Where:**
  - `resources/js/components.js:337-393` reads `__value`, `__context`, `__isOpen`, `__close`, `$refs.__input` and `$refs.__options`.
  - `components/story-preview.blade.php:22` sets `__buttonEl`.
  - `.github/workflows/tests.yml:61-62`: the browser job is deliberately non-required.
- **Problem:**
  - Only the browser suite exercises these internals. A version bump of `@alpinejs/ui`, or of Livewire's bundled Alpine core (`livewire/livewire ^4.1`), passes the required `ci` check while it breaks the combobox and the preview.
- **Fix:**
  - Add a unit test that reads `package-lock.json` and asserts `node_modules/@alpinejs/ui.version === '3.17.5'`, with a message listing the internals to re-verify.
  - Make `browser` required for PRs that touch `package*.json` or `composer.lock`.
- **Test:** the guard test itself.

### R11 — Minor: `sq` ships before anything reads it
- **Where:** `search/⚡index.blade.php:208-212`, `cover-tile.blade.php:27` and `result-row.blade.php:13`.
- **Problem:** sequential `search_queries` ids go into shareable URLs, and the server ignores them.
  - Once click-crediting reads `sq`, any reader can credit any search unless the value is validated.
- **Fix:** drop `sq` until the click log exists (YAGNI). Or, when it lands, credit only when `SearchQuery::find($sq)?->session_hash` matches the current session's hash.
- **Test (with the consumer):** an `sq` from another session is ignored.

### R12 — Minor: Home's DOM weight against the unmeasured Lighthouse ≥ 90 exit criterion
- **Where:** `components/⚡home-shelf.blade.php:56-60` and `stories/⚡show.blade.php:333-337`.
- **Problem:**
  - Every tile renders `<x-story-summary>` twice: once in the preview, once in the quick view.
  - It also renders one `<dialog>` per tile, even on fine pointers, where the quick view is `display:none`.
  - Each popover adds a window `focusin` listener (`popover.js`).
  - Home with two story shelves therefore has about 40 dialogs, 80 summaries and 40 global focus handlers.
- **Fix:**
  - Measure first (exit criterion).
  - If it falls below 90, render one shared preview and quick view per shelf, fed by the hovered tile's data, or render the quick view only `@if` on a coarse pointer through a single page-level modal.
- **Test:** a browser test asserting `document.querySelectorAll('dialog').length <= 3` on Home once that refactor lands.

### R13 — Minor: the series header's LCP cover starts at opacity 0
- **Where:** `series/⚡show.blade.php:98-101`. `x-motion-reveal` on the header's flex row sets the cover `opacity:0` until `inView` fires and the 250 ms fade ends.
- **Fix:** move `x-motion-reveal` to the text column only. The `fetchpriority="high"` cover must paint on first frame.
- **Test:** a browser test (motion allowed) that `getComputedStyle(cover).opacity === '1'` on load.

### R14 — Minor: search runs unthrottled
- **Where:** `components/⚡command-palette-results.blade.php:24` and `search/⚡index.blade.php:185-187`.
- **Problem:** each debounced keystroke runs 6 to 8 queries (4 keyword lists, the subject leg, the load), with no limiter.
- **Fix:** `RateLimiter::attempt("search:{$userId}", 120, ...)` in `HybridSearch::search()`, returning an empty page with a status message when exceeded.
- **Test:** the 121st search in a minute returns an empty page and doesn't hit the DB.

### R15 — Minor: F5, the popover still swallows Escape while closed
- **Where:** `components/story-preview.blade.php:13` (plugin: `popover.js:77`, `@keydown.escape.stop.prevent`).
- **Problem:**
  - With a story tile focused, Escape is stopped and default-prevented even while the preview is closed, and `__close()` refocuses the trigger.
  - There is no instance inside a dialog today (neither the palette nor the sheet holds a preview), so the impact is latent.
- **Fix:** on the `x-popover` element, `@keydown.escape.capture="open || $event.stopPropagation()"`, the same pattern as R4.
- **Test:** `StoryPreviewTest`: a preview inside `componentPage('<flux:modal name="m">…')`, opened via trigger. Escape on the focused, closed tile closes the modal.

### R16 — Minor: F4, still open
- **Where:** `⚡command-palette-results.blade.php:84`.
- **Problem:** `@click.prevent` on the result `<a>` swallows Ctrl/Cmd-click.
- **Fix:** `@click="($event.metaKey || $event.ctrlKey || $event.shiftKey) ? $event.stopPropagation() : $event.preventDefault()"`.
- **Test:** a browser test where a Meta-click on a result keeps the palette open and doesn't navigate the current tab.

### R17 — Minor: the search page has no query budget test
- **Where:** `tests/Feature/Discovery/SearchPageTest.php`. Every other Phase 4 page has one (Home, Discover, Story, Series).
- **Fix and test:**
  - A list-view search with a text query and two filters runs ≤ 22 queries with `preventLazyLoading` on.
  - The same holds for a pure filter browse.

## Design fixes (F8–F19 merged with the new findings; tokens from `resources/css/app.css`)

1. **Hero backdrop (F8)** — `components/hero.blade.php:46-63`
   - The backdrop is an AniList "large" (~230 px) cover blown up to 1440 px with `blur-2xl scale-110`, and it reads as a smudge.
   - Use the largest source: the AniList `extraLarge` URL, which the provider already reads, and Open Library `-L`.
   - On `lg`, place the image in the right 60% (`lg:inset-y-0 lg:start-auto lg:end-0 lg:w-3/5`, `object-cover object-[center_20%]`) with no blur, or `blur-sm` at most.
   - Scrim it with `lg:bg-linear-to-r from-canvas via-canvas/85 to-transparent`.
   - The phone keeps the full-bleed backdrop with `blur-md` and the existing bottom scrim.
   - Height `min-h-112 lg:min-h-128` (it is `lg:min-h-144`, which leaves about 170 px of empty band under the CTAs in `review-home.png`).
2. **Discover and series plainness (F9)**
   - **Discover** (`discover/⚡index.blade.php:230-243`) becomes `flush`:
     - Open with a full-bleed header band like Series: `bg-surface-deep`, a 3-cover collage at the right, the h1 and intro on the gutter.
     - Format tiles: covers `w-16` (64 px) overlapping with `-space-x-3`, and `ring-2 ring-surface-deep` on each.
     - A tile with no covers shows three `<x-cover-fallback compact>` instead of a hole (see `review-discover.png`, "Quisquam Est" and "Tempora Ut").
   - **Series** (`series/⚡show.blade.php:142-161`):
     - `lg:grid lg:grid-cols-[minmax(0,48rem)_18rem] lg:gap-16`, with the right column holding "About this series": the description (moved out of the header when longer than 2 lines), creators with roles, status and the year span.
     - Volume rows become `py-2` (now 97 px tall), and the index column `min-w-12`.
3. **Phone type (F10) and the page-title scale (new)**
   - Page h1 today:
     - Story `text-section` 24 px (`stories/⚡show.blade.php:164`).
     - Explore 24 px.
     - Discover `text-hero` 56 px at every width (`discover/⚡index.blade.php:232`).
     - Series 24 to 56 px.
   - One rule for every page h1: `text-section md:text-hero tracking-tight text-balance`. Add `line-clamp-3` on Story, because titles reach about 120 characters.
   - If 56 px Black is too heavy for detail pages, add a `--text-title: 2.5rem` / 800 / 1.1 token to Figma first, then use it for Story, Series and Explore.
4. **F11** — fixed in `c657369` (the ring sits inside the bleed track). Close it after the browser run.
5. **Figma lag (F12)**
   - Update the "Phase 4 Screens" frames to match the code:
     - hero full-bleed with ghost controls
     - the palette with its header row
     - the search panel under the hero
     - bleed shelves with contained tracks
     - the 16 px CTA
   - Then run the exit-criterion QA at 1440 and 390.
6. **Shelf fade after a morph (F13)** — `resources/js/components.js:14-36`
   - In the `edge-fade` directive, also `queue()` on `Livewire.hook('morph.updated', ({ el: morphed }) => morphed === el && queue())`, the same hook `comboboxField` uses, and unhook in `cleanup`.
   - Test: lazy-load a shelf, then assert `data-fade-end` without scrolling.
7. **Long series (F14)** — `series/⚡show.blade.php:46`
   - Paginate volumes at 100 per page (`WithPagination` plus `orderedMedia()->unmerged()->with('creators')->paginate(100)`).
   - The Story page's Series tab (`stories/⚡show.blade.php:300`) shows ±5 volumes around the current one, plus "All :count volumes" linking to the series page.
8. **Empty listbox rectangle (F15)** — `components/combobox.blade.php:80-85`
   - Add `not-has-[li]:hidden` to the `x-combobox:options` `<ul>`, so a freeform field with no suggestions shows nothing (no border and no `p-1` box).
   - Test: focus the search panel input, and the listbox `getBoundingClientRect().height === 0`.
9. **Palette trigger (F16)** — `components/command-palette.blade.php:18-28`
   - `h-10 rounded-control border border-hairline bg-surface-input px-3 gap-2 text-meta text-ink-subtle hover:border-border-input hover:text-ink`, with the icon `size-4`.
   - Label `sr-only md:not-sr-only`, plus `aria-label` on the button.
   - `<kbd>` as a keycap: `rounded-control border border-hairline px-1.5 text-legal leading-5`.
   - Also (new): Home above the fold has three search entries (nav "Search", "Search ⌘K" and the Home panel), and Discover adds a fourth ("Filter the catalog").
     - Add a `desktop: false` flag on the Search item in `config('bookflix.navigation')`, read only by `header.blade.php:11`. Desktop keeps the palette; the bottom nav keeps Search.
10. **Tabs (F17)** — `components/tabs.blade.php:33`
    - `text-body` becomes `text-meta font-medium`, and `px-4` becomes `px-3`.
    - Keep `min-h-11`, active `aria-selected:text-ink aria-selected:border-ink` (2 px), and inactive `text-ink-subtle`.
11. **Subject tiles (F18)** — `components/subject-tile.blade.php:14-19`; grids at `discover/⚡index.blade.php:359` and the Home shelf
    - Tile: `h-24 sm:h-28`, shelf width `w-52`, padding `p-3`.
    - Content: code `text-legal`, name `text-body font-medium line-clamp-2`, count `text-legal tabular-nums`.
    - Grid: `md:grid-cols-[repeat(auto-fill,minmax(13rem,16rem))]`. Use `auto-fill`, not `auto-fit`, here and for the format grid at `:268`, which today orphans tile 6 at full width.
    - Also (new): the Explore index uses the Phase 3 `dewey-class-card` (`000`, no brackets, different height, and it shows "0 stories" classes), while Home and Discover use `subject-tile` (`[700]`, hides empty classes). Render `<x-subject-tile fluid>` on Explore and hide empty classes, or give the empty ones the `text-ink-muted` treatment.
12. **Quick links (F19)** — `components/quick-links.blade.php:12-24`
    - Drop the `flux:card` grid for `flex flex-wrap gap-2`, with each link `inline-flex h-11 items-center gap-2 rounded-control border border-hairline bg-surface-input px-3 text-meta text-ink hover:bg-hairline` and icon `size-4`.
    - Content (new): "Browse by subject" duplicates the "Explore by subject" section and its "Explore the Library" link on the same page, and "Filter the catalog" duplicates nav Search. Drop both, so the component renders only when Phase 5 links exist.
13. **Full-bleed rule (new)**
    - The Story page's "More Like This" (`stories/⚡show.blade.php:330`) and the Explore class shelves (`components/class-shelves.blade.php:4`) are inset `rounded-card bg-surface-deep` boxes. That breaks the user's "no black edges" rule and clashes with Home's bands.
    - Make Story and Explore `flush` (`layoutData(['flush' => true])`), with `<x-shelf bleed>` and `px-gutter` on the other blocks.
    - Give the Story header the same band as Series (cover backdrop as in item 1, `bg-surface-deep`, `py-8 lg:py-16`), so the two detail pages match.
14. **Story header dead space (new)** — `stories/⚡show.blade.php:152-222`
    - At 1440, the right 55% of the header is empty, while the description sits under a tab.
    - Move the description into the header column (`mt-4 max-w-prose line-clamp-4 text-body text-ink-subtle`, with a "More" disclosure). The Overview tab keeps Classification, the chips and the warnings.
    - Drop the duplicate class code: the breadcrumb, the story-info `813` and the Classification list say it three times. On the Story page, list only the secondary classes, under "Also shelved in".
15. **Page-top and section rhythm (new)**
    - Story and Search stack `py-12` on the layout's `pt-6`, which makes 72 px before the first line (`review-story.png`, about 95 px under the header). Discover uses `pt-2 lg:pt-6`.
    - Set the layout container (`layouts/app.blade.php:10`) to `pt-6 lg:pt-10`, and change the page sections from `py-12` to `pb-12`.
    - Between top-level sections, use `mt-12 lg:mt-16` everywhere. The Story tabs use `mt-10`, and the shelf `mt-12`.
16. **Search results header (new)** — `search/⚡index.blade.php:282-301`
    - Replace the `sr-only` h1 with a visible `text-section` "Results for “:q”" ("Search" when there's no text), with the count as `text-meta text-ink-subtle` beside it.
    - Use `:browse="false"` on this page's panel: "Browse Discover" sends people away from their results.
    - The count is said twice in two words: "42 stories" and Flux's "Showing 1 to 24 of 42 results". Override the `results` key to "stories" in `lang/en.json`, or hide the summary.
17. **Filter label styles (new)**
    - Flux groups (Type, Status, Length, Rating) render white medium labels, while combobox and range labels (Genres, Themes, Languages, Year) render `text-meta text-ink-subtle`.
    - Use one style, `text-meta font-medium text-ink`, as the default in `combobox.blade.php:35` and on the label span in `range-filter.blade.php:25`.
18. **Type badge noise (new)** — `search/⚡index.blade.php:347`
    - "Manga" covers the art on 24 of 24 tiles.
    - Pass `:badge="$this->results->pluck('media_type_id')->unique()->count() > 1"`. Discover already hides the badge under a type filter.
19. **Chip size (new)** — `stories/⚡show.blade.php:144`, `discover/⚡index.blade.php:227`, `search/⚡index.blade.php:315`
    - The 44 px pills with 14 px text read oversized, as F7 did.
    - Visual `h-9 px-3 text-meta` (36 px), with the 44 px target kept through `relative after:absolute after:-inset-y-1 after:inset-x-0 after:content-['']`.
20. **Copy (new)**
    - "Start reading" becomes "View story" (hero, preview, quick view), and "Start with volume 1" / "Start reading" (series) becomes "See volume 1" / "See the first volume". See R2.
    - Case, per decision (f): Home says "Explore by Subject", but Discover says "Explore by subject" (`:354`), "Browse by format" (`:251`) and "Top genres" (`:299`), and Explore says "Browse by subject". Use Title Case for every section or shelf heading.
    - Series status `ucfirst($series->status->value)` (`series/⚡show.blade.php:87`) is untranslated. Use `__(ucfirst(...))`, as the search facets do.
    - Discover intro genre count: see R5.
21. **Preview card CTAs (new)** — `components/story-summary.blade.php:32-35`
    - Two stacked full-width buttons in a 384 px popover read heavy.
    - Use one row: primary "View story" (`flex-1`) plus a 44 px icon-only secondary (`plus`, `aria-label="Add to Library"`), once the library exists.
22. **Skip link** — R3. **Escape in the sheet** — R4.
