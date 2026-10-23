# Phase 2 — Catalog and Ingestion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A catalog of stories from Open Library and AniList, ingested idempotently, deduplicated, rate-limited and observable, with an admin to inspect runs, edit metadata (with field locking), review duplicates and merge them.

**Architecture:** `app/Catalog` holds provider adapters behind `MetadataProvider`, DTOs, and the ingestion pipeline (master plan A9). `SyncProvider` pages a provider into per-record `ImportMediaRecord` jobs; the pipeline validates → normalizes → maps taxonomy → dedups → enriches → stores in one transaction, upserting on `(external_source_id, external_id)`. Queues move to Redis + Horizon (D-9).

**Tech Stack:** Laravel 13, Pest on Postgres, `laravel/horizon`, Livewire 4 + Flux free, `Http::fake` with recorded fixtures.

**Spec:** `/Users/gabe/Documents/Bookflix Documentation/Bookflix_Specification.md` (in-repo copy: `docs/superpowers/specs/bookflix-specification.md`) §5, §9, §10, §42, §43. **Master plan:** `docs/superpowers/plans/2026-10-22-bookflix-master-plan.md` (A6 catalog block, A7, A9, Part E Phase 2).

**Depends on Phase 1:** `User::factory()->admin()`, Gate `access-admin`, `routes/admin.php` (prefix `admin`, name `admin.`), `config/bookflix.php`, `Http::preventStrayRequests()` in Feature tests, `tests/Fixtures/`.

## Global Constraints

All master-plan Global Constraints apply (Flux free only, open-source additions only, single-file Livewire pages `resources/views/pages/<area>/⚡<name>.blade.php` + `Route::livewire(...)`, tests on Postgres, `php artisan make:* --no-interaction`, Pint after PHP edits, `search-docs` before using Horizon/rate-limiting/queue APIs).
- Skip provider records with `isAdult = true` (Q15). Metadata only; never chapter images (MangaDex, 2b).
- `media.locked_fields` fields are never overwritten by sync. Field precedence: admin-locked > existing > provider priority (A9).
- Dedup bands (A9): exact provider ID / ISBN-13 / cross-ID → auto-merge; trigram ≥ 0.90 **and** shared creator **and** year ±1 → auto-merge; 0.75–0.90 → `duplicate_candidates` row.
- Provider User-Agent from `BOOKFLIX_PROVIDER_USER_AGENT`; each provider's rate limits, terms, attribution, `cover_policy`, `display_policy` recorded in `external_sources` before the adapter is enabled (A9).
- Fixtures under `tests/Fixtures/providers/<provider>/*.json` are recorded once from real APIs, no personal data.

## Review Focus

1. Same work from two providers → one `Media`, two `media_external_ids`; "Monster" manga vs novel never merge. → Task 2.5.
2. Provider failure mid-sync (429, 5xx, timeout, malformed payload): run `partial`/`failed`, existing data untouched, retries create no duplicates. → Task 2.7.
3. Admin-edited (locked) fields survive the next sync. → Task 2.6.
4. A merge is reversible and the loser's URL still resolves (301). → Task 2.8.
5. Records with missing/blank title, unknown type or no external id are skipped and counted, never crash the run. → Task 2.6.

## File structure

```text
app/Catalog/Data/{ProviderRecord,NormalizedMedia,NormalizedCreator,ImportOutcome}.php
app/Catalog/Providers/{MetadataProvider.php, OpenLibrary/OpenLibraryProvider.php, AniList/AniListProvider.php}
app/Catalog/Ingestion/{IngestionPipeline,RecordValidator,TaxonomyMapper,Deduplicator,Enricher}.php
app/Jobs/{SyncProvider,ImportMediaRecord}.php
app/Models/{MediaType,Media,Creator,Publisher,Series,Genre,Theme,Tag,Character,ExternalSource,MediaExternalId,IngestionRun,DuplicateCandidate}.php
app/Enums/{MediaFamily,ReadingMode,AgeRating,PublicationStatus,CreatorRole,ImportOutcome,CoverPolicy,IngestionMode,IngestionStatus,DuplicateStatus}.php
database/data/taxonomy/{openlibrary,anilist}.csv
resources/views/pages/admin/{providers,media,duplicates}/⚡*.blade.php
```

---

### Task 2.1: Catalog schema, models, factories, seeded media types

**Files:**
- Create: migrations `2026_10_23_100001_create_catalog_tables.php` (A6 catalog block **except** `content_sources`), `2026_10_23_100002_create_content_sources_table.php` (designed now, unused until Phase 9). Add to `media` one column not in A6: `merged_into_id` (nullable FK → `media.id`, `nullOnDelete`) for reversible merges (see Task 2.8).
- Create: models/enums/factories per the structure above; `Media::factory()` states `manga()`, `novel()`; `database/seeders/MediaTypeSeeder.php` (10 types, §2 families/reading modes); call it from `DatabaseSeeder`.
- Modify: `AppServiceProvider::boot` — `Relation::enforceMorphMap` is **not** set here (Phase 5 sets the `Preferable` map).
- Test: `tests/Feature/Catalog/CatalogSchemaTest.php`

