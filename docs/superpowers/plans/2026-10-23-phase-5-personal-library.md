# Phase 5 — Personal Library Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each user keeps a library with statuses and favorites, collections, manual reading progress and history, ratings, a data export, and an onboarding flow — and every meaningful action becomes a privacy-respecting interaction signal for Phase 6.

**Architecture:** Single-purpose actions in `app/Actions/{Library,Ratings}` do the writes and fire a `LibraryChanged` event (Phase 6 hooks cache invalidation onto it). `InteractionRecorder` is the only writer of `user_interactions`, gated by `User::personalizationEnabled()` and queued. Pages are Livewire single-file components over the Phase 4 component kit.

**Tech Stack:** Laravel 13, Livewire 4 (`wire:sort`), Flux free, Pest on Postgres, queues (Redis/Horizon from Phase 2; `sync` in tests).

**Spec:** `/Users/gabe/Documents/Bookflix Documentation/Bookflix_Specification.md` §8, §33, §36, §38, §39 (ratings), §41, §44, §60. **Master plan:** A6 "Library and signals" + `user_preferences`, A7 (`InteractionRecorder`), D-6, D-14, Part E Phase 5.

**Depends on:** Phase 1 (`User::personalizationEnabled()`, `Visibility`, `profiles.onboarded_at`, `PurgeDeactivatedAccounts`, `access-admin`), Phase 2 (`Media`, `Deduplicator::merge`, `MediaType`, `Genre`), Phase 3 (`ClassShelves`), Phase 4 (`Shelf`, `HomeShelves`, component kit incl. `<x-rating-input>`, `<x-stepper>`, `<x-cover-tile>`, `<x-combobox>`, `search_queries`, `?sq=` result links, `stories.show` page).

## Global Constraints

