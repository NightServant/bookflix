# Phase 4 — Discovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Users can search (full-text + typo-tolerant + multilingual), filter, browse Discover and Explore, open story and series pages, and land on a non-personalized Home — all built on a reusable, accessible, tokens-only component kit with Motion.

**Architecture:** Search is a fusion of "legs" (keyword/full-text via Scout `database` engine + trigram fallback; subject/Dewey) merged by Reciprocal Rank Fusion (k = 60, D-12) and filtered in SQL before fusion (A13). Phase 7 adds a semantic leg through the same `SearchLeg` contract. UI components are Blade wrappers over Flux free, `@alpinejs/ui`, native HTML and Motion (C5/C6).

**Tech Stack:** Laravel Scout (database engine), Postgres FTS + `pg_trgm` + `unaccent`, Livewire 4, Flux free, Alpine + `@alpinejs/ui`, Motion (free core), noUiSlider, Pest + `pest-plugin-browser`.

**Spec:** `/Users/gabe/Documents/Bookflix Documentation/Bookflix_Specification.md` §20, §21 (keyword leg), §35/§52 (non-personal shelves), §46, §51. **Master plan:** A13, C2/C5/C6, Part E Phase 4.

> **Refresh against the code at phase start for the UI parts:** Figma F4/F5 discovery screens must be signed off (F6) before the UI tasks (4.0b onward). Use `figma:figma-design-to-code` before any `get_design_context`.

**Depends on:** Phase 1 (tokens, layouts, `bottom-nav`, `access-admin`), Phase 2 (`Media`, relations, `search_text`, `Media::resolveSlug`), Phase 3 (`DeweyHierarchy`, `DeweyCounts`, `ClassShelves`, explore routes, `MediaDeweyClass::scopePublic`).

## Global Constraints