**Interfaces:**
- Produces (used by every later phase): models and relations — `Media::mediaType()`, `creators()` (pivot `role`, `position`), `publishers()`, `series()` (pivot `position`, `label`), `genres()`, `themes()`, `tags()`, `characters()`, `externalIds()` (`MediaExternalId`), `relations()`; `MediaType::media()`; `Series::media()`. Enums `MediaFamily`(Prose, SequentialArt, Illustrated, Interactive), `ReadingMode`(Reflowable, Paged, VerticalScroll, Interactive), `AgeRating`(All, Teen, Mature, Adult, Unknown), `PublicationStatus`(Upcoming, Ongoing, Completed, Hiatus, Cancelled, Unknown), `CreatorRole`(Author, Artist, Illustrator, Writer, Penciller, Inker, Colorist, Translator, Developer). Slugs: `Media` slug generated with `Str::slug(title)` + `-{year}` fallback `-2`, `-3` on collision (`Media::uniqueSlug(string $title, ?int $year): string`).
- `Media` casts: `alternative_titles`, `content_warnings`, `locked_fields` → array; `published_on` → date; `age_rating`/`status` → enums; `SoftDeletes`.

- [ ] **Step 1: Write the failing test**

```php
test('media types are seeded with family and reading mode', function () {
    $this->seed(MediaTypeSeeder::class);

    expect(MediaType::count())->toBe(10)
        ->and(MediaType::firstWhere('slug', 'manga')->family)->toBe(MediaFamily::SequentialArt)
        ->and(MediaType::firstWhere('slug', 'manga')->reading_mode)->toBe(ReadingMode::Paged)
        ->and(MediaType::firstWhere('slug', 'webcomic')->reading_mode)->toBe(ReadingMode::VerticalScroll)
        ->and(MediaType::firstWhere('slug', 'visual-novel')->family)->toBe(MediaFamily::Interactive)
        ->and(MediaType::firstWhere('slug', 'novel')->reading_mode)->toBe(ReadingMode::Reflowable);
});

test('media relations and casts work', function () {
    $media = Media::factory()->manga()->hasAttached(Creator::factory(), ['role' => 'author', 'position' => 0])->create(['alternative_titles' => ['進撃の巨人']]);

    expect($media->alternative_titles)->toBe(['進撃の巨人'])
        ->and($media->creators->first()->pivot->role)->toBe(CreatorRole::Author)
        ->and($media->mediaType->slug)->toBe('manga');
});

test('media slugs are unique', function () {
    $a = Media::factory()->create(['title' => 'Berserk', 'slug' => null]);
    $b = Media::factory()->create(['title' => 'Berserk', 'slug' => null]);

    expect($a->slug)->not->toBe($b->slug);
});

test('a series position can be fractional', function () {
    $series = Series::factory()->create();
    $m = Media::factory()->create();
    $series->media()->attach($m, ['position' => 1.5]);

    expect((float) $series->media()->first()->pivot->position)->toBe(1.5);
});

test('external id pair is unique per source', function () {
    $source = ExternalSource::factory()->create();
    MediaExternalId::factory()->create(['external_source_id' => $source->id, 'external_id' => 'X1']);

    expect(fn () => MediaExternalId::factory()->create(['external_source_id' => $source->id, 'external_id' => 'X1']))
        ->toThrow(QueryException::class);
});
```

- [ ] **Step 2: Run to confirm failure.** Run: `php artisan test --compact tests/Feature/Catalog/CatalogSchemaTest.php` → FAIL, class `MediaTypeSeeder` not found.
- [ ] **Step 3: Implement** migrations (A6 exact columns/indexes; GIN trigram on `media.title` via `DB::statement('create index media_title_trgm on media using gin (title gin_trgm_ops)')`; the full-text GIN index is **Phase 4**), models, enums, factories, seeder. Unique slug via a `creating` observer on `Media`.
- [ ] **Step 4: Run test + `php artisan migrate:fresh --seed`** → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: catalog schema, models and media type seed"`

---

### Task 2.2: Redis and Horizon

**Files:**
- Modify: `composer.json` (`laravel/horizon`), `.env.example` (`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_CLIENT=phpredis`, `REDIS_HOST`…), `phpunit.xml` (`QUEUE_CONNECTION=sync`, `CACHE_STORE=array` — tests never need Redis), `app/Providers/HorizonServiceProvider.php` (published; `Horizon::auth` / gate `viewHorizon` → `Gate::allows('access-admin')`), `config/horizon.php` (supervisors: `default`, `ingestion` queue with `maxProcesses` 3)
- Test: `tests/Feature/Admin/HorizonAccessTest.php`

**Interfaces:**
- Produces: queue names `default`, `ingestion` (used by `SyncProvider`/`ImportMediaRecord`); `/horizon` behind `access-admin`.

- [ ] **Step 1: Write the failing test**

```php
test('guests and non-admins cannot open horizon', function () {
    $this->get('/horizon')->assertForbidden();
    $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
});

test('admins can open horizon', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/horizon')->assertOk();
});
```
If Horizon's middleware redirects guests to login instead of returning 403, assert that redirect for the guest line (check `search-docs` Horizon authorization); assert exactly one concrete outcome.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Admin/HorizonAccessTest.php` → FAIL (404, route missing).
- [ ] **Step 3: Implement** `composer require laravel/horizon`, `php artisan horizon:install --no-interaction`, gate wiring. Add CI note: the `tests.yml` Postgres job needs no Redis because tests use `sync`/`array`; add a Redis service **only** if a test touches the real connection.
- [ ] **Step 4: Run** → PASS. Manual: `php artisan horizon` boots with a local Redis.
- [ ] **Step 5: Commit** — `git commit -m "feat: redis queue and horizon behind access-admin"`