- **Phase 1 as built (PR #4, authoritative):** `AssignRequestId` and `SetSecurityHeaders` are global middleware; `EnsureAccountIsActive` stays in the `web` group (new middleware is appended in `bootstrap/app.php` accordingly). Primary navigation is defined **once** in `config('bookflix.navigation')` (items `{label, route, active: [routeIs patterns], icon}`); `layouts/app/header.blade.php` and `components/bottom-nav.blade.php` loop over it, so a phase adds nav items to that config, never to the layouts (`primaryNavs()` in `tests/Pest.php` asserts them). `FILESYSTEM_DISK=local`; public-facing files use a dedicated disk setting like `config('bookflix.avatar_disk')`; **Livewire temporary uploads never use a public disk**. Red (Flux `primary`) is for CTAs only: Save/Update buttons in settings and admin use `variant="filled"`; focus rings use `accent-electric`. ML: `ML_SERVICE_TOKEN` ≥ 32 characters, docs endpoints off unless `ML_ENABLE_DOCS=true`, local `ML_SERVICE_URL=http://localhost:8001`, `MlClient` throws `MlServiceUnavailable` (and `health()` is false) when the token is empty. Deferred to deploy setup: `league/flysystem-aws-s3-v3`, Nightwatch, a shared temporary-upload disk. The spec is not in the repo (public repo): read it at the absolute path above.
- Ratings are integers **1–10**, shown as 5 stars with half stars (D-14); `ratings.score` has a DB CHECK 1–10. `media.average_rating` stays on the 1–10 scale (decimal(4,2)); UI divides by 2.
- Favorites are `user_library.favorited_at` (D-6): favoriting implies a library row (create `want_to_read` if absent).
- Interactions: **no IP, no user agent**; `user_interactions` has no `updated_at`; opted-out users (`! $user->personalizationEnabled()`) generate **zero** rows; recorder is the only writer. Event names exactly (spec §44): `search, view, click, library_add, reading_start, reading_progress, reading_complete, favorite, rating, review, abandon, recommendation_click`.
- Every Livewire action re-authorizes (policy or ownership check). A user only ever reads/writes their own library, ratings, progress and history.
- Exports and downloads: signed, expire in 24 h, owner-only, stored on a private disk, never public.
- Deactivated/opted-out users stay out of any aggregate or training export (rating aggregate excludes deactivated users; Phase 6 export excludes both).
- Flux free + Phase 4 components only; no new dependencies. `wire:sort` (Livewire 4) for reordering — confirm with `search-docs`; fall back to `x-sort` if absent.

## Review Focus

1. Opted-out users create no `user_interactions` rows via any path (library, rating, progress, search click, view). → Tasks 5.1, 5.4, 5.5.
2. Cross-user access: another user's library entry, progress, rating, collection, history or export link is never readable or writable (ids guessed in requests). → Tasks 5.1–5.7.
3. Out-of-range / malformed input: percent 101, −1, `NaN`; rating 0/11/5.5; 1001-char note; oversize collection names; duplicate adds; double-clicks. → Tasks 5.1, 5.4, 5.5.
4. Merged/deleted media: a merge must carry users' library, ratings, progress and collection rows over without unique-key crashes; deleting a user cascades everything. → Tasks 5.1, 5.9.
5. Completing a story twice (re-read) or moving backwards in progress doesn't double-count `reading_complete` or break `completed_at`. → Task 5.4.

## File structure

```text
app/Actions/Library/{AddToLibrary,RemoveFromLibrary,SetLibraryStatus,ToggleFavorite,LogProgress}.php
app/Actions/Ratings/RateMedia.php   app/Events/LibraryChanged.php
app/Discovery/Recommendations/InteractionRecorder.php   app/Discovery/ContinueReading.php
app/Jobs/{RecordInteraction,RecalculateMediaRating,ExportUserData}.php
app/Enums/{ReadingStatus,InteractionEvent}.php   app/Policies/{CollectionPolicy}.php
app/Models/{LibraryEntry,ReadingProgress,ReadingSession,Rating,Collection,CollectionItem,UserInteraction,UserPreference}.php
resources/views/components/⚡library-controls.blade.php
resources/views/pages/{library/⚡index,collections/⚡index,collections/⚡show,history/⚡index,onboarding/⚡index,settings/⚡preferences}.blade.php
```

---

### Task 5.1: `user_library`, library actions and `InteractionRecorder` (Review Focus #1, #3)

**Files:**
- Create: migrations `2026_10_26_000001_create_user_library_table.php` (A6), `2026_10_26_000002_create_user_interactions_table.php` (A6: `id bigIncrements`, `user_id`, `media_id` null, `event` string (backed by `InteractionEvent`), `value` real null, `context` jsonb default `{}`, `occurred_at`, **no timestamps**; indexes `(user_id, occurred_at)`, `(media_id, event, occurred_at)`); enums `ReadingStatus`(WantToRead, Reading, Completed, Paused, Abandoned), `InteractionEvent` (12 cases above); models `LibraryEntry` (table `user_library`), `UserInteraction` (`$timestamps = false`) + factories; the five actions; `app/Events/LibraryChanged.php` (`User $user`, `Media $media`); `app/Jobs/RecordInteraction.php`; `app/Discovery/Recommendations/InteractionRecorder.php`; `resources/views/components/⚡library-controls.blade.php`
- Modify: `User` (`library(): HasMany`), `Media`, `resources/views/pages/stories/⚡show.blade.php` (embed `<livewire:library-controls :media="$media" />`; on mount record `view`, and a `click` with `context.search_query_id` when `?sq=` is present and refers to a `search_queries` row of the same session hash), `AppServiceProvider` (`Relation::morphMap([])` placeholder is added in 5.8)
- Test: `tests/Feature/Library/LibraryActionsTest.php`, `tests/Feature/Library/InteractionRecorderTest.php`

**Interfaces:**
- Consumes: `User::personalizationEnabled(): bool` (Phase 1).
- Produces:
  - `InteractionRecorder::record(User $user, InteractionEvent $event, ?Media $media = null, array $context = [], ?float $value = null): void` (A7). Returns immediately when personalization is off; else `RecordInteraction::dispatch($user->id, $event, $media?->id, $context, $value, now())`. Job inserts one `UserInteraction` with `occurred_at` from dispatch time. Context keys allowed: `surface, shelf, position, recommendation_id, search_query_id` (others dropped).
  - `AddToLibrary::handle(User $user, Media $media, ReadingStatus $status = ReadingStatus::WantToRead): LibraryEntry` (idempotent; records `library_add` only on first add; sets `started_at` when status Reading and `completed_at` when Completed); `RemoveFromLibrary::handle(User, Media): void` (also removes favorite — same row); `SetLibraryStatus::handle(User, Media, ReadingStatus): LibraryEntry` (adds if missing; `Abandoned` records `abandon`; `Completed` fires `reading_complete` only via `LogProgress` rules in 5.4 — here status change alone records no completion event); `ToggleFavorite::handle(User, Media): LibraryEntry` (creates a `want_to_read` row if absent; records `favorite` with value 1 when turned on, none when turned off). All dispatch `LibraryChanged`.

- [ ] **Step 1: Write the failing tests**

```php
test('opted out users create no interaction rows through any action', function () {
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);
    $media = Media::factory()->create();
    $this->actingAs($user);

    app(AddToLibrary::class)->handle($user, $media);
    app(ToggleFavorite::class)->handle($user, $media);
    app(SetLibraryStatus::class)->handle($user, $media, ReadingStatus::Abandoned);
    app(InteractionRecorder::class)->record($user, InteractionEvent::View, $media);

    expect(UserInteraction::count())->toBe(0)->and(LibraryEntry::count())->toBe(1);
});

test('an opted in user gets one queued insert per event with context', function () {
    Queue::fake();
    $user = User::factory()->create(); $media = Media::factory()->create();

    app(InteractionRecorder::class)->record($user, InteractionEvent::Click, $media, ['surface' => 'search', 'search_query_id' => 7, 'ip' => '1.2.3.4'], 1.0);

    Queue::assertPushed(RecordInteraction::class, 1);
    Queue::assertPushed(RecordInteraction::class, fn ($j) => $j->context === ['surface' => 'search', 'search_query_id' => 7]);
});

test('the queued job inserts the row with the dispatch time', function () {
    $user = User::factory()->create(); $media = Media::factory()->create();
    $this->travelTo(now()->setTime(10, 0));
    (new RecordInteraction($user->id, InteractionEvent::View, $media->id, [], null, now()))->handle();
    $row = UserInteraction::sole();
    expect($row->event)->toBe(InteractionEvent::View)->and($row->occurred_at->format('H:i'))->toBe('10:00');
});

test('adding twice keeps one row and one library_add', function () {
    $user = User::factory()->create(); $media = Media::factory()->create();
    app(AddToLibrary::class)->handle($user, $media);
    app(AddToLibrary::class)->handle($user, $media);
    expect(LibraryEntry::count())->toBe(1)->and(UserInteraction::where('event', 'library_add')->count())->toBe(1);
});

test('favoriting an unlisted story adds it as want to read', function () {
    $user = User::factory()->create(); $media = Media::factory()->create();
    $entry = app(ToggleFavorite::class)->handle($user, $media);
    expect($entry->status)->toBe(ReadingStatus::WantToRead)->and($entry->favorited_at)->not->toBeNull();
    expect(app(ToggleFavorite::class)->handle($user, $media)->favorited_at)->toBeNull();
});

test('removing deletes the row and its favorite', function () {
    $user = User::factory()->create(); $media = Media::factory()->create();
    app(ToggleFavorite::class)->handle($user, $media);
    app(RemoveFromLibrary::class)->handle($user, $media);
    expect(LibraryEntry::count())->toBe(0);
});

test('status changes stamp started_at and completed_at', function () {
    $user = User::factory()->create(); $media = Media::factory()->create();
    $e = app(SetLibraryStatus::class)->handle($user, $media, ReadingStatus::Reading);
    expect($e->started_at)->not->toBeNull()->and($e->completed_at)->toBeNull();
    expect(app(SetLibraryStatus::class)->handle($user, $media, ReadingStatus::Completed)->completed_at)->not->toBeNull();
});

test('library actions dispatch LibraryChanged', function () {
    Event::fake([LibraryChanged::class]);
    app(AddToLibrary::class)->handle(User::factory()->create(), Media::factory()->create());
    Event::assertDispatched(LibraryChanged::class);
});

test('the controls component only acts for the signed in user and rejects bad status', function () {
    $this->actingAs($user = User::factory()->create()); $media = Media::factory()->create();
    Livewire::test('library-controls', ['media' => $media])->call('setStatus', 'reading')->assertHasNoErrors();
    expect($user->library()->sole()->status)->toBe(ReadingStatus::Reading);
    Livewire::test('library-controls', ['media' => $media])->call('setStatus', 'bogus')->assertHasErrors();
});

test('deleting a user cascades library and interactions', function () {
    $user = User::factory()->create(); $media = Media::factory()->create();
    app(AddToLibrary::class)->handle($user, $media);
    $user->delete();
    expect(LibraryEntry::count())->toBe(0)->and(UserInteraction::count())->toBe(0);
});

test('visiting a story records view and a search click only for an opted in owner of that search', function () {
    $user = User::factory()->create(); $media = Media::factory()->create();
    $sq = SearchQuery::factory()->create(['session_hash' => hash_hmac('sha256', 'sess', config('app.key'))]);
    $this->actingAs($user)->withSession(['_forced_id' => 'sess']); // adapt: set session id so hash matches
    $this->get(route('stories.show', $media->slug).'?sq='.$sq->id);
    expect(UserInteraction::pluck('event')->map->value->all())->toContain('view', 'click')
        ->and(UserInteraction::where('event', 'click')->first()->context['search_query_id'])->toBe($sq->id);
});
```
(Last test: if forcing the session id is awkward, assert instead that a non-existent or other-session `sq` yields `view` only — that is the important security-ish property: no recording of a click with a foreign `search_query_id`.)

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Library` → FAIL (enum `ReadingStatus` not found).
- [ ] **Step 3: Implement** per Interfaces; `uniques(user_id, media_id)`, `index(user_id, status)`. Run `fortify`-independent: no changes to auth.
- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: library actions and interaction recorder"`

---

### Task 5.2: Library page

**C7 patterns (master plan C7):** C7 #7 — add `<x-view-toggle>` (grid/list, `#[Url] view`, alongside the existing `shelves` view) and removable filter chips; test `list view renders compact rows` and `view persists in the url`.

**Files:**
- Create: `resources/views/pages/library/⚡index.blade.php`, `app/Policies`: Gate `view-library` in `AppServiceProvider`
- Modify: `routes/web.php` (`Route::livewire('library', 'pages::library.index')->name('library')`), `config('bookflix.navigation')` (Library item, also in the bottom nav)
- Test: `tests/Feature/Library/LibraryPageTest.php`

**Interfaces:**
- Consumes: `LibraryEntry`, `ClassShelves::forMedia`, `<x-cover-tile>`. Produces: `#[Url]` state `status` (ReadingStatus value|null), `type` (media type slug|null), `favorites` (bool), `sort` (`added|title|last_read|rating`, default `added`), `view` (`grid|shelves`); Gate `view-library`: `fn (?User $viewer, User $owner): bool` → owner, or (`library_visibility === Visibility::Public` and `! $owner->isDeactivated()`) — used by Phase 10 public libraries.

- [ ] **Step 1: Write the failing tests**

```php
test('each filter narrows the library', function (array $set, array $expected) {
    $user = libraryFixture(); // 4 entries: A manga reading fav, B novel completed, C manga want_to_read, D novel abandoned
    $this->actingAs($user);
    $lw = Livewire::test('pages::library.index');
    foreach ($set as $k => $v) { $lw->set($k, $v); }
    expect($lw->viewData('entries')->pluck('media.title')->all())->toEqualCanonicalizing($expected);
})->with([
    'status' => [['status' => 'completed'], ['B']],
    'type' => [['type' => 'manga'], ['A', 'C']],
    'favorites' => [['favorites' => true], ['A']],
    'combined' => [['type' => 'novel', 'status' => 'abandoned'], ['D']],
]);

test('sorting works', function (string $sort, array $order) {
    $this->actingAs(libraryFixture());
    expect(Livewire::test('pages::library.index')->set('sort', $sort)->viewData('entries')->pluck('media.title')->all())->toBe($order);
})->with([
    'title' => ['title', ['A', 'B', 'C', 'D']],
    'rating' => ['rating', ['B', 'A', 'C', 'D']],      // rated 10, 8, none, none → unrated last, ties by title
    'last read' => ['last_read', ['A', 'B', 'C', 'D']], // progress last_read_at desc, nulls last
]);

test('only the owners entries appear', function () {
    $this->actingAs($me = libraryFixture());
    LibraryEntry::factory()->for(User::factory()->create())->create();
    expect(Livewire::test('pages::library.index')->viewData('entries'))->toHaveCount(4);
});

test('shelves view groups by classification', function () {
    $this->actingAs(libraryFixture(classified: true));
    Livewire::test('pages::library.index')->set('view', 'shelves')->assertSee('Literature');
});

test('the empty library shows guidance', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::library.index')->assertSee('Your library is empty');
});

test('invalid filter values are ignored', function () {
    $this->actingAs(libraryFixture());
    Livewire::test('pages::library.index')->set('status', 'bogus')->set('sort', 'drop table')->assertHasNoErrors();
});

test('the view-library gate honours privacy and deactivation', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    expect(Gate::forUser($other)->allows('view-library', $owner))->toBeFalse();
    $owner->profile()->updateOrCreate([], ['library_visibility' => 'public']);
    expect(Gate::forUser($other)->allows('view-library', $owner->refresh()))->toBeTrue();
    $owner->update(['deactivated_at' => now()]);
    expect(Gate::forUser($other)->allows('view-library', $owner->refresh()))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view-library', $owner))->toBeTrue();
});

test('the library runs bounded queries', function () {
    $user = User::factory()->create(); LibraryEntry::factory()->count(50)->for($user)->create();
    $this->actingAs($user); DB::enableQueryLog();
    Livewire::test('pages::library.index');
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(15);
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (eager-load `media.mediaType`, `media.progress`, `media.ratings` for the user; paginate 24; `wire:key`). **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: library page with filters and sorting"`

---

### Task 5.3: Collections

**C7 patterns (master plan C7):** C7 #4 — editorial collections show a theme eyebrow and appear as a themed banner row on Discover/Home; test `editorial collections surface on discover with their theme`.

**Files:**
- Create: migration `2026_10_26_000003_create_collections_tables.php` (A6 `collections`, `collection_items` with `unique(collection_id, media_id)`, index `(collection_id, position)`), models `Collection`, `CollectionItem` + factories, `app/Policies/CollectionPolicy.php`, pages `collections/⚡index.blade.php` (list + create), `collections/⚡show.blade.php` (view/edit, add/remove items, reorder, visibility, editorial toggle for admins)
- Modify: `routes/web.php` (`collections.index`, `collections.show` → `collections/{slug}`), `config('bookflix.navigation')` (Collections, desktop only per D-13; reachable under Library on mobile), `<x-library-controls>` ("Add to collection" dropdown)
- Test: `tests/Feature/Library/CollectionsTest.php`

**Interfaces:**
- Produces: `Collection` slug unique per owner (`Str::slug(name)` + counter); `CollectionPolicy`: `view` (owner, or `is_editorial`, or `visibility=public` and owner active), `update`/`delete`/`addItem` (owner only). `CollectionItem.position` contiguous from 0. Editorial flag: only `Gate::allows('access-admin')`. Class named `App\Models\Collection` (alias when importing next to Eloquent's `Collection`).

- [ ] **Step 1: Write the failing tests**

```php
test('users create collections with a unique slug per owner', function () {
    $this->actingAs($a = User::factory()->create());
    Livewire::test('pages::collections.index')->set('name', 'Favorites')->call('create');
    Livewire::test('pages::collections.index')->set('name', 'Favorites')->call('create');
    expect($a->collections()->pluck('slug')->all())->toBe(['favorites', 'favorites-2']);
});
test('names are required and limited to 100 characters', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::collections.index')->set('name', '')->call('create')->assertHasErrors(['name' => 'required'])
        ->set('name', str_repeat('a', 101))->call('create')->assertHasErrors(['name' => 'max']);
});
test('only the owner can edit or view a private collection', function () {
    $c = Collection::factory()->create(['visibility' => 'private']);
    $this->actingAs(User::factory()->create());
    $this->get(route('collections.show', $c->slug))->assertNotFound();
    expect(Gate::allows('update', $c))->toBeFalse();
});
test('items can be added once and removed', function () {
    $this->actingAs($u = User::factory()->create()); $c = Collection::factory()->for($u)->create(); $m = Media::factory()->create();
    $lw = Livewire::test('pages::collections.show', ['slug' => $c->slug])->call('addItem', $m->id)->call('addItem', $m->id);
    expect($c->items()->count())->toBe(1);
    $lw->call('removeItem', $c->items()->first()->id);
    expect($c->items()->count())->toBe(0);
});
test('reordering rewrites contiguous positions', function () {
    $this->actingAs($u = User::factory()->create()); $c = Collection::factory()->for($u)->create();
    [$a, $b, $d] = collect(range(1, 3))->map(fn ($i) => CollectionItem::factory()->for($c)->create(['position' => $i - 1]))->all();
    Livewire::test('pages::collections.show', ['slug' => $c->slug])->call('reorder', $d->id, 0);
    expect($c->items()->orderBy('position')->pluck('id')->all())->toBe([$d->id, $a->id, $b->id])
        ->and($c->items()->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2]);
});
test('another users item ids cannot be reordered or removed', function () {
    $this->actingAs(User::factory()->create()); $mine = Collection::factory()->for(auth()->user())->create();
    $foreign = CollectionItem::factory()->create();
    Livewire::test('pages::collections.show', ['slug' => $mine->slug])->call('removeItem', $foreign->id);
    expect(CollectionItem::count())->toBe(1);
});
test('only admins can mark a collection editorial', function () {
    $c = Collection::factory()->create();
    Livewire::actingAs($c->user)->test('pages::collections.show', ['slug' => $c->slug])->set('isEditorial', true)->call('save');
    expect($c->fresh()->is_editorial)->toBeFalse();
    $admin = User::factory()->admin()->create(); $e = Collection::factory()->for($admin)->create();
    Livewire::actingAs($admin)->test('pages::collections.show', ['slug' => $e->slug])->set('isEditorial', true)->call('save');
    expect($e->fresh()->is_editorial)->toBeTrue();
});
test('editorial collections are viewable by everyone, deleting a collection removes its items', function () {
    $c = Collection::factory()->create(['is_editorial' => true, 'visibility' => 'private']);
    $this->actingAs(User::factory()->create())->get(route('collections.show', $c->slug))->assertOk();
    CollectionItem::factory()->for($c)->create(); $c->delete();
    expect(CollectionItem::count())->toBe(0);
});
```
Editorial/foreign collections resolve by slug: the page looks up the viewer's own collection first, then `is_editorial` ones; ambiguous slugs prefer the viewer's.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: collections with ordering, visibility and editorial flag"`

---

### Task 5.4: Reading progress, history sessions and Continue Reading (Review Focus #1, #3, #5)

**Files:**
- Create: migration `2026_10_26_000004_create_reading_tables.php` (A6 `reading_progress` PK `(user_id, media_id)`, `reading_history`), models `ReadingProgress`, `ReadingSession` (table `reading_history`), `app/Actions/Library/LogProgress.php`, `app/Discovery/ContinueReading.php`
- Modify: `⚡library-controls.blade.php` (progress form: percent, optional page/chapter, optional minutes), `resources/views/pages/home/⚡index.blade.php` (Continue Reading shelf first, when non-empty)
- Test: `tests/Feature/Library/ReadingProgressTest.php`

**Interfaces:**
- Produces: `LogProgress::handle(User $user, Media $media, float $percent, ?int $page = null, ?string $chapter = null, ?array $locator = null, int $seconds = 0): ReadingProgress` — validates `0 <= percent <= 100` and finite (throws `ValidationException` key `percent`), upserts `reading_progress` (`last_read_at = now`), appends a `reading_history` row (`started_at = now - seconds`, `ended_at = now`, `seconds`, `from_percent` = previous percent or 0, `to_percent`), ensures a library row. Rules: first log on a non-reading entry → status `reading`, `started_at` set if null, record `reading_start`; each log records `reading_progress` with `value = max(0, to - from)`; reaching `100` when the entry is not already `completed` → status `completed`, `completed_at = now`, record `reading_complete` **once** (a re-read from <100 back to 100 after completion is not recorded again; moving backwards never lowers `completed_at`, and does not change `completed` status). `ContinueReading::shelf(User, int $limit = 20): ?Shelf` — key `continue-reading`, title "Continue Reading", entries with status `reading` ordered by `reading_progress.last_read_at desc`; `null` when empty.

- [ ] **Step 1: Write the failing tests**

```php
test('percent must be between 0 and 100', function (float $percent) {
    $user = User::factory()->create(); $media = Media::factory()->create();
    expect(fn () => app(LogProgress::class)->handle($user, $media, $percent))->toThrow(ValidationException::class);
    expect(ReadingProgress::count())->toBe(0);
})->with([-1, 100.01, 101, NAN, INF]);

test('first log starts reading and records reading_start', function () {
    $user = User::factory()->create(); $media = Media::factory()->create();
    app(LogProgress::class)->handle($user, $media, 12.5, chapter: '3');
    $entry = $user->library()->sole();
    expect($entry->status)->toBe(ReadingStatus::Reading)->and($entry->started_at)->not->toBeNull()
        ->and(UserInteraction::where('event', 'reading_start')->count())->toBe(1)
        ->and(ReadingProgress::sole()->chapter)->toBe('3');
});

test('progress events carry the delta as value', function () {
    $user = User::factory()->create(); $media = Media::factory()->create();
    app(LogProgress::class)->handle($user, $media, 10); app(LogProgress::class)->handle($user, $media, 40);
    expect(UserInteraction::where('event', 'reading_progress')->orderBy('id')->pluck('value')->map(fn ($v) => (float) $v)->all())->toBe([10.0, 30.0]);
});

test('reaching 100 completes the story once', function () {
    $user = User::factory()->create(); $media = Media::factory()->create();
    app(LogProgress::class)->handle($user, $media, 99); app(LogProgress::class)->handle($user, $media, 100);
    $entry = $user->library()->sole();
    expect($entry->status)->toBe(ReadingStatus::Completed)->and($entry->completed_at)->not->toBeNull()
        ->and(UserInteraction::where('event', 'reading_complete')->count())->toBe(1);
    // re-read: back to 5 then 100 again
    $first = $entry->completed_at;
    app(LogProgress::class)->handle($user, $media, 5); app(LogProgress::class)->handle($user, $media, 100);
    expect(UserInteraction::where('event', 'reading_complete')->count())->toBe(1)->and($user->library()->sole()->completed_at->equalTo($first))->toBeTrue();
});

test('every log appends a history session with from and to percent and seconds', function () {
    $user = User::factory()->create(); $media = Media::factory()->create();
    app(LogProgress::class)->handle($user, $media, 20, seconds: 600);
    app(LogProgress::class)->handle($user, $media, 35, seconds: 300);
    $rows = ReadingSession::orderBy('id')->get();
    expect($rows->pluck('seconds')->all())->toBe([600, 300])->and((float) $rows[1]->from_percent)->toBe(20.0)->and((float) $rows[1]->to_percent)->toBe(35.0);
});

test('opted out users still keep progress and history but no interactions', function () {
    $user = User::factory()->create(); $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);
    app(LogProgress::class)->handle($user, Media::factory()->create(), 50);
    expect(ReadingProgress::count())->toBe(1)->and(ReadingSession::count())->toBe(1)->and(UserInteraction::count())->toBe(0);
});

test('continue reading lists reading entries by last read time', function () {
    $user = User::factory()->create(); [$a, $b, $done] = Media::factory()->count(3)->create();
    app(LogProgress::class)->handle($user, $a, 10); $this->travel(1)->hour(); app(LogProgress::class)->handle($user, $b, 10); app(LogProgress::class)->handle($user, $done, 100);
    expect(app(ContinueReading::class)->shelf($user)->items->pluck('id')->all())->toBe([$b->id, $a->id]);
});

test('continue reading is null for a user with nothing in progress and the home page omits it', function () {
    $user = User::factory()->create();
    expect(app(ContinueReading::class)->shelf($user))->toBeNull();
    $this->actingAs($user)->get(route('home'))->assertOk()->assertDontSee('Continue Reading');
});

test('users cannot log progress for another user through the component', function () {
    $this->actingAs($u = User::factory()->create()); $media = Media::factory()->create();
    Livewire::test('library-controls', ['media' => $media])->set('percent', 30)->call('logProgress');
    expect(ReadingProgress::sole()->user_id)->toBe($u->id);
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: manual reading progress, history sessions and continue reading"`

---

### Task 5.5: Ratings and the aggregate job (Review Focus #1, #3)

**Files:**
- Create: migration `2026_10_26_000005_create_ratings_table.php` (PK `(user_id, media_id)`, `score smallint`, raw `check (score between 1 and 10)`), `app/Models/Rating.php`, `app/Actions/Ratings/RateMedia.php`, `app/Jobs/RecalculateMediaRating.php`
- Modify: `⚡library-controls.blade.php` (`<x-rating-input>` bound to `rating`), `stories/⚡show.blade.php` (average display `average_rating / 2` as stars + count)
- Test: `tests/Feature/Library/RatingsTest.php`

**Interfaces:**
- Produces: `RateMedia::handle(User $user, Media $media, int $score): Rating` — validates int 1..10 (`ValidationException` key `score`), upsert, ensures library row (status unchanged if present, else `want_to_read`? **No** — rating does not create a library row), records `rating` with `value = score`, dispatches `RecalculateMediaRating::dispatch($media->id)->afterCommit()`, fires `LibraryChanged`. `RateMedia::forget(User, Media): void` removes a rating and recalculates. `RecalculateMediaRating(int $mediaId)`: `average_rating = round(avg(score), 2)` and `ratings_count` over ratings of **active** users (`User::active()`), null/0 when none.

- [ ] **Step 1: Write the failing tests**

```php
test('a user has one rating per story and re-rating updates it', function () {
    $user = User::factory()->create(); $media = Media::factory()->create();
    app(RateMedia::class)->handle($user, $media, 7); app(RateMedia::class)->handle($user, $media, 9);
    expect(Rating::count())->toBe(1)->and(Rating::sole()->score)->toBe(9);
});
test('scores outside 1 to 10 are rejected', function (int $score) {
    expect(fn () => app(RateMedia::class)->handle(User::factory()->create(), Media::factory()->create(), $score))->toThrow(ValidationException::class);
    expect(Rating::count())->toBe(0);
})->with([0, 11, -3]);
test('the component rejects a half point score', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('library-controls', ['media' => Media::factory()->create()])->set('rating', 5.5)->call('saveRating')->assertHasErrors(['rating']);
});
test('the database rejects a score of 0', function () {
    expect(fn () => DB::table('ratings')->insert(['user_id' => User::factory()->create()->id, 'media_id' => Media::factory()->create()->id, 'score' => 0, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});
test('the aggregate is recomputed from active users only', function () {
    $media = Media::factory()->create();
    foreach ([[8, false], [6, false], [10, true]] as [$score, $deactivated]) {
        $u = User::factory()->create(['deactivated_at' => $deactivated ? now() : null]);
        Rating::factory()->create(['user_id' => $u->id, 'media_id' => $media->id, 'score' => $score]);
    }
    (new RecalculateMediaRating($media->id))->handle();
    expect((float) $media->fresh()->average_rating)->toBe(7.0)->and($media->fresh()->ratings_count)->toBe(2);
});
test('removing the last rating resets the aggregate', function () {
    $user = User::factory()->create(); $media = Media::factory()->create();
    app(RateMedia::class)->handle($user, $media, 8); app(RateMedia::class)->forget($user, $media);
    expect($media->fresh()->average_rating)->toBeNull()->and($media->fresh()->ratings_count)->toBe(0);
});
test('rating dispatches the aggregate job and records an interaction value', function () {
    Queue::fake([RecalculateMediaRating::class]);
    $user = User::factory()->create(); $media = Media::factory()->create();
    app(RateMedia::class)->handle($user, $media, 8);
    Queue::assertPushed(RecalculateMediaRating::class);
    expect((float) UserInteraction::where('event', 'rating')->sole()->value)->toBe(8.0);
});
test('opted out users can rate without an interaction row', function () {
    $user = User::factory()->create(); $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);
    app(RateMedia::class)->handle($user, Media::factory()->create(), 8);
    expect(Rating::count())->toBe(1)->and(UserInteraction::count())->toBe(0);
});
test('the component shows only the signed in users rating', function () {
    $media = Media::factory()->create(); Rating::factory()->create(['media_id' => $media->id, 'score' => 3]);
    $this->actingAs(User::factory()->create());
    expect(Livewire::test('library-controls', ['media' => $media])->get('rating'))->toBeNull();
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: ratings with aggregate recalculation"`

---

### Task 5.6: History page

**C7 patterns (master plan C7):** C7 #11 — History header uses `<x-stat-row>` (total reading time, sessions this month, stories completed); test `stat values come from the user's own sessions only`.

**Files:**
- Create: `resources/views/pages/history/⚡index.blade.php`; Modify: `routes/web.php` (`history`), `config('bookflix.navigation')` (History, desktop only; reachable under Library on mobile)
- Test: `tests/Feature/Library/HistoryPageTest.php`

**Interfaces:** consumes `ReadingSession`. Produces: paginated sessions newest first, grouped by day, each with title, percent range, formatted duration; header shows total time (`CarbonInterval::seconds($total)->cascade()->forHumans(short: true)`).

- [ ] **Step 1: Write the failing tests**

```php
test('sessions are listed newest first with total reading time', function () {
    $user = User::factory()->create(); $a = Media::factory()->create(['title' => 'Alpha']); $b = Media::factory()->create(['title' => 'Beta']);
    ReadingSession::factory()->create(['user_id' => $user->id, 'media_id' => $a->id, 'seconds' => 3600, 'ended_at' => now()->subDay()]);
    ReadingSession::factory()->create(['user_id' => $user->id, 'media_id' => $b->id, 'seconds' => 1800, 'ended_at' => now()]);
    $this->actingAs($user)->get(route('history'))->assertSeeInOrder(['Beta', 'Alpha'])->assertSee('1h 30m');
});
test('only the owner sessions appear', function () {
    ReadingSession::factory()->create(['seconds' => 60]);
    $this->actingAs(User::factory()->create())->get(route('history'))->assertDontSee('1m');
});
test('an empty history shows guidance and long histories paginate', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('history'))->assertSee('No reading history yet');
    ReadingSession::factory()->count(60)->create(['user_id' => $user->id]);
    expect(Livewire::actingAs($user)->test('pages::history.index')->viewData('sessions'))->toHaveCount(25);
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: reading history page"`

---

### Task 5.7: Data export

**Files:**
- Create: `app/Jobs/ExportUserData.php`, `app/Http/Controllers/ExportDownloadController.php` (route `exports.download`: `GET exports/{user}/{file}` middleware `auth`, `signed`), `app/Notifications/DataExportReady.php` (mail with the signed link), `app/Console/Commands/PruneExports.php` (`bookflix:prune-exports`, deletes files > 7 days, scheduled daily)
- Modify: `resources/views/pages/settings/⚡privacy.blade.php` ("Export my data" button → dispatches the job; status text), `routes/console.php`
- Test: `tests/Feature/Library/DataExportTest.php`

**Interfaces:**
- Produces: `ExportUserData(int $userId)`: writes `exports/{userId}/{ulid}.zip` on the **local private disk** containing `profile.json, preferences.json, library.json, collections.json, ratings.json, history.json, interactions.json`; notifies via `DataExportReady` with `URL::temporarySignedRoute('exports.download', now()->addHours(24), ['user' => $userId, 'file' => $ulid])`. Download streams the zip only when `auth()->id() === (int) $user`; else 403.

- [ ] **Step 1: Write the failing tests**

```php
test('the export zip contains every data set for the user only', function () {
    Storage::fake('local'); Notification::fake();
    $user = exportFixture(); // profile, preference, library entry, collection+item, rating, history, interaction
    $other = exportFixture();
    (new ExportUserData($user->id))->handle();
    $path = Storage::disk('local')->allFiles("exports/{$user->id}")[0];
    $zip = new ZipArchive; $zip->open(Storage::disk('local')->path($path));
    $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->all();
    expect($names)->toEqualCanonicalizing(['profile.json', 'preferences.json', 'library.json', 'collections.json', 'ratings.json', 'history.json', 'interactions.json'])
        ->and(json_decode($zip->getFromName('library.json'), true))->toHaveCount(1)
        ->and($zip->getFromName('interactions.json'))->not->toContain((string) $other->email);
    Notification::assertSentTo($user, DataExportReady::class);
});
test('the signed link downloads for the owner', function () {
    Storage::fake('local'); Notification::fake(); $user = User::factory()->create();
    (new ExportUserData($user->id))->handle();
    $file = pathinfo(Storage::disk('local')->allFiles("exports/{$user->id}")[0], PATHINFO_FILENAME);
    $url = URL::temporarySignedRoute('exports.download', now()->addHours(24), ['user' => $user->id, 'file' => $file]);
    $this->actingAs($user)->get($url)->assertOk()->assertDownload();
});
test('another user an unsigned link and an expired link are all forbidden', function () {
    Storage::fake('local'); $user = User::factory()->create(); (new ExportUserData($user->id))->handle(); Notification::fake();
    $file = pathinfo(Storage::disk('local')->allFiles("exports/{$user->id}")[0], PATHINFO_FILENAME);
    $url = URL::temporarySignedRoute('exports.download', now()->addHours(24), ['user' => $user->id, 'file' => $file]);
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    $this->actingAs($user)->get(route('exports.download', ['user' => $user->id, 'file' => $file]))->assertForbidden();
    $this->travel(25)->hours();
    $this->actingAs($user)->get($url)->assertForbidden();
});
test('path traversal in the file parameter is rejected', function () {
    $user = User::factory()->create();
    $url = URL::temporarySignedRoute('exports.download', now()->addHour(), ['user' => $user->id, 'file' => '../../.env']);
    $this->actingAs($user)->get($url)->assertNotFound();
});
test('the privacy page button queues the export and old exports are pruned', function () {
    Queue::fake(); $this->actingAs($user = User::factory()->create());
    Livewire::test('pages::settings.privacy')->call('exportData');
    Queue::assertPushed(ExportUserData::class, fn ($j) => $j->userId === $user->id);
    Storage::fake('local'); Storage::disk('local')->put('exports/1/old.zip', 'x'); touch(Storage::disk('local')->path('exports/1/old.zip'), now()->subDays(8)->timestamp);
    $this->artisan('bookflix:prune-exports')->assertSuccessful();
    Storage::disk('local')->assertMissing('exports/1/old.zip');
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (`file` constrained to `[0-9A-Z]{26}`; zip built with `ZipArchive` into a temp file then moved). **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: signed user data export"`. Run `security-review` on this task before merging the phase.

---

### Task 5.8: Onboarding and preferences (§33, §41)

**C7 patterns (master plan C7):** C7 #10 — `<x-stepper>` labels are numbered `01 Types`, `02 Genres`, `03 Stories` with the eyebrow + title recipe; test `the current step is announced with aria-current and its number`.

**Files:**
- Create: migration `2026_10_26_000006_create_user_preferences_table.php` (A6: `user_id`, `morphs(preferable)`, `weight smallint default 1`, `unique(user_id, preferable_type, preferable_id)`), `app/Models/UserPreference.php`, `resources/views/pages/onboarding/⚡index.blade.php` (3 steps with `<x-stepper>`), `resources/views/pages/settings/⚡preferences.blade.php`, `app/Http/Middleware/EnsureOnboarded.php`
- Modify: `AppServiceProvider` (`Relation::morphMap(['genre' => Genre::class, 'theme' => Theme::class, 'media_type' => MediaType::class, 'creator' => Creator::class])`), `routes/web.php` (`onboarding` outside the onboarded middleware; `EnsureOnboarded` on the `home` group), `routes/settings.php` (`settings/preferences` → `preferences.edit`), settings nav item
- Test: `tests/Feature/Library/OnboardingTest.php`

**Interfaces:**
- Produces: `User::preferences(): HasMany`; `EnsureOnboarded` redirects authenticated users with `profile->onboarded_at === null` from `home` to `route('onboarding')` (never loops: onboarding, settings, logout and admin are exempt). Onboarding page: step 1 media types (≥ 0), step 2 genres (≥ 0), step 3 stories — popular stories filtered by chosen types/genres, pick **≥ 3** to finish (Cold start §33); finishing writes `user_preferences` (weight 1) for chosen types/genres, adds each chosen story to the library as `completed` via `AddToLibrary`/`SetLibraryStatus` with interaction context `{surface: 'onboarding'}`, sets `onboarded_at`, redirects home. **Skip** sets `onboarded_at` and writes nothing.

- [ ] **Step 1: Write the failing tests**

```php
test('a new user is redirected from home to onboarding until onboarded', function () {
    $this->actingAs(User::factory()->create())->get(route('home'))->assertRedirect(route('onboarding'));
});
test('an onboarded user reaches home and onboarding does not loop', function () {
    $user = User::factory()->create(); $user->profile()->updateOrCreate([], ['onboarded_at' => now()]);
    $this->actingAs($user)->get(route('home'))->assertOk();
});
test('finishing with fewer than 3 stories is a validation error', function () {
    $this->actingAs($user = User::factory()->create()); $stories = Media::factory()->count(5)->create();
    Livewire::test('pages::onboarding.index')->set('stories', $stories->take(2)->pluck('id')->all())->call('finish')->assertHasErrors(['stories' => 'min']);
    expect($user->fresh()->profile->onboarded_at)->toBeNull();
});
test('finishing saves preferences, library entries and onboarded_at', function () {
    $this->actingAs($user = User::factory()->create()); $stories = Media::factory()->count(3)->create();
    $type = MediaType::factory()->create(['slug' => 'manga']); $genre = Genre::factory()->create();
    Livewire::test('pages::onboarding.index')->set('mediaTypes', [$type->id])->set('genres', [$genre->id])->set('stories', $stories->pluck('id')->all())->call('finish')->assertRedirect(route('home'));
    expect($user->preferences()->count())->toBe(2)->and($user->library()->count())->toBe(3)
        ->and($user->fresh()->profile->onboarded_at)->not->toBeNull()
        ->and(UserInteraction::where('event', 'library_add')->first()->context['surface'])->toBe('onboarding');
});
test('skipping is allowed and saves nothing', function () {
    $this->actingAs($user = User::factory()->create());
    Livewire::test('pages::onboarding.index')->call('skip')->assertRedirect(route('home'));
    expect($user->preferences()->count())->toBe(0)->and($user->fresh()->profile->onboarded_at)->not->toBeNull();
});
test('invalid ids are rejected and preferences are unique', function () {
    $this->actingAs($user = User::factory()->create());
    Livewire::test('pages::onboarding.index')->set('genres', [999999])->set('stories', [1, 2, 3])->call('finish')->assertHasErrors();
    UserPreference::factory()->create(['user_id' => $user->id, 'preferable_type' => 'genre', 'preferable_id' => ($g = Genre::factory()->create())->id]);
    expect(fn () => UserPreference::factory()->create(['user_id' => $user->id, 'preferable_type' => 'genre', 'preferable_id' => $g->id]))->toThrow(QueryException::class);
});
test('preferences can be edited later from settings and the morph map resolves', function () {
    $this->actingAs($user = User::factory()->create()); $g = Genre::factory()->create();
    Livewire::test('pages::settings.preferences')->set('genres', [$g->id])->call('save');
    expect($user->preferences()->first()->preferable)->toBeInstanceOf(Genre::class)->and(DB::table('user_preferences')->value('preferable_type'))->toBe('genre');
});
```
(Decided: new users must complete onboarding, so `EnsureOnboarded` stays. `UserFactory` creates users with a profile whose `onboarded_at` is set by default, so existing Phase 1 tests that expect `home` → 200 keep passing without edits; add a `->notOnboarded()` state used by the onboarding tests above. Verify the factory's current shape in the Phase 1 code first.)
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** `php artisan test --compact` (the whole suite, since the middleware touches earlier tests) → PASS. **Step 5: Commit** — `git commit -m "feat: onboarding flow and user preferences"`

---

### Task 5.9: Merge safety for user data

**Files:**
- Modify: `app/Catalog/Ingestion/Deduplicator.php` (`merge`: also repoint user data), `tests/Feature/Catalog/DeduplicatorTest.php` (add cases)
- Test: `tests/Feature/Library/MergeUserDataTest.php`

**Interfaces:** consumes `LibraryEntry`, `Rating`, `ReadingProgress`, `ReadingSession`, `CollectionItem`, `UserInteraction`. `Deduplicator::merge(Media $winner, Media $loser): void` additionally: for `user_library`, `ratings`, `reading_progress`, `collection_items` — if the user already has a row for the winner, keep the **winner's** row and drop the loser's; else repoint to the winner; `reading_history` and `user_interactions` always repoint; then dispatch `RecalculateMediaRating($winner->id)`.

- [ ] **Step 1: Write the failing tests**

```php
test('merging repoints user rows without unique key crashes', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    $solo = User::factory()->create(); $both = User::factory()->create();
    LibraryEntry::factory()->create(['user_id' => $solo->id, 'media_id' => $loser->id]);
    LibraryEntry::factory()->create(['user_id' => $both->id, 'media_id' => $loser->id, 'status' => 'reading']);
    LibraryEntry::factory()->create(['user_id' => $both->id, 'media_id' => $winner->id, 'status' => 'completed']);
    Rating::factory()->create(['user_id' => $solo->id, 'media_id' => $loser->id, 'score' => 6]);
    UserInteraction::factory()->create(['user_id' => $solo->id, 'media_id' => $loser->id]);

    app(Deduplicator::class)->merge($winner, $loser);

    expect(LibraryEntry::where('media_id', $winner->id)->count())->toBe(2)
        ->and(LibraryEntry::where('media_id', $loser->id)->count())->toBe(0)
        ->and(LibraryEntry::where(['user_id' => $both->id, 'media_id' => $winner->id])->sole()->status)->toBe(ReadingStatus::Completed)
        ->and(Rating::where('media_id', $winner->id)->count())->toBe(1)
        ->and(UserInteraction::where('media_id', $winner->id)->count())->toBe(1);
});
test('collection items for both media in one collection collapse to one', function () {
    [$winner, $loser] = Media::factory()->count(2)->create(); $c = Collection::factory()->create();
    CollectionItem::factory()->for($c)->create(['media_id' => $winner->id, 'position' => 0]);
    CollectionItem::factory()->for($c)->create(['media_id' => $loser->id, 'position' => 1]);
    app(Deduplicator::class)->merge($winner, $loser);
    expect($c->items()->count())->toBe(1);
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** inside the existing merge transaction. **Step 4: Run** `php artisan test --compact tests/Feature/Catalog tests/Feature/Library` → PASS. **Step 5: Commit** — `git commit -m "fix: carry user data across media merges"`

---

## Phase 5 exit criteria
- [ ] `composer test` green (including the earlier phases); browser smoke added for: onboarding → add to library → log progress → rate (Pest browser, `assertNoAccessibilityIssues()`).
- [ ] `security-review` on export + ownership checks; `design:accessibility-review` on the rating input and stepper.
- [ ] The MVP library flow works end to end; export downloads.
- [ ] Ask the user to run the full `php artisan test --compact`.

## Self-review notes
- Spec coverage: §36 (5.1–5.3), §38 (5.4, 5.6), §39 ratings (5.5), §41 (5.8), §44 (5.1), §60 export/minimization (5.7, 5.1), §33 onboarding (5.8).
- Numbering follows the master plan references (5.1 recorder, 5.4/5.6 history, 5.5 ratings, 5.7 export, 5.8 onboarding); 5.9 is added.
- Additions recorded in master plan decisions D-19–D-28: `LibraryChanged` event (Phase 6 cache hook), `Gate view-library`, morph map, `EnsureOnboarded` middleware (touches Phase 1 tests), `DataExportReady` notification.