- **Phase 1 as built (PR #4, authoritative):** `AssignRequestId` and `SetSecurityHeaders` are global middleware; `EnsureAccountIsActive` stays in the `web` group (new middleware is appended in `bootstrap/app.php` accordingly). Primary navigation is defined **once** in `config('bookflix.navigation')` (items `{label, route, active: [routeIs patterns], icon}`); `layouts/app/header.blade.php` and `components/bottom-nav.blade.php` loop over it, so a phase adds nav items to that config, never to the layouts (`primaryNavs()` in `tests/Pest.php` asserts them). `FILESYSTEM_DISK=local`; public-facing files use a dedicated disk setting like `config('bookflix.avatar_disk')`; **Livewire temporary uploads never use a public disk**. Red (Flux `primary`) is for CTAs only: Save/Update buttons in settings and admin use `variant="filled"`; focus rings use `accent-electric`. ML: `ML_SERVICE_TOKEN` ≥ 32 characters, docs endpoints off unless `ML_ENABLE_DOCS=true`, local `ML_SERVICE_URL=http://localhost:8001`, `MlClient` throws `MlServiceUnavailable` (and `health()` is false) when the token is empty. Deferred to deploy setup: `league/flysystem-aws-s3-v3`, Nightwatch, a shared temporary-upload disk. The spec is not in the repo (public repo): read it at the absolute path above.
- Master-plan constraints apply: Flux **free** only; every added library is MIT/Apache-2.0/ISC/OFL; Motion free core, vanilla API, via Alpine directives; hover/focus by CSS transitions; all animation checks `prefers-reduced-motion`; only `transform`/`opacity` animate; third-party DOM inside `wire:ignore`; stable `wire:key`; init in Alpine `x-init`.
- Tokens only (C2): no raw hex in Blade/CSS outside `resources/css/app.css`; red `#e50914` only for primary CTAs, active accents and the logo; **Trending Now is the only gradient shelf**; no shadows; radii 0/4/8/16; 44 px touch targets; visible focus ring; red/electric-blue never used as small text colour.
- Animated wrappers: `x-motion-*` directives from `resources/js/motion.js`; motion constants mirror CSS custom properties (fast 150 / base 250 / slow 400 ms; spring stiffness 300, damping 30).
- Copied markup (Pines/Basecoat/APG) keeps its MIT license header. Record each added library's license in master plan G2 when merging.
- All new pages live in the `auth` + `verified` route group (the app has no guest pages besides landing/auth). "Guest-safe" in this phase means the queries do not depend on the user, so a public home could be opened later; it does not mean guests can load the page (decision D-26).
- Search legs apply filters in SQL **before** fusion. Dewey filtering uses `MediaDeweyClass::scopePublic` only.
- `Model::preventLazyLoading(! app()->isProduction())` is on (add to `AppServiceProvider` in 4.0a if Phase 1 hasn't).
- Dependencies awaiting approval (A4): `laravel/scout`, `motion`, `@alpinejs/ui`, `nouislider`, `pestphp/pest-plugin-browser` (+ Playwright). Confirm they were approved before Task 4.0a.

## Review Focus

1. Non-Latin, accented and alternative titles: `進撃の巨人`, `Shingeki no Kyojin`, `Attack on Titan`, `Pokemon`↔`Pokémon`, typo `Berserl`. → Task 4.1.
2. Empty / whitespace / punctuation-only / very long / SQL-ish queries (`"'; drop`, `%`, `_`, `"`) never error or return everything. → Task 4.1.
3. Zero results shows a helpful state and is logged; opted-out users are logged anonymously. → Task 4.3.
4. A merged or deleted story URL: merged → 301 to the winner, deleted → 404, never a 500. → Task 4.4.
5. Components keep working after `wire:navigate` and Livewire morphs, and don't animate under reduced motion. → Tasks 4.0b, 4.7, 4.8.

## File structure

```text
app/Discovery/Search/{HybridSearch,SearchFilters,SearchTextBuilder,SearchLeg,Rrf}.php
app/Discovery/Search/Legs/{KeywordLeg,SubjectLeg}.php
app/Discovery/{Shelf,HomeShelves,SimilarMedia}.php   app/Models/SearchQuery.php
resources/js/{app.js,motion.js}
resources/views/components/{cover-tile,shelf,hero,story-info,cta,accordion,tabs,rating-input,combobox,command-palette,range-filter,stepper,story-preview,filter-sheet,class-shelves}.blade.php
resources/views/pages/{search/⚡index,stories/⚡show,series/⚡show,discover/⚡index,home/⚡index}.blade.php
tests/Browser/*  tests/Feature/Discovery/*
```

---

### Task 4.0a: Frontend foundation — Alpine UI, Motion, browser testing

**Files:**
- Modify: `package.json` (`@alpinejs/ui` pinned to the exact Alpine version Livewire bundles — read it from `vendor/livewire/livewire` `package.json`/dist, today 3.17.5; `motion` latest 12.x; `nouislider`; dev `playwright` via `pestphp/pest-plugin-browser`'s install instructions), `composer.json` (`pestphp/pest-plugin-browser`, `laravel/scout`), `resources/js/app.js` (import Livewire's ESM build per `search-docs` "manually bundling alpine": `import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm'`; `Alpine.plugin(ui)`; `Alpine.plugin(motionDirectives)`; `Livewire.start()`), layouts (swap `@livewireScripts` for `@livewireScriptConfig`), `config/scout.php` (published; `driver` = `database`), `.env.example` (`SCOUT_DRIVER=database`), `phpunit.xml` (`SCOUT_DRIVER=database`), `AppServiceProvider` (`Model::preventLazyLoading`)
- Create: `resources/js/motion.js` (C5), `tests/Browser/FoundationTest.php`

**Interfaces:**
- Produces: Alpine directives `x-motion-reveal` (fade + 8 px rise in view, staggered children, `data-stagger` ms default 30), `x-motion-expand` (spring height/scale for previews), `x-motion-crossfade` (hero). Exported constants `MOTION = { fast: 150, base: 250, slow: 400, spring: { stiffness: 300, damping: 30 } }` with a comment linking to the CSS `--motion-*` tokens. With `matchMedia('(prefers-reduced-motion: reduce)').matches` each directive applies final styles and returns. Alpine components from `@alpinejs/ui` (`x-combobox`, `x-tabs`, `x-dialog`, `x-popover`, `x-disclosure`, `x-radio`, …) available on every page.

- [ ] **Step 1: Write the failing browser test** (adapt method names to the installed plugin API — check `search-docs`/the plugin README first; the assertions are the contract)

```php
test('alpine ui and motion are registered on the page', function () {
    $this->actingAs(User::factory()->create());

    visit(route('home'))
        ->assertScript('typeof window.Alpine !== "undefined"', true)
        ->assertScript('typeof window.Alpine.directive === "function" && Alpine.version === "3.17.5"', true)
        ->assertNoJavaScriptErrors();
});

test('motion reveal sets the final state immediately under reduced motion', function () {
    $this->actingAs(User::factory()->create());
    Route::middleware(['web', 'auth'])->get('/_test/reveal', fn () => view('test-reveal')); // fixture view with <ul x-motion-reveal><li>…</li></ul>

    visit('/_test/reveal')->withReducedMotion()
        ->assertScript('getComputedStyle(document.querySelector("li")).opacity', '1');
});
```
(`resources/views/test-reveal.blade.php` is created for tests only under `tests/Fixtures/views` and registered through `View::addLocation` in the test.)

- [ ] **Step 2: Run** `./vendor/bin/pest tests/Browser/FoundationTest.php` → FAIL (`window.Alpine` undefined / no directive).
- [ ] **Step 3: Install and wire** per Files. Run `npm install && npm run build`. Confirm `Alpine.version` equals the Livewire-bundled version (keep them pinned together; note in the PR for R17).
- [ ] **Step 4: Run** → PASS; also `php artisan test --compact` to confirm no regression from the Livewire script change.
- [ ] **Step 5: Commit** — `git commit -m "feat: alpine ui, motion directives, scout and browser testing foundation"`

---

### Task 4.0b: Component acceptance harness and component kit (parallel subagents)

The kit starts here. After 4.0a lands, **one implementer subagent per component** (`superpowers:dispatching-parallel-agents`; files are independent). Use the F3 Figma frame for each (`figma:figma-design-to-code`), adapt to Blade + Flux + tokens.

**Files (per component):** `resources/views/components/<name>.blade.php`, `tests/Feature/Components/<Name>Test.php`, `tests/Browser/Components/<Name>Test.php`. Shared: `tests/Feature/Components/NoRawColorsTest.php`.

**Interfaces — produces (props are the contract later tasks consume):**

| Component | Props / slots | Source (C6 gap map) | Component-specific assertion |
|---|---|---|---|
| `<x-cover-tile>` | `:media` (Media), `size` S\|M\|L, `:progress` ?int, `:reason` ?string | native + CSS hover scale 1.05 (`motion/fast`) | renders `<img loading="lazy" width height alt>`, links to `route('stories.show', slug)`, 2:3 aspect, media-type badge, progress bar only when given |
| `<x-shelf>` | `title`, `:featured` bool, optional `href` for a "See all" link (C7 #5), default slot of tiles | scroll-snap + Alpine arrows | arrows are `<button aria-label>`; `featured` adds the only allowed gradient (`from-featured-from via-featured-via to-featured-to`); `plain` uses `bg-surface-deep` |
| `<x-hero>` | `:slides` (list of Media) or `:media`, `size` L\|M\|S, `autoplay` (default false), cta slot (C7 #1) | Motion crossfade | one `<h1>` per active slide, counter `01 / 05` in tabular figures, prev/next/pause `<button aria-label>`, `aria-live="polite"`, `x-motion-crossfade`, no autoplay under reduced motion |
| `<x-story-info>` | `:media` | native | order: type · year · length · age rating · Dewey code (public primary only); omits missing parts without stray separators |
| `<x-cta>` | `href`/`wire:click`, label slot, `variant` primary\|secondary | `flux:button` | primary uses `variant="primary"`; 24 px label token (`text-cta`) |
| `<x-accordion>` | `title`, `name` (optional group), slot | native `<details name>` + `x-collapse` | uses `<details>/<summary>`; group `name` makes siblings exclusive |
| `<x-tabs>` | `:tabs` (key→label), default slot per tab | `@alpinejs/ui` tabs | `role=tablist/tab/tabpanel`, arrow-key navigation, `aria-selected` |
| `<x-rating-input>` | `wire:model` (1–10 int), `readonly` | `@alpinejs/ui` radio group as 5 stars with half stars (D-14) | 10 radio inputs, value = half-star steps, keyboard arrows |
| `<x-combobox>` | `:options`, `wire:model`, `multiple`, `placeholder` | `@alpinejs/ui` combobox | `role=combobox/listbox/option`, type-ahead filter, multiple chips, Escape closes, focus returns |
| `<x-command-palette>` | opens on ⌘K / Ctrl-K | `@alpinejs/ui` dialog + combobox | focus trap, Escape closes, results from the same Livewire search |
| `<x-range-filter>` | `min`, `max`, `wire:model.live` (two values) | noUiSlider inside `wire:ignore` | two handles with `aria-valuenow`, values synced to Livewire, destroyed/recreated safely on morph |
| `<x-search-panel>` | `placeholder`, `wire:model` (C7 #2) | `flux:input` + `<x-combobox>` | label "What would you like to find?" is a real `<label>`; secondary CTA to Discover |
| `<x-quick-links>` | `:links` (label, route, icon) (C7 #3) | `flux:card` links | 44 px targets, equal heights, keyboard order matches visual order |
| `<x-view-toggle>` | `wire:model` grid\|list (C7 #7) | `@alpinejs/ui` radio group | two radios with labels, `aria-checked`, choice reflected in the URL |
| `<x-path-card>` | `:class` (DeweyClass), `:count` (C7 #12) | `flux:card` | renders code, name, count; link to `explore.show` |
| `<x-stat-row>` | `:stats` (label, value) (C7 #11) | Blade | values are plain text, tabular figures, labels uppercase eyebrow |
| `<x-stepper>` | `:steps`, `:current` | Blade (Basecoat/Pines markup reference, license header kept) | `aria-current="step"` on the current step |
| `<x-story-preview>` | `:media` | `@alpinejs/ui` popover + `x-anchor` + `x-motion-expand` | opens on hover/focus after 300 ms, closes on Escape/blur |
| `<x-filter-sheet>` | slot | `flux:modal variant="flyout"` | mobile only (`lg:hidden` trigger), traps focus |

**Shared acceptance tests (every component):**

```php
test('component views use design tokens and no raw colors', function () {
    $offenders = collect(File::allFiles(resource_path('views/components')))
        ->filter(fn ($f) => preg_match('/#[0-9a-fA-F]{3,8}\b|rgb\(|hsl\(/', $f->getContents()))
        ->map->getFilename();

    expect($offenders)->toBeEmpty();
});
```
Per component, a browser test: `visit(<fixture page>)->assertNoAccessibilityIssues()`; the keyboard pattern from its APG row above (`->keys('Tab')`, arrows, Escape); under `->withReducedMotion()` the animated ones reach their final state without transitions; after `wire:navigate` to another page and back, the component still works (Review Focus #5).

- [ ] **Step 1: Per component** write the Feature test (render with a factory `Media`, assert structure from the table) and the Browser test, using fixture pages under a test-only route group.
- [ ] **Step 2: Run** the component's tests → FAIL (view not found).
- [ ] **Step 3: Implement** the component per the table. Third-party DOM inside `wire:ignore`; Alpine `x-init` for setup; `wire:key` on loops.
- [ ] **Step 4: Run** the component tests + `NoRawColorsTest` → PASS.
- [ ] **Step 5: Commit** per component — `git commit -m "feat: <name> component"`. Reviewer compares each with its F3 frame and checks copied-code license headers.

---

### Task 4.1: Scout database engine, `search_text`, indexes, keyword + subject legs (Review Focus #1, #2)

**Files:**
- Create: migration `2026_10_25_000001_add_search_indexes_to_media.php` — `immutable_unaccent(text)` SQL function wrapper (`create or replace function immutable_unaccent(text) returns text language sql immutable parallel safe as $$ select public.unaccent('public.unaccent', $1) $$`); GIN full-text index `media_search_fts` on `to_tsvector('simple', search_text)` (exact expression must match what the Scout database engine emits — verified by the EXPLAIN test); GIN trigram indexes `media_title_trgm_unaccent` on `immutable_unaccent(lower(title)) gin_trgm_ops` and `media_alt_titles_trgm` on `immutable_unaccent(lower(alternative_titles::text)) gin_trgm_ops`; btree indexes on `media(media_type_id)`, `(publication_year)`, `(status)` if Phase 2 didn't create them
- Create: `app/Discovery/Search/SearchTextBuilder.php`, `SearchLeg.php` (interface), `Legs/KeywordLeg.php`, `Legs/SubjectLeg.php`, `Rrf.php`, `HybridSearch.php`, `SearchFilters.php` (DTO, filled in 4.2)
- Modify: `app/Models/Media.php` (`use Searchable`; `#[SearchUsingFullText(['search_text'], ['language' => 'simple'])]`; `toSearchableArray()`; `shouldBeSearchable()` → not soft-deleted), `IngestionPipeline` + admin media edit (use `SearchTextBuilder::build(Media): string`), a `bookflix:rebuild-search-text` command
- Test: `tests/Feature/Discovery/SearchTest.php`, `tests/Unit/Discovery/RrfTest.php`

**Interfaces:**
- Produces: `SearchTextBuilder::build(Media $media): string` — lowercased titles, alternative titles, creators, series, characters, genres, themes, tags, publisher, plus an accent-stripped copy (via `Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC')`; CJK untouched). `interface SearchLeg { public function key(): string; /** @return list<int> media ids, best first */ public function ids(string $query, SearchFilters $filters, int $limit = 100): array; }`. `Rrf::fuse(array $rankedLists, int $k = 60): array<int, float>` (media id → score, descending). `HybridSearch::search(string $query, SearchFilters $filters, ?User $user, int $perPage = 24): LengthAwarePaginator` (A7). Query normalization: trim, collapse whitespace, cap 200 chars, empty/punctuation-only → empty paginator (no query run). All SQL uses bindings (the `%`/`_` of `ILIKE` escaped).
- `KeywordLeg`: Scout FTS over `search_text` (websearch syntax **disabled** — user input is sanitized to plain terms) ∪ trigram: `similarity(immutable_unaccent(lower(title)), immutable_unaccent(lower(?))) > 0.3` ∪ same on `alternative_titles::text` ∪ for queries containing non-ASCII, `alternative_titles::text ILIKE %q%` fallback. `SubjectLeg`: query terms matching `dewey_classes.name` (accent-insensitive) or code → media in that subtree with public primary class.

- [ ] **Step 1: Write the failing tests**

```php
beforeEach(function () {
    $this->aot = Media::factory()->manga()->create(['title' => 'Attack on Titan', 'alternative_titles' => ['進撃の巨人', 'Shingeki no Kyojin']]);
    $this->berserk = Media::factory()->manga()->create(['title' => 'Berserk']);
    $this->pokemon = Media::factory()->create(['title' => 'Pokémon Adventures']);
    Media::query()->each(fn ($m) => $m->update(['search_text' => app(SearchTextBuilder::class)->build($m)]));
});

test('titles are found across scripts accents and alternative titles', function (string $q, string $expected) {
    $titles = search($q)->pluck('title');
    expect($titles->first())->toBe($expected);
})->with([
    ['進撃の巨人', 'Attack on Titan'],
    ['Shingeki no Kyojin', 'Attack on Titan'],
    ['Attack on Titan', 'Attack on Titan'],
    ['Pokemon', 'Pokémon Adventures'],
    ['Pokémon', 'Pokémon Adventures'],
    ['Berserl', 'Berserk'],
]);

test('hostile or degenerate queries are safe', function (string $q) {
    expect(fn () => search($q))->not->toThrow(Throwable::class);
    expect(search($q)->total())->toBe(0);
})->with(['', '   ', '%', '_', '"', "'; drop table media;--", '!!!', str_repeat('a', 5000)]);

test('percent is not a wildcard', function () {
    expect(search('%')->total())->toBe(0);
});

test('soft deleted media is never returned', function () {
    $this->berserk->delete();
    expect(search('Berserk')->total())->toBe(0);
});

test('the full text index is used by the generated query', function () {
    DB::statement('set enable_seqscan = off');
    $plan = collect(DB::select('explain '.Media::search('titans')->toRawSql()))->pluck('QUERY PLAN')->implode("\n");
    expect($plan)->toContain('media_search_fts');
});
```
If `Media::search()->toRawSql()` doesn't exist, build the SQL with the Scout database engine's query builder callback (`->query(fn ($q) => dd($q->toRawSql()))`) — the assertion (the plan uses `media_search_fts`) stays; if the plan doesn't, change the index expression to what the engine emits, not the test.

```php
// tests/Unit/Discovery/RrfTest.php
test('rrf rewards items ranked high in several lists', function () {
    $fused = Rrf::fuse([[1, 2, 3], [3, 1, 4]], k: 60);
    expect(array_key_first($fused))->toBe(1)
        ->and($fused[1])->toEqualWithDelta(1 / 61 + 1 / 62, 1e-9)
        ->and(array_keys($fused))->toBe([1, 3, 2, 4]);
});

test('rrf with no lists is empty', function () { expect(Rrf::fuse([]))->toBe([]); });

// SearchTest
test('a subject name finds stories classified under it', function () {
    classified(Media::factory()->create(['title' => 'Some Novel']), '813');
    expect(search('literature')->pluck('title'))->toContain('Some Novel');
});

test('legs are fused so a title match outranks a subject only match', function () {
    classified($this->berserk, '741.52');
    expect(search('berserk')->first()->title)->toBe('Berserk');
});
```
(`search($q, $filters = null)` Pest helper: `app(HybridSearch::class)->search($q, $filters ?? new SearchFilters, null)`. Seed Dewey in `beforeEach` for subject tests: class name "Literature" comes from the CSV.)

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Discovery tests/Unit/Discovery` → FAIL.
- [ ] **Step 3: Implement.** Also `php artisan scout:import "App\Models\Media"` documented in the README section of the phase PR (database engine needs no import, but `Searchable` hooks must not queue: set `SCOUT_QUEUE=false`).
- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: scout database search with trigram and subject legs fused by rrf"`

---

### Task 4.2: `SearchFilters` and filter application

**Files:**
- Modify: `app/Discovery/Search/SearchFilters.php`, both legs (apply filters before returning ids), `config/bookflix.php` (`length_buckets` => `['pages' => ['short' => 200, 'medium' => 500], 'chapters' => ['short' => 30, 'medium' => 100]]`)
- Test: `tests/Feature/Discovery/SearchFiltersTest.php`

**Interfaces:**
- Produces (A7): `final readonly class SearchFilters` with constructor params (all optional, defaults empty/null): `array $mediaTypes` (slugs), `array $genres` (slugs), `array $themes` (slugs), `array $languages`, `?int $yearFrom`, `?int $yearTo`, `?float $minRating`, `array $statuses` (`PublicationStatus` values), `?string $length` (`short|medium|long`), `array $creatorIds`, `array $publisherIds`, `?string $deweyCode`; `static fromArray(array $input): self` (ignores unknown keys, coerces types, drops invalid enum values); `toArray(): array`; `isEmpty(): bool`; `apply(Builder $query): Builder` (applied by each leg). `ratings`: `average_rating >= minRating`. Length: pages bucket when `page_count` is set, else chapter bucket (config above). Dewey: public subtree. Filters work with an **empty query** through `HybridSearch::browse(SearchFilters, int $perPage = 24)` (used by Discover) which orders by `ratings_count desc, published_on desc`.

- [ ] **Step 1: Write the failing tests** — one per filter (dataset), plus a combination:

```php
test('each filter narrows results', function (SearchFilters $filters, array $expectedTitles) {
    seedFilterCatalog(); // 6 media with distinct type/genre/theme/lang/year/rating/status/pages/creator/publisher/dewey
    expect(app(HybridSearch::class)->browse($filters)->pluck('title')->sort()->values()->all())->toBe($expectedTitles);
})->with([
    'type' => [new SearchFilters(mediaTypes: ['manga']), ['Berserk']],
    'genre' => [new SearchFilters(genres: ['fantasy']), ['Berserk', 'Dune Fantasy']],
    'theme' => [new SearchFilters(themes: ['politics']), ['Dune Fantasy']],
    'language' => [new SearchFilters(languages: ['ja']), ['Berserk']],
    'year range' => [new SearchFilters(yearFrom: 1990, yearTo: 2000), ['Berserk']],
    'rating' => [new SearchFilters(minRating: 8.0), ['Berserk']],
    'status' => [new SearchFilters(statuses: ['completed']), ['Dune Fantasy']],
    'length short' => [new SearchFilters(length: 'short'), ['Short Story']],
    'length chapters' => [new SearchFilters(length: 'long'), ['Berserk']],
    'creator' => [new SearchFilters(creatorIds: [/* resolved in seedFilterCatalog via $this->creator */]), ['Berserk']],
    'dewey subtree' => [new SearchFilters(deweyCode: '810'), ['Dune Fantasy']],
]);

test('filters combine with a text query', function () {
    seedFilterCatalog();
    $r = app(HybridSearch::class)->search('berserk', new SearchFilters(mediaTypes: ['novel']), null);
    expect($r->total())->toBe(0);
});

test('fromArray drops unknown keys and invalid values', function () {
    $f = SearchFilters::fromArray(['mediaTypes' => 'manga', 'yearFrom' => 'abc', 'statuses' => ['bogus', 'ongoing'], 'evil' => 1]);
    expect($f->mediaTypes)->toBe(['manga'])->and($f->yearFrom)->toBeNull()->and($f->statuses)->toBe(['ongoing']);
});

test('rejected or low confidence dewey rows do not satisfy a dewey filter', function () {
    classified(Media::factory()->create(['title' => 'Hidden']), '813', status: 'rejected');
    expect(app(HybridSearch::class)->browse(new SearchFilters(deweyCode: '810'))->pluck('title'))->not->toContain('Hidden');
});
```
(Pest datasets are evaluated before the test body, so IDs of seeded rows aren't known there. Make the dataset values filter *arrays* — `['mediaTypes' => ['manga']]`, and for creators/publishers `['creatorName' => 'Kentaro Miura']` — and have the test body resolve names to IDs after `seedFilterCatalog()`, then call `SearchFilters::fromArray`. Write the `SearchFilters` constructor calls in the table above that way.)

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: search filters"`

---

### Task 4.3: Search page, `search_queries` logging and click-through (Review Focus #3)

**C7 patterns (master plan C7):** C7 #2/#7 — add `<x-search-panel>` at the top of the page and a removable filter-chip row plus `<x-view-toggle>` above results; add tests `the view toggle switches grid and list and is reflected in the url` and `active filters render as removable chips`.

**Files:**
- Create: migration `2026_10_25_000002_create_search_queries_table.php` (A6 `search_queries`, created in this phase per D-22), `app/Models/SearchQuery.php` + factory, `resources/views/pages/search/⚡index.blade.php`, `app/Discovery/Search/SearchLogger.php`
- Modify: `routes/web.php` (`Route::livewire('search', 'pages::search.index')->name('search')`), `config/bookflix.php` `navigation` (add the Search item; it also appears in the bottom nav), `layouts/app/header.blade.php` (only the `<x-command-palette>` trigger)
- Test: `tests/Feature/Discovery/SearchPageTest.php`

**Interfaces:**
- Consumes: `HybridSearch`, `SearchFilters`, `User::personalizationEnabled()`.
- Produces: `SearchLogger::log(string $query, SearchFilters $filters, int $resultsCount, ?User $user, ?int $previousQueryId): SearchQuery` — `user_id` null when the user opted out; `session_hash` = `hash_hmac('sha256', session()->getId(), config('app.key'))`; `normalized` = lowercased, accent-stripped, whitespace-collapsed. Page state: `#[Url] string $q`, `#[Url] array $filters`, `WithPagination`; `lastQueryId` tracked in component state. Result links append `?sq={search_query_id}` (Phase 5's `InteractionRecorder` turns that into `click` with `context.search_query_id`; this phase only emits the parameter). Debounce `wire:model.live.debounce.300ms`.

- [ ] **Step 1: Write the failing tests**

```php
test('searching shows results and logs one query row', function () {
    Media::factory()->create(['title' => 'Berserk', 'search_text' => 'berserk']);
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::search.index')->set('q', 'berserk')->assertSee('Berserk');

    $row = SearchQuery::sole();
    expect($row->query)->toBe('berserk')->and($row->results_count)->toBe(1)->and($row->user_id)->toBe($user->id)->and($row->session_hash)->toHaveLength(64);
});

test('a refinement links to the previous query', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::search.index')->set('q', 'berserk')->set('q', 'berserk manga');
    [$first, $second] = SearchQuery::orderBy('id')->get()->all();
    expect($second->previous_query_id)->toBe($first->id);
});

test('zero results shows the empty state and is logged', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::search.index')->set('q', 'zzzxxyy')->assertSee('No stories found')->assertSee('Try');
    expect(SearchQuery::sole()->results_count)->toBe(0);
});

test('opted out users are logged anonymously', function () {
    $this->actingAs($user = User::factory()->create());
    $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);
    Livewire::test('pages::search.index')->set('q', 'berserk');
    expect(SearchQuery::sole()->user_id)->toBeNull();
});

test('the same settled query is not logged twice', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::search.index')->set('q', 'berserk')->call('$refresh');
    expect(SearchQuery::count())->toBe(1);
});

test('queries shorter than two characters are not run or logged', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::search.index')->set('q', 'a');
    expect(SearchQuery::count())->toBe(0);
});

test('result links carry the search query id', function () {
    $m = Media::factory()->create(['title' => 'Berserk', 'search_text' => 'berserk']);
    $this->actingAs(User::factory()->create());
    $html = Livewire::test('pages::search.index')->set('q', 'berserk')->html();
    expect($html)->toContain(route('stories.show', $m->slug).'?sq='.SearchQuery::sole()->id);
});

test('filters in the url restore the page state', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('search', ['q' => 'berserk', 'filters' => ['mediaTypes' => ['manga']]]))->assertOk();
});

test('guests are redirected to login', function () { $this->get(route('search'))->assertRedirect(route('login')); });
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** Filter UI: desktop sidebar (`<x-combobox multiple>` for genres/themes/languages; `flux:checkbox.group` for type/status; `<x-range-filter>` for year; select for length/rating), mobile `<x-filter-sheet>`. **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: search page with analytics logging"`

---

### Task 4.4: Story detail page and `SimilarMedia` (Review Focus #4)

**C7 patterns (master plan C7):** C7 #9 — breadcrumb (Dewey path via `<x-dewey-breadcrumb>`) above the title and "Previous / Next in series" links; test `series neighbours link in both directions and are absent for the first/last or standalone story`.

**Files:**
- Create: `resources/views/pages/stories/⚡show.blade.php`, `app/Discovery/SimilarMedia.php`
- Modify: `routes/web.php` (`Route::livewire('stories/{slug}', 'pages::stories.show')->name('stories.show')`)
- Test: `tests/Feature/Discovery/StoryPageTest.php`

**Interfaces:**
- Consumes: `Media::resolveSlug` (Phase 2), `<x-story-info>`, `<x-accordion>`, `<x-tabs>`, `ClassShelves` n/a.
- Produces: `SimilarMedia::for(Media $media, int $limit = 12): Collection<Media>` — order: same series siblings, then by shared genres+themes count desc, then same primary-Dewey division; excludes self; eager-loaded. Phase 6 swaps the implementation for `media_similarities`. Slug resolution: merged-away slug → `redirect()->route('stories.show', $winner->slug, 301)`; unknown or soft-deleted-not-merged → 404. Sections: identity (title, alt titles, creators with roles, publisher), `<x-story-info>`, description, classification (public classes with `<x-dewey-breadcrumb>`, linking to explore), genres/themes/tags chips (links to Search with a filter), series block (links to series page), related (`media_relations`), content-warnings accordion (only when non-empty), attribution (from `external_sources.attribution` of the story's sources), similar shelf, tabs Overview / Series / Related.

- [ ] **Step 1: Write the failing tests**

```php
test('the story page renders its sections', function () {
    $m = fullMedia(); // factory helper: creators, genres, themes, series, relation, content warnings, public dewey 813, external source attribution
    $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))
        ->assertOk()->assertSee($m->title)->assertSee('Frank Herbert')->assertSee('Science Fiction')
        ->assertSee('Content warnings')->assertSee('Data from');
});

test('hidden classifications are not shown on the page', function () {
    $m = classified(Media::factory()->create(), '813', status: 'rejected');
    $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))->assertDontSee('Literature');
});

test('the content warnings accordion is absent when there are none', function () {
    $m = Media::factory()->create(['content_warnings' => []]);
    $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))->assertDontSee('Content warnings');
});

test('a soft deleted story is a 404', function () {
    $m = Media::factory()->create(); $m->delete();
    $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))->assertNotFound();
});

test('a merged slug redirects with 301', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    app(Deduplicator::class)->merge($winner, $loser);
    $this->actingAs(User::factory()->create())->get(route('stories.show', $loser->slug))->assertStatus(301)->assertRedirect(route('stories.show', $winner->slug));
});

test('an unknown slug is a 404', function () {
    $this->actingAs(User::factory()->create())->get('/stories/nope')->assertNotFound();
});

test('similar media prefers series then shared genres and excludes itself', function () {
    [$base, $sibling, $sharesGenre, $unrelated] = similarFixture();
    expect(app(SimilarMedia::class)->for($base)->pluck('id')->all())->toBe([$sibling->id, $sharesGenre->id]);
});

test('the story page runs a bounded number of queries', function () {
    $m = fullMedia();
    $this->actingAs(User::factory()->create());
    DB::enableQueryLog();
    $this->get(route('stories.show', $m->slug))->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(25);
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (`wire:navigate` on links; page meta title). **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: story detail page with similar stories"`

---

### Task 4.5: Series page

**C7 patterns (master plan C7):** C7 #9 — volumes listed with bracketed index numbers in tabular figures (`[01]`, `[01.5]`); keep the ordering tests unchanged.

**Files:**
- Create: `resources/views/pages/series/⚡show.blade.php`; Modify: `routes/web.php` (`series/{slug}` → `series.show`)
- Test: `tests/Feature/Discovery/SeriesPageTest.php`

**Interfaces:** consumes `Series`, `media_series.position decimal(8,2)`, `label`. Produces `Series::orderedMedia(): BelongsToMany` ordered by `pivot position asc nulls last, title`.

- [ ] **Step 1: Write the failing tests**

```php
test('volumes are ordered 1, 1.5, 2', function () {
    $series = Series::factory()->create();
    foreach ([[2, 'B'], [1, 'A'], [1.5, 'AB']] as [$pos, $t]) { $series->media()->attach(Media::factory()->create(['title' => $t]), ['position' => $pos]); }
    $this->actingAs(User::factory()->create())->get(route('series.show', $series->slug))->assertSeeInOrder(['A', 'AB', 'B']);
});
test('items without a position come last and an unknown series is 404', function () {
    $series = Series::factory()->create();
    $series->media()->attach(Media::factory()->create(['title' => 'NoPos']), ['position' => null]);
    $series->media()->attach(Media::factory()->create(['title' => 'First']), ['position' => 1]);
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('series.show', $series->slug))->assertSeeInOrder(['First', 'NoPos']);
    $this->actingAs($user)->get('/series/missing')->assertNotFound();
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: series page"`

---

### Task 4.6: Discover page

**C7 patterns (master plan C7):** C7 #2/#3/#6 — Discover opens with `<x-search-panel>`, then `<x-quick-links>` (Continue reading, New releases, Browse by subject, My collections), then browse tiles by media type, genre and subject with live counts; test `quick links point at existing routes` and `type tiles show counts`.

**Files:**
- Create: `resources/views/pages/discover/⚡index.blade.php`; Modify: `routes/web.php` (`discover`), `config('bookflix.navigation')` (add the Discover item)
- Test: `tests/Feature/Discovery/DiscoverPageTest.php`

**Interfaces:** consumes `HybridSearch::browse`, `MediaFamily`, `DeweyCounts`. Produces route `discover`; `#[Url] ?string $family` (prose|sequential_art|illustrated|interactive), `#[Url] ?string $type` (media type slug), `#[Url] ?string $genre`; sections: media-type tiles with counts, top genres, "Explore by subject" row linking to `explore.index`.

- [ ] **Step 1: Write the failing tests**

```php
test('discover filters by media family', function () {
    Media::factory()->manga()->create(['title' => 'Panel Story']);
    Media::factory()->novel()->create(['title' => 'Prose Story']);
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::discover.index')->set('family', 'sequential_art')->assertSee('Panel Story')->assertDontSee('Prose Story');
});
test('a genre filter and the subject entry are present', function () {
    $this->actingAs(User::factory()->create())->get(route('discover'))->assertOk()->assertSee('Explore by subject')->assertSee(route('explore.index'));
});
test('an invalid family is ignored', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::discover.index')->set('family', 'bogus')->assertHasNoErrors()->assertOk();
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: discover page"`

---

### Task 4.7: Home v1 (non-personal shelves)

**C7 patterns (master plan C7):** C7 #1/#5/#16 — the hero is the numbered slider (`<x-hero :slides>` with the top 5 trending stories, autoplay off); each shelf header gets a "See all" `href`; optional layered-parallax is out of this phase's acceptance. Tests: `hero renders the counter and one h1`, `next control advances the slide (browser)`, `no autoplay and no transition under reduced motion (browser)`, `shelf headers link to filtered search`.

**Files:**
- Create: `app/Discovery/Shelf.php` (readonly: `string $key`, `string $title`, `Collection $items`, `bool $featured = false`, `?string $reasonLabel = null`), `app/Discovery/HomeShelves.php`
- Modify: `resources/views/pages/home/⚡index.blade.php` (replaces Phase 1's empty state; keep the heading + empty-state CTA when the catalog is empty), `app/Models/Media.php` (`scopeTrending(Builder)` interim: `ratings_count desc, published_on desc` — **Phase 6 task 6.3 redefines it over `media_popularity`**)
- Test: `tests/Feature/Discovery/HomeV1Test.php`, `tests/Browser/HomeTest.php`

**Interfaces:**
- Produces: `HomeShelves::guestSafe(int $perShelf = 20): Collection<Shelf>` — keys in the §52 order: `trending` (featured/gradient), `new-releases`, `explore-by-subject` (items are root `DeweyClass` with counts). Phase 6's `ShelfBuilder` extends this list; keep `Shelf` stable. Home also renders a `<x-hero>` of the highest-rated recent story (`Media::scopeTrending()->first()`), lazy Livewire components for shelves (`#[Lazy]`), cached 10 minutes under `home:shelves:v1`.

- [ ] **Step 1: Write the failing tests**

```php
test('home shows trending, new releases and explore by subject in order', function () {
    Media::factory()->count(5)->create();
    $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()
        ->assertSeeInOrder(['Trending Now', 'New Releases', 'Explore by Subject']);
});

test('trending is the only gradient shelf', function () {
    Media::factory()->count(3)->create();
    $html = $this->actingAs(User::factory()->create())->get(route('home'))->getContent();
    expect(substr_count($html, 'from-featured-from'))->toBe(1);
});

test('an empty catalog still renders an empty state without errors', function () {
    $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()->assertSee('Discover');
});

test('guest safe shelves do not depend on a user', function () {
    Media::factory()->count(3)->create();
    expect(app(HomeShelves::class)->guestSafe()->pluck('key')->all())->toBe(['trending', 'new-releases', 'explore-by-subject']);
});

test('home runs a bounded number of queries without lazy loading', function () {
    Media::factory()->count(30)->create();
    $this->actingAs(User::factory()->create());
    DB::enableQueryLog();
    $this->get(route('home'))->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(25);
});

test('new releases are ordered by published date and exclude unpublished future dates beyond a month', function () {
    Media::factory()->create(['title' => 'Old', 'published_on' => now()->subYears(2)]);
    Media::factory()->create(['title' => 'New', 'published_on' => now()->subDays(3)]);
    $shelf = app(HomeShelves::class)->guestSafe()->firstWhere('key', 'new-releases');
    expect($shelf->items->pluck('title')->first())->toBe('New');
});
```
Browser test: reduced-motion — `visit(route('home'))->withReducedMotion()` every `[x-motion-reveal] > *` has computed opacity 1 immediately; and `wire:navigate` to Search and back keeps shelves working.

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** using `<x-hero>`, `<x-shelf>`, `<x-cover-tile>`, `<x-class-shelves>` (explore row). **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: home v1 with non-personal shelves"`

---

### Task 4.8: Browser smoke tests and accessibility

**Files:** `tests/Browser/SmokeTest.php`, `tests/Browser/AccessibilityTest.php`

**Interfaces:** consumes everything above.

- [ ] **Step 1: Write the tests**

```php
test('landing to login to home to search to detail works at desktop and mobile', function (int $width, int $height) {
    $user = User::factory()->create(); $m = Media::factory()->create(['title' => 'Berserk', 'search_text' => 'berserk']);

    visit(route('landing'))->resize($width, $height)->click('Sign in')
        ->fill('email', $user->email)->fill('password', 'password')->press('Log in')
        ->assertPathIs('/home')->assertSee('Trending Now')
        ->click('Search')->type('q', 'berserk')->assertSee('Berserk')
        ->click('Berserk')->assertPathIs("/stories/{$m->slug}")->assertSee('Berserk')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
})->with([[1440, 900], [390, 844]]);

test('every discovery page passes the accessibility audit', function (string $path) {
    $this->actingAs(User::factory()->create());
    visit($path)->assertNoAccessibilityIssues();
})->with(['/home', '/discover', '/search', '/explore', '/explore/800']);

test('the command palette opens with the keyboard and closes with escape', function () {
    $this->actingAs(User::factory()->create());
    visit(route('home'))->keys('Meta+k')->assertVisible('[role=dialog]')->keys('Escape')->assertMissing('[role=dialog]');
});
```
- [ ] **Step 2: Run** `./vendor/bin/pest tests/Browser` → FAIL until all prior tasks are merged into the branch. **Step 3: Fix** whatever the audit finds at the root cause (never skip). **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "test: discovery browser smoke and accessibility"`

---

## Phase 4 exit criteria
- [ ] `composer test` and `./vendor/bin/pest tests/Browser` green; Phase 2–3 tests still green.
- [ ] Lighthouse performance ≥ 90 on Home at 390 px (browser pane); F7 design QA screenshots compared with Figma at 1440 and 390.
- [ ] `/code-review`, `design:accessibility-review`, `impeccable` polish pass; library licenses recorded in master plan G2.
- [ ] Ask the user to run the full `php artisan test --compact`.

## Self-review notes
- Spec coverage: §20 (4.1–4.2), §21 keyword/Dewey legs (4.1), §46 (4.3), §35/§52 non-personal shelves (4.7), §51 components (4.0b).
- Master plan decisions applied: D-22 (`search_queries` created in Phase 4), D-23 (interim trending), D-26; `SearchLeg` interface + two legs; `immutable_unaccent` wrapper + accent-folded `search_text`; interim `Media::scopeTrending()` because `media_popularity` only exists in Phase 6; click-through is a `?sq=` URL parameter until Phase 5's `InteractionRecorder`.