---

### Task 2.3: `MetadataProvider` contract, DTOs and the Open Library adapter

**Files:**
- Create: `app/Catalog/Providers/MetadataProvider.php` (A7 interface verbatim), `app/Catalog/Data/{ProviderRecord,NormalizedMedia,NormalizedCreator}.php` (A7; `NormalizedCreator(string $name, CreatorRole $role)`), `app/Catalog/Providers/OpenLibrary/OpenLibraryProvider.php`
- Create fixtures: `tests/Fixtures/providers/openlibrary/{work-dune.json,edition-dune.json,recent-changes.json,work-malformed.json}` (recorded once)
- Create: `database/seeders/ExternalSourceSeeder.php` (rows `openlibrary`, `anilist` with attribution/cover_policy `hotlink`/display_policy; disabled until terms reviewed — seeder sets `enabled` from config `bookflix.providers.<key>.enabled`)
- Modify: `config/bookflix.php` (`providers.openlibrary.{enabled,rate_per_minute=100}`, `providers.anilist.{enabled,rate_per_minute=30}`; `provider_user_agent` from `BOOKFLIX_PROVIDER_USER_AGENT`)
- Test: `tests/Feature/Catalog/OpenLibraryProviderTest.php`

**Interfaces:**
- Consumes: `CreatorRole`, `config('bookflix.providers.openlibrary')`.
- Produces: `OpenLibraryProvider implements MetadataProvider` (`key() === 'openlibrary'`); `NormalizedMedia` public readonly props exactly as listed in A7 (`title`, `mediaTypeSlug`, `alternativeTitles`, `description`, `creators`, `publishers`, `series`, `genres`, `themes`, `tags`, `characters`, `isbns`, `crossIds`, `publicationYear`, `publishedOn`, `language`, `countryOfOrigin`, `ageRating`, `contentWarnings`, `status`, `pageCount`, `chapterCount`, `coverUrl`, `externalDeweyCode`, `sourceUrl`, `isAdult`). All requests send `User-Agent: config('bookflix.provider_user_agent')`.

- [ ] **Step 1: Write the failing test**

```php
test('a work and edition normalize to NormalizedMedia', function () {
    Http::fake([
        'openlibrary.org/works/OL893415W.json' => Http::response(fixture('openlibrary/work-dune.json')),
        'openlibrary.org/works/OL893415W/editions.json*' => Http::response(fixture('openlibrary/edition-dune.json')),
    ]);

    $record = app(OpenLibraryProvider::class)->find('OL893415W');
    $media = app(OpenLibraryProvider::class)->normalize($record);

    expect($record->provider)->toBe('openlibrary')
        ->and($media->title)->toBe('Dune')
        ->and($media->mediaTypeSlug)->toBe('novel')
        ->and($media->isbns)->toContain('9780441172719')
        ->and($media->externalDeweyCode)->toBe('813.54')
        ->and($media->creators[0]->name)->toBe('Frank Herbert')
        ->and($media->creators[0]->role)->toBe(CreatorRole::Author)
        ->and($media->coverUrl)->toStartWith('https://covers.openlibrary.org/')
        ->and($media->genres)->not->toBeEmpty();
    Http::assertSent(fn ($r) => $r->hasHeader('User-Agent', config('bookflix.provider_user_agent')));
});

test('find returns null for a 404', function () {
    Http::fake(['openlibrary.org/*' => Http::response(null, 404)]);
    expect(app(OpenLibraryProvider::class)->find('OL0W'))->toBeNull();
});

test('changedSince yields records lazily from the recent-changes feed', function () {
    Http::fake(['openlibrary.org/recentchanges*' => Http::response(fixture('openlibrary/recent-changes.json')), 'openlibrary.org/works/*' => Http::response(fixture('openlibrary/work-dune.json'))]);

    $records = app(OpenLibraryProvider::class)->changedSince(CarbonImmutable::parse('2026-10-01'));

    expect($records)->toBeInstanceOf(LazyCollection::class)->and($records->first())->toBeInstanceOf(ProviderRecord::class);
});

test('a malformed payload normalizes to an empty title so the validator can reject it', function () {
    $media = app(OpenLibraryProvider::class)->normalize(new ProviderRecord('openlibrary', 'OL1W', fixture('openlibrary/work-malformed.json'), null, now()->toImmutable()));
    expect($media->title)->toBe('');
});
```
(`fixture()` helper added to `tests/Pest.php`: decodes `tests/Fixtures/providers/<path>`.)

- [ ] **Step 2: Run** → FAIL (class not found).
- [ ] **Step 3: Implement.** Use `Http::withUserAgent()->baseUrl('https://openlibrary.org')->retry(0)`; pagination via `LazyCollection::make(function () {…yield…})`. Media type: subjects/`first_publish` → `novel` by default; `picture book`/`graphic novel` subjects → `picture-book`/`graphic-novel`. Never throw on shape drift — return empty strings/arrays. Record real fixtures with a one-off `curl` and trim.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: metadata provider contract, DTOs, open library adapter"`

---

### Task 2.4: AniList adapter

**Files:**
- Create: `app/Catalog/Providers/AniList/AniListProvider.php`; fixtures `tests/Fixtures/providers/anilist/{manga-berserk.json,manhwa-solo-leveling.json,manhua.json,light-novel.json,adult.json,page-1.json}`
- Test: `tests/Feature/Catalog/AniListProviderTest.php`

**Interfaces:**
- Consumes: Task 2.3 contract/DTOs. Produces: `AniListProvider` (`key() === 'anilist'`); GraphQL POST to `https://graphql.anilist.co`; `crossIds['myanimelist']` from `idMal`.

- [ ] **Step 1: Write the failing test**

```php
test('format and country map to a media type', function (string $fixture, string $slug) {
    $record = new ProviderRecord('anilist', '1', fixture("anilist/{$fixture}.json"), null, now()->toImmutable());
    expect(app(AniListProvider::class)->normalize($record)->mediaTypeSlug)->toBe($slug);
})->with([
    ['manga-berserk', 'manga'],          // MANGA + JP
    ['manhwa-solo-leveling', 'manhwa'],  // MANGA + KR
    ['manhua', 'manhua'],                // MANGA + CN
    ['light-novel', 'light-novel'],      // NOVEL
]);

test('adult records are flagged so ingestion can skip them', function () {
    $record = new ProviderRecord('anilist', '9', fixture('anilist/adult.json'), null, now()->toImmutable());
    expect(app(AniListProvider::class)->normalize($record)->isAdult)->toBeTrue();
});

test('staff roles map to creator roles and the MAL id becomes a cross id', function () {
    $media = app(AniListProvider::class)->normalize(new ProviderRecord('anilist', '30002', fixture('anilist/manga-berserk.json'), null, now()->toImmutable()));
    expect(collect($media->creators)->pluck('role')->all())->toContain(CreatorRole::Author)
        ->and($media->crossIds['myanimelist'])->toBe('2')
        ->and($media->alternativeTitles)->toContain('ベルセルク');
});

test('changedSince pages through the GraphQL feed until hasNextPage is false', function () {
    Http::fake(['graphql.anilist.co' => Http::sequence()->push(fixture('anilist/page-1.json'))->push(['data' => ['Page' => ['pageInfo' => ['hasNextPage' => false], 'media' => []]]])]);
    expect(app(AniListProvider::class)->changedSince(null)->count())->toBeGreaterThan(0);
});

test('a 429 surfaces as a RateLimitedByProvider exception carrying retry-after', function () {
    Http::fake(['graphql.anilist.co' => Http::response(null, 429, ['Retry-After' => '30'])]);
    expect(fn () => app(AniListProvider::class)->find('1'))->toThrow(RateLimitedByProvider::class);
});
```
`RateLimitedByProvider` (`app/Catalog/Providers/RateLimitedByProvider.php`, public `int $retryAfter`) and `ProviderUnavailable` (5xx/timeouts) are introduced here and reused by Open Library (retrofit in this task's step 3; add a matching test to Task 2.3's file).

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (note: AniList's `updatedAt` sort may not exist for Media — check at implementation; fall back to stale re-sync by id order per A9). Genres/tags → raw `genres`, `tags`; TaxonomyMapper (2.6) maps them. **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: anilist adapter with adult skip and type mapping"`

---

### Task 2.5: `Deduplicator` (Review Focus #1)

**Files:**
- Create: `app/Catalog/Ingestion/Deduplicator.php`, `database/data/` none; `config/bookflix.php` → `dedup.{auto_title_similarity=0.90, review_min_similarity=0.75, year_window=1}`
- Test: `tests/Feature/Catalog/DeduplicatorTest.php`

**Interfaces:**
- Consumes: `NormalizedMedia`, `Media`, `MediaExternalId`, `DuplicateCandidate`.
- Produces (A7): `Deduplicator::match(NormalizedMedia $media): ?Media` and `Deduplicator::merge(Media $winner, Media $loser): void`; additionally `Deduplicator::candidates(NormalizedMedia $media): Collection<int, array{media: Media, score: float}>` (the 0.75–0.90 band) used by the pipeline to write `duplicate_candidates`. Title normalization: `unaccent` + lowercase + strip punctuation, trigram via `similarity()`.

- [ ] **Step 1: Write the failing tests**

```php
test('the same work from two providers resolves to one media with two external ids', function () {
    $existing = Media::factory()->manga()->create(['title' => 'Berserk', 'publication_year' => 1989]);
    $existing->creators()->attach(Creator::factory()->create(['name' => 'Kentaro Miura']), ['role' => 'author', 'position' => 0]);
    MediaExternalId::factory()->for($existing)->create(['external_source_id' => ExternalSource::factory()->create(['key' => 'anilist']), 'external_id' => '30002']);

    $incoming = normalized(title: 'Berserk', type: 'manga', year: 1990, creators: ['Kentaro Miura'], provider: 'mangadex');

    expect(app(Deduplicator::class)->match($incoming)?->is($existing))->toBeTrue();
});

test('an exact isbn or provider cross id matches regardless of title', function () {
    $existing = Media::factory()->create(['title' => 'Dune']);
    $existing->externalIds()->save(MediaExternalId::factory()->make(['external_source_id' => ExternalSource::factory()->create(['key' => 'isbn'])->id, 'external_id' => '9780441172719']));

    expect(app(Deduplicator::class)->match(normalized(title: 'Dune (Deluxe Edition)', isbns: ['9780441172719'])))->not->toBeNull();
});

test('monster the manga and an unrelated novel called monster never merge', function () {
    $manga = Media::factory()->manga()->create(['title' => 'Monster', 'publication_year' => 1994]);
    $manga->creators()->attach(Creator::factory()->create(['name' => 'Naoki Urasawa']), ['role' => 'author', 'position' => 0]);

    $novel = normalized(title: 'Monster', type: 'novel', year: 2011, creators: ['Walter Dean Myers']);

    expect(app(Deduplicator::class)->match($novel))->toBeNull()
        ->and(app(Deduplicator::class)->candidates($novel))->toBeEmpty();
});

test('same title and creator but a different year beyond the window does not auto merge', function () {
    $existing = Media::factory()->create(['title' => 'Akira', 'publication_year' => 1982]);
    $existing->creators()->attach(Creator::factory()->create(['name' => 'Katsuhiro Otomo']), ['role' => 'author', 'position' => 0]);

    expect(app(Deduplicator::class)->match(normalized(title: 'Akira', year: 2001, creators: ['Katsuhiro Otomo'])))->toBeNull();
});

test('similarity between 0.75 and 0.90 is returned as a review candidate', function () {
    Media::factory()->create(['title' => 'The Lord of the Rings Fellowship']);
    $candidates = app(Deduplicator::class)->candidates(normalized(title: 'Lord of the Rings Fellowship of the Ring'));
    expect($candidates)->not->toBeEmpty()->and($candidates->first()['score'])->toBeBetween(0.75, 0.9);
});

test('merge moves external ids pivots and relations and soft deletes the loser', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    $loser->externalIds()->save(MediaExternalId::factory()->make());
    $loser->genres()->attach(Genre::factory()->create());
    $loser->update(['title' => 'Loser']);

    app(Deduplicator::class)->merge($winner, $loser);

    expect($winner->externalIds()->count())->toBe(1)
        ->and($winner->genres()->count())->toBe(1)
        ->and($loser->fresh()->trashed())->toBeTrue()
        ->and($loser->fresh()->merged_into_id)->toBe($winner->id);
});
```
`normalized(...)` is a test helper in `tests/Pest.php`/`tests/Helpers/` building a `NormalizedMedia` with defaults (add it in this task). **A6 has no ISBN column**, so this plan stores ISBN-13s as `media_external_ids` rows under a synthetic source `isbn` (`external_id` = the ISBN-13). The pipeline (2.6) writes them; the second test above reflects that. Cross-ids (`crossIds`) are matched against `(source key, external_id)` the same way.

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** `match` (1: any `(source, external_id)` among incoming ids/crossIds/isbns; 2: trigram `similarity(unaccent(lower(title)), :t) >= 0.90` AND shared creator name (normalized) AND `abs(year diff) <= 1` AND same `family`). `candidates` returns the 0.75–0.90 band within the same `family`. `merge`: in a transaction move `media_external_ids`, pivot rows (ignore duplicates with `insertOrIgnore`), `media_relations` (both directions), `user_library`/`ratings` do not exist yet (Phase 5 adds a merge hook — note in Phase 5 plan); then `$loser->update(['merged_into_id' => $winner->id])` and `delete()`.
- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: deduplicator with auto-merge and review bands"`

---

### Task 2.6: `IngestionPipeline`, validator, taxonomy mapper, enricher, `ImportMediaRecord` (Review Focus #3, #5)

**Files:**
- Create: `app/Catalog/Ingestion/{IngestionPipeline,RecordValidator,TaxonomyMapper,Enricher}.php`, `app/Catalog/Data/ImportOutcome.php` is the enum `app/Enums/ImportOutcome.php` (`Created`, `Updated`, `Merged`, `Skipped`), `app/Jobs/ImportMediaRecord.php`, `database/data/taxonomy/openlibrary.csv` + `anilist.csv` (`provider_term,kind,bookflix_slug`, kind ∈ genre|theme|tag)
- Test: `tests/Feature/Catalog/IngestionPipelineTest.php`

**Interfaces:**
- Consumes: `MetadataProvider`, `Deduplicator`, DTOs, `ExternalSource` rows.
- Produces (A7): `IngestionPipeline::import(ProviderRecord $record, MetadataProvider $provider): ImportOutcome`. `RecordValidator::validate(NormalizedMedia): bool` (non-blank trimmed title, known `mediaTypeSlug`, non-empty external id, `! isAdult`). `TaxonomyMapper::map(string $provider, NormalizedMedia): array{genres: list<string>, themes: list<string>, tags: list<string>}` (unknown terms go to `tags`). `Enricher::apply(Media $media, NormalizedMedia $incoming, string $provider): void` honoring `locked_fields`. Job: `ImportMediaRecord(ProviderRecord $record, string $providerKey, ?int $ingestionRunId)`, queue `ingestion`, `$tries = 5`, `backoff(): [10, 60, 300]`, middleware `RateLimited` keyed by provider (`RateLimiter::for('provider:openlibrary'|'provider:anilist')` in `AppServiceProvider`, limits from config). Pipeline also: sets `search_text` (titles, creators, series, genres, themes, tags, characters, publisher — Phase 4 reuses), writes `duplicate_candidates` from `Deduplicator::candidates`, and — extension points left for later phases — dispatches nothing yet (Phase 3 adds `ClassifyMedia`, Phase 6/7 `GenerateMediaFeatures`).

- [ ] **Step 1: Write the failing tests**

```php
test('importing creates media with creators genres and an external id', function () {
    $outcome = importFixture('anilist', 'manga-berserk');

    expect($outcome)->toBe(ImportOutcome::Created)
        ->and(Media::count())->toBe(1)
        ->and(MediaExternalId::where('external_id', '30002')->exists())->toBeTrue()
        ->and(Media::first()->search_text)->toContain('berserk');
});

test('re-importing the same payload changes nothing', function () {
    importFixture('anilist', 'manga-berserk');
    $before = Media::first()->updated_at;
    $this->travel(1)->minute();

    expect(importFixture('anilist', 'manga-berserk'))->toBe(ImportOutcome::Skipped)
        ->and(Media::count())->toBe(1)
        ->and(Media::first()->updated_at->equalTo($before))->toBeTrue();
});

test('a changed payload updates the media and keeps one external id row', function () {
    importFixture('anilist', 'manga-berserk');
    expect(importFixture('anilist', 'manga-berserk', mutate: ['description' => 'New blurb']))->toBe(ImportOutcome::Updated)
        ->and(MediaExternalId::count())->toBe(1);
});

test('locked fields are not overwritten by a sync', function () {
    importFixture('anilist', 'manga-berserk');
    Media::first()->update(['title' => 'Berserk (admin)', 'locked_fields' => ['title']]);

    importFixture('anilist', 'manga-berserk', mutate: ['description' => 'x']);

    expect(Media::first()->title)->toBe('Berserk (admin)');
});

test('invalid records are skipped and never created', function (array $mutate) {
    expect(importFixture('anilist', 'manga-berserk', mutate: $mutate))->toBe(ImportOutcome::Skipped)->and(Media::count())->toBe(0);
})->with([
    'blank title' => [['title' => ['romaji' => ' ', 'english' => null, 'native' => null]]],
    'no type' => [['format' => 'UNKNOWN_FORMAT']],
    'adult' => [['isAdult' => true]],
]);

test('a failure inside the store step rolls everything back', function () {
    $this->mock(Enricher::class)->shouldReceive('apply')->andThrow(new RuntimeException('boom'));
    expect(fn () => importFixture('anilist', 'manga-berserk'))->toThrow(RuntimeException::class);
    expect(Media::count())->toBe(0)->and(MediaExternalId::count())->toBe(0)->and(Creator::count())->toBe(0);
});

test('unmapped provider terms land in tags, mapped ones in genres', function () {
    importFixture('anilist', 'manga-berserk');
    expect(Media::first()->genres->pluck('slug'))->toContain('fantasy');
});
```
`importFixture(provider, fixtureName, mutate)` helper in `tests/Helpers`: builds a `ProviderRecord` from the fixture payload (deep-merging `mutate`) and calls `IngestionPipeline::import`.

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** Store in `DB::transaction`; short-circuit on equal `payload_hash` (sha256 of canonical JSON) before any write; upsert external id via `updateOrCreate(['external_source_id','external_id'])`. Genres/themes/tags `firstOrCreate` by slug.
- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: idempotent ingestion pipeline with locking and validation"`

---

### Task 2.7: `SyncProvider`, scheduling, rate limiting, retries, `ingestion_runs` (Review Focus #2)

**Files:**
- Create: `app/Jobs/SyncProvider.php`, `routes/console.php` schedule lines, `app/Console/Commands/SyncProviderCommand.php` (`bookflix:sync {provider} {--full}`)
- Modify: `ImportMediaRecord` (adds run counting), `AppServiceProvider` (rate limiters from 2.6)
- Test: `tests/Feature/Catalog/SyncProviderTest.php`

**Interfaces:**
- Consumes: `MetadataProvider`, `IngestionRun`, `ExternalSource.sync_cursor`.
- Produces: `SyncProvider(string $providerKey, IngestionMode $mode = IngestionMode::Incremental)` — creates an `IngestionRun` (`running`), iterates `changedSince($cursor)` and dispatches one `ImportMediaRecord` per record **using `Bus::batch`** with `->allowFailures()` and a `finally` callback that finalizes the run: all ok → `succeeded`; some failed → `partial`; fetch failed before any record → `failed` with `error`. `counts` increments through `IngestionRun::bump(string $key): void` (atomic `jsonb_set`). Schedule: `SyncProvider('openlibrary')` hourly, `SyncProvider('anilist')` every 6 hours, only for `enabled` sources.

- [ ] **Step 1: Write the failing tests**

```php
test('a provider 429 releases the job with the retry-after delay', function () {
    $provider = fakeProviderThrowing(new RateLimitedByProvider(retryAfter: 30));
    $job = new ImportMediaRecord(providerRecord(), 'anilist', run()->id);

    $fake = $job->withFakeQueueInteractions();
    $job->handle(app(IngestionPipeline::class));
    $fake->assertReleased(30);
});

test('a job backs off 10 60 300 seconds and tries five times', function () {
    $job = new ImportMediaRecord(providerRecord(), 'anilist', null);
    expect($job->backoff())->toBe([10, 60, 300])->and($job->tries)->toBe(5);
});

test('a persistently failing provider marks the run failed and leaves existing media untouched', function () {
    $existing = Media::factory()->create(['title' => 'Keep me']);
    Http::fake(['graphql.anilist.co' => Http::response(null, 503)]);

    (new SyncProvider('anilist'))->handle();

    $run = IngestionRun::latest()->first();
    expect($run->status)->toBe(IngestionStatus::Failed)->and($run->error)->not->toBeNull()
        ->and($existing->fresh()->title)->toBe('Keep me');
});

test('a malformed record is skipped and counted while the rest succeed', function () {
    Http::fake(['graphql.anilist.co' => Http::response(fixture('anilist/page-with-one-bad-record.json'))]);
    (new SyncProvider('anilist'))->handle();

    $run = IngestionRun::latest()->first();
    expect($run->status)->toBe(IngestionStatus::Succeeded)
        ->and($run->counts['skipped'])->toBe(1)->and($run->counts['created'])->toBeGreaterThan(0);
});

test('a retried job never duplicates rows', function () {
    $job = new ImportMediaRecord(providerRecord(), 'anilist', run()->id);
    $job->handle(app(IngestionPipeline::class));
    $job->handle(app(IngestionPipeline::class));

    expect(Media::count())->toBe(1)->and(MediaExternalId::count())->toBe(1);
});

test('some records failing mid-run yields a partial run', function () {
    fakePageWith(good: 2, throwing: 1);
    (new SyncProvider('anilist'))->handle();
    expect(IngestionRun::latest()->first()->status)->toBe(IngestionStatus::Partial);
});

test('the sync cursor advances only after a successful run', function () {
    Http::fake(['graphql.anilist.co' => Http::response(null, 503)]);
    (new SyncProvider('anilist'))->handle();
    expect(ExternalSource::firstWhere('key', 'anilist')->sync_cursor)->toBeNull();
});

test('only enabled sources are scheduled', function () {
    ExternalSource::where('key', 'anilist')->update(['enabled' => false]);
    $this->artisan('schedule:list')->doesntExpectOutputToContain('anilist')->assertSuccessful();
});
```
(Use `Queue::fake()`/`Bus::fake()` where the test must not run the batch; the failure tests run with the `sync` queue so the batch `finally` fires.)

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** per Interfaces. In `ImportMediaRecord::handle`, catch `RateLimitedByProvider` → `release($e->retryAfter)`; `ProviderUnavailable`/`ConnectionException` rethrow so the queue backs off; `failed(Throwable)` bumps `failed`.
- [ ] **Step 4: Run** `php artisan test --compact tests/Feature/Catalog` → PASS. **Step 5: Commit** — `git commit -m "feat: scheduled provider sync with rate limits, retries and run tracking"`

---

### Task 2.8: Admin — providers and runs, media edit with locking, duplicate review and merge (Review Focus #4)

**Files:**
- Create: `resources/views/pages/admin/providers/⚡index.blade.php` (sources + last 20 runs, toggle `enabled`, "Sync now" button dispatching `SyncProvider`), `pages/admin/media/⚡index.blade.php` (search/list, `?trashed`), `pages/admin/media/⚡edit.blade.php` (editable `title`, `description`, `age_rating`, `status`; each saved field is appended to `locked_fields`; "Unlock" per field; soft-delete "Remove invalid record"), `pages/admin/duplicates/⚡index.blade.php` (pending candidates side-by-side, **Merge** (pick winner) / **Dismiss**)
- Modify: `routes/admin.php` (`admin.providers`, `admin.media.index`, `admin.media.edit`, `admin.duplicates`), `resources/views/pages/admin/⚡index.blade.php` (links)
- Create: `app/Http/Controllers/MediaRedirectController.php` is **not** used; redirect lives in route model binding — `Route::get('stories/{slug}')` is added in Phase 4. Here: `Media::resolveSlug(string $slug): ?Media` follows `merged_into_id` once, and a temporary `Route::get('/stories/{slug}', fn)` named `stories.show` is **Phase 4**; this task tests the helper and the admin merge only.
- Test: `tests/Feature/Admin/{AdminProvidersTest,AdminMediaEditTest,AdminDuplicatesTest}.php`

**Interfaces:**
- Consumes: `Deduplicator::merge`, `SyncProvider`, `access-admin`. Produces: `Media::resolveSlug(string $slug): ?Media` (returns the merge target for a merged-away slug; `null` for an unknown or plain-deleted slug; Phase 4 uses it for the 301). `DuplicateCandidate` statuses `pending|merged|dismissed`, `resolved_by`.

- [ ] **Step 1: Write the failing tests**

```php
test('non admins get 403 on every admin catalog page', function (string $route) {
    $this->actingAs(User::factory()->create())->get(route($route))->assertForbidden();
})->with(['admin.providers', 'admin.media.index', 'admin.duplicates']);

test('admins see providers and recent runs', function () {
    IngestionRun::factory()->create(['status' => 'partial']);
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.providers'))->assertOk()->assertSee('partial');
});

test('sync now dispatches the provider job', function () {
    Bus::fake();
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test('pages::admin.providers.index')->call('syncNow', 'openlibrary');
    Bus::assertDispatched(SyncProvider::class, fn ($j) => $j->providerKey === 'openlibrary');
});

test('editing a field locks it', function () {
    $media = Media::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.media.edit', ['media' => $media])->set('title', 'Fixed')->call('save');

    expect($media->fresh()->title)->toBe('Fixed')->and($media->fresh()->locked_fields)->toContain('title');
});

test('an unedited field is not locked', function () {
    $media = Media::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test('pages::admin.media.edit', ['media' => $media])->set('title', 'Fixed')->call('save');
    expect($media->fresh()->locked_fields)->not->toContain('description');
});

test('removing an invalid record soft deletes it', function () {
    $media = Media::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test('pages::admin.media.edit', ['media' => $media])->call('remove');
    expect($media->fresh()->trashed())->toBeTrue();
});

test('merging a duplicate candidate moves ids and the loser slug resolves to the winner', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    $loser->externalIds()->save(MediaExternalId::factory()->make());
    $candidate = DuplicateCandidate::factory()->create(['media_id' => $winner->id, 'duplicate_media_id' => $loser->id, 'score' => 0.82]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test('pages::admin.duplicates.index')->call('merge', $candidate->id);

    expect($winner->externalIds()->count())->toBe(1)
        ->and($candidate->fresh()->status)->toBe(DuplicateStatus::Merged)
        ->and($candidate->fresh()->resolved_by)->toBe($admin->id)
        ->and(Media::resolveSlug($loser->slug)?->is($winner))->toBeTrue();
});

test('dismissing a candidate keeps both records', function () {
    $c = DuplicateCandidate::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test('pages::admin.duplicates.index')->call('dismiss', $c->id);
    expect($c->fresh()->status)->toBe(DuplicateStatus::Dismissed)->and(Media::count())->toBe(2);
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (single-file Livewire pages; every action calls `$this->authorize('access-admin')`; `Media::resolveSlug` uses `withTrashed()` then follows `merged_into_id`; unlock removes the field from `locked_fields`). Load `fluxui-development`/`livewire-development` first; F4 admin screens must be signed off before styling.
- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: admin providers, media editing with locks, duplicate merge"`

---

### Task 2.9 (2b, parallelizable): MangaDex, Metron, VNDB, Taddy adapters

**Files (one subagent per adapter, independent):** `app/Catalog/Providers/{MangaDex,Metron,Vndb,Taddy}/*Provider.php`, fixtures, `tests/Feature/Catalog/<Name>ProviderTest.php`; `config/bookflix.php` provider blocks; `.env.example` (`METRON_USERNAME`, `METRON_PASSWORD`, `VNDB_API_TOKEN`, `TADDY_API_KEY`, `TADDY_USER_ID`); `ExternalSourceSeeder` rows; scheduler lines (disabled by default).

**Interfaces:** each implements `MetadataProvider` exactly as Task 2.3; reuse `RateLimitedByProvider`/`ProviderUnavailable`.

Each adapter copies the Task 2.3/2.4 test template with these distinct assertions:
- MangaDex: `crossIds['anilist']`/`['myanimelist']` from `attributes.links`; type from `originalLanguage` (`ja`→manga, `ko`→manhwa, `zh`/`zh-hk`→manhua); **no chapter image URLs anywhere in `NormalizedMedia`**; attribution text recorded in `external_sources`.
- Metron: basic auth from env; issue/volume → `media_type=comic-book`, `issue_number`, series `position`; `modified` filter drives `changedSince`.
- VNDB: POST `https://api.vndb.org/kana/vn`; type `visual-novel`; adult flag from `olang`/rating filter → `isAdult`.
- Taddy (optional; skip if no key): type `webcomic`.

Commit per adapter: `feat: <name> provider adapter`.

---

## Phase 2 exit criteria
- [ ] `composer test` green; `php artisan migrate:fresh --seed` clean.
- [ ] 10k+ stories synced from Open Library + AniList on a dev DB; no duplicate `(source, external_id)`; `ingestion_runs` visible in admin; Horizon green.
- [ ] `security-review` run (admin authorization on every Livewire action; provider credentials only in env).
- [ ] Ask the user to run the full `php artisan test --compact`.

## Self-review notes
- Spec coverage: §5 providers (2.3, 2.4, 2.9), §9 catalog fields (2.1), §10 series (2.1), §42 pipeline (2.6, 2.7), §43 admin media/merge/remove (2.8). Classify/NLP/embedding steps of §42 are deliberately deferred (Phases 3, 6, 7).
- Names match master plan A6/A7/A9. Additions beyond A6, flagged in the report: `media.merged_into_id`; ISBNs stored as `media_external_ids` under synthetic source `isbn`.
