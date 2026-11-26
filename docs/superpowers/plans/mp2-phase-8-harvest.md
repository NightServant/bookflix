# Phase 8: Harvest System and Sources Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a local-first harvester (own database, own private disk, own queue) that fetches public-domain and openly licensed books from vetted sources, gates them by licence, validates and scans every file, and hands ready items to an admin's Verify click, with nothing live until the owner approves in writing.

**Architecture:** A second Postgres connection `harvest` and a private disk `harvest-raw` hold sources, raw records, files and licence evidence. Jobs on queue `harvest` run catalog → licence gate → download → dedupe → enrich; the production app reads ready items through `HarvestApi` and promotes through `PromoteRecord`, which is the only code that writes both sides, one connection at a time, never joined. Gutenberg and Standard Ebooks adapters wrap the existing providers.

**Tech Stack:** Laravel 13 queues (Horizon supervisor `harvest`), Eloquent on a second connection, Pest 5 on Postgres, existing `ZipGuard`/`EpubInspector`, `Http::fake()` fixtures.

**Spec:** `docs/superpowers/specs/2026-11-26-cleanup-brief.md` F3, F4, F5, F9 and Part I (harvester tests); masterplan `docs/superpowers/plans/2026-11-26-bookflix-masterplan-2.md` Part F "Phase 8" and F.0.

**Branch:** `mp2-phase-8-harvest` · **Ledger:** `docs/superpowers/plans/mp2-phase-8-ledger.md` · **Depends on:** Phase 5 (`App\Admin\Contracts\WorkQueueSource`, `App\Admin\WorkQueueItem`, `config('bookflix.admin_work_queue_sources')`), Phase 7 (`content_sources.jurisdiction`), Phase 1 (secret scan: no token in any file).

## Global Constraints

Every Global Constraint in the masterplan applies. Phase-specific:

- The agent never runs a live harvest, never downloads from a real source, never creates an enabled source. Tests use generated fixtures (`tinyEpub()`, `comicSource()` helpers in `tests/Pest.php`) and `Http::fake()`.
- Every new source row is `enabled=false`, `approved_by=null`, `approved_at=null` until the owner approves in writing; the only approval path is `php artisan harvest:approve`, run by the owner.
- Code never joins across connections `pgsql` and `harvest`; no foreign key crosses them (`promotions.bookflix_media_id` and `approved_by` are plain bigints).
- Env names: `HARVEST_ENABLED` (default false), `HARVEST_DB_HOST|PORT|DATABASE|USERNAME|PASSWORD` (database default `bookflix_harvest`), `HARVEST_CONTACT_EMAIL`, `HARVEST_SERVICE_TOKEN`, `HARVEST_API_URL`, `HARVEST_AUTO_APPROVE` (default false), `HARVEST_RAW_DRIVER` (default `local`). Each gets a blank or safe line in `.env.example`; `.env` is never edited.
- Circuit breaker: 5 consecutive failed runs (`bookflix.harvest.breaker_threshold`). Backoff: 60, 120, 240, 480 seconds. Default caps per source: 50 files, 2048 MB.
- Test database `bookflix_harvest_test` (user `postgres`/`postgres`), created once by hand locally (`createdb -h 127.0.0.1 -U postgres bookflix_harvest_test`) and by a CI step. Tests migrate it with plain `migrate` (additive), never `migrate:fresh`.
- `BOOKFLIX_OUTSIDE_REVIEWS_ENABLED` stays unset. Nothing in this phase turns a source, the scheduler, or auto-approve on.

## Review Focus

1. **An ambiguous or missing rights statement still gets downloaded.** A record with no statement, an unknown one, or one for another jurisdiction must end `needs_review` with zero HTTP calls to the file URL. → Task 8.5 (`LicenseGate` and `RunHarvest` tests assert `Http::assertNotSent` for the file URL).
2. **A harvested file is published without a human or without a scan.** Ready items must need an admin click; with the default `NullScanner`, auto-approve must still never promote. → Task 8.7.
3. **A bad file is deleted or served.** A fake-EPUB, a zip-slip archive and an infected file must be quarantined (kept, flagged, never ready). → Task 8.5.
4. **A source on the F4 list, or an unapproved source, runs.** Excluded hosts and names are refused at create, update, enable and run time; an unapproved source never runs, even if `enabled` is forced. → Task 8.2 and Task 8.4.
5. **A failing source retries forever or fails silently.** After 5 consecutive failures the source is paused and an admin work-queue item appears; a success resets the count. → Task 8.4.

## Cross-phase interfaces

**Consumes:** Phase 5 `App\Admin\Contracts\WorkQueueSource::items(): iterable<WorkQueueItem>` and `App\Admin\WorkQueueItem` (named args `area`, `severity`, `label`, `count`, `oldestAt`, `status`, `ctaLabel`, `ctaUrl`); `config('bookflix.admin_work_queue_sources')`; Phase 7 `content_sources.jurisdiction`; existing `IngestionPipeline::import()`, `RecordContentFile`, `Deduplicator::match()`, `ZipGuard`, `EpubInspector`, `GutenbergProvider`, `StandardEbooksProvider`.

**Produces (registry names, exact):** connection `harvest`; models `App\Harvest\Models\{Source, HarvestRun, RawRecord, HarvestFile, LicenseEvidence, Promotion}` (`HarvestFile` on table `files`); contracts `App\Harvest\Contracts\HarvestAdapter`, `App\Harvest\Contracts\FileScanner`; `App\Harvest\LicenseGate::assess(RawRecord $record): LicenseEvidence`; jobs `App\Harvest\Jobs\RunHarvest(int $sourceId)`, `DownloadHarvestFile(int $rawRecordId)`, `PromoteRecord(int $rawRecordId, int $adminId)`; routes `internal.harvest.ready` (GET `/internal/harvest/ready`), `internal.harvest.promote` (POST `/internal/harvest/promote`), guarded by `config('bookflix.harvest.service_token')`; config `bookflix.harvest.*` including `excluded_hosts`; `App\Harvest\HarvestWorkQueueSource`. Additions (not renames): route `internal.harvest.decide`, `App\Harvest\HarvestApi`, `App\Harvest\ReadyItem`, `App\Harvest\SourceRefused`, command `harvest:approve`.

## Rulings

- **P8-R1: schema = the brief's columns exactly, plus named additions.** Additions: `sources.key|adapter|cadence|vetting jsonb|incremental jsonb|consecutive_failures|paused_at|max_files|max_bytes`; `harvest_runs.report jsonb`; `raw_records.state` and a functional index on `payload->'normalized'->>'dedupe_key'`; `files.failure_reason|scan_status`. The brief calls its schema a "starting point". Cost if wrong: low; a column nobody reads.
- **P8-R2: `counts` is one jsonb column** (`fetched`, `new`, `updated`, `failed`). Cost: low.
- **P8-R3: the admin screen reads through `HarvestApi`**, which calls the same `ReadyQuery` in process when `bookflix.harvest.api_url` is null and the internal routes over HTTP with the service token when it is set. The routes exist and are tested now so deploying the harvester separately is an env change. Cost: medium if the HTTP mode is never exercised before deployment; the runbook lists it.
- **P8-R4: `PromoteRecord` is the one job that writes the production connection** (media, content source) and the production disk, in separate steps. Dedupe against production uses `Deduplicator::match()` on the default connection and compares in PHP with the harvest-side result. Cost: low.
- **P8-R5: ambiguous records are reviewed by an admin, not stuck.** `internal.harvest.decide` (`approved|rejected`) records `reviewer` and `decided_at`; approval queues the download. Cost: low.
- **P8-R6: wrapped providers read `bookflix.provider_user_agent`, so `RunHarvest` sets it to the harvest User-Agent for the run** (the `harvest` queue only runs harvest jobs). Cost: low; ponytail marker names the ceiling.
- **P8-R7: F9's code shows "4.1 / 5" plus a separate "N ratings" line**, not the brief's "4.1 on Open Library (2,345 ratings)". Task 8.8 changes the outside rating row and the existing assertion in `tests/Feature/Social/StoryReviewsTest.php`. Cost: low.
- **P8-R8: deny-list has `excluded_hosts` (domains) and `excluded_names` (tools with no own domain).** Matching is by host suffix or lowercase name substring; domains are best effort and carry `TODO(owner)` to confirm. Cost: medium; a mirror on a new domain needs adding.
- **P8-R9: the comics adapter reads an assumed JSON feed shape** because no official feed has been verified. It is a skeleton behind vetting; if vetting finds no machine-readable access, delete it and run comics as manual imports (brief F5 rollout). Cost: low.

## Baseline re-check

Run first, record in the ledger:
1. `grep -rn "harvest" app config routes database` returns nothing (no harvest code yet).
2. Open `app/Catalog/Providers/Gutenberg/GutenbergProvider.php` and `StandardEbooks/StandardEbooksProvider.php`: both implement `MetadataProvider, OffersContentFiles`.
3. `app/Jobs/FetchContentFile.php` still has private `rights()`, `download()`, `send()`.
4. `php artisan route:list` shows `admin.content` and no `internal.*`.
5. `grep -n "'4.1 / 5'" tests/Feature/Social/StoryReviewsTest.php` hits (F9 baseline).
6. Phase 5 and 7 names exist: `grep -rn "interface WorkQueueSource" app`; `grep -n jurisdiction database/migrations/*`. If missing, stop and record "Needs decision".

## Tasks

### Task 8.1: The harvest connection, disk, queue and six tables

**Files:**
- Modify: `config/database.php` (connection `harvest`, copy of `pgsql` with `HARVEST_DB_*`, default database `bookflix_harvest`), `config/filesystems.php` (disk `harvest-raw`: driver `env('HARVEST_RAW_DRIVER','local')`, root `storage_path('app/harvest-raw')`, `visibility` private, no `url`, `serve` false; S3 keys from `HARVEST_AWS_*`), `config/bookflix.php` (key `harvest`, see below), `config/horizon.php` (supervisor `harvest`, queue `harvest`, timeout 600, maxProcesses 1, in `defaults` and each environment), `routes/console.php`, `.env.example`, `phpunit.xml` (`HARVEST_DB_DATABASE=bookflix_harvest_test`, `HARVEST_DB_*` as `DB_*`, `HARVEST_ENABLED=false` forced), `.github/workflows/tests.yml` (in job `ci`, before "Run CI Checks": `PGPASSWORD=postgres psql -h 127.0.0.1 -U postgres -c 'CREATE DATABASE bookflix_harvest_test'`)
- Create: `database/migrations/harvest/2026_11_27_000001_create_harvest_tables.php`, `app/Harvest/Models/{Source,HarvestRun,RawRecord,HarvestFile,LicenseEvidence,Promotion}.php`, `app/Harvest/Enums/{SourceAccess,RecordState,FileStatus,LicenseVerdict}.php`, `database/factories/Harvest/{SourceFactory,RawRecordFactory,HarvestFileFactory,LicenseEvidenceFactory}.php`, `tests/Concerns/UsesHarvestDatabase.php`
- Modify: `tests/Pest.php` (`uses(\Tests\Concerns\UsesHarvestDatabase::class)->in('Feature/Harvest');`)
- Test: `tests/Feature/Harvest/HarvestSchemaTest.php`

**Interfaces:** Produces the models above (`protected $connection = 'harvest'`, `HarvestFile::$table = 'files'`), enums `SourceAccess` (`Api`, `Opds`, `BulkDump`, `Mirror`), `RecordState` (`New`, `NeedsReview`, `Blocked`, `Queued`, `Downloaded`, `Duplicate`, `Quarantined`, `Ready`, `Promoted`), `FileStatus` (`Queued`, `Downloaded`, `Failed`, `Quarantined`), `LicenseVerdict` (`Allowed`, `Review`, `Blocked`). Config `bookflix.harvest`: `enabled`, `contact_email`, `service_token`, `api_url`, `auto_approve` (false), `breaker_threshold` (5), `queue` (`harvest`), `disk` (`harvest-raw`), `max_file_mb` (100), `default_caps` (`files` 50, `bytes_mb` 2048), `storage_price_per_gb_month` (null, `TODO(owner)`), `adapters` (empty map), `license_policies` (Task 8.5), `excluded_hosts`/`excluded_names` (Task 8.2).

`tests/Concerns/UsesHarvestDatabase.php`: Laravel calls `setUp{Trait}` and `tearDown{Trait}` on traits a test class uses. `setUpUsesHarvestDatabase()` runs `Artisan::call('migrate', ['--database' => 'harvest', '--path' => 'database/migrations/harvest', '--force' => true])` once per process (static flag), then `DB::connection('harvest')->beginTransaction()` and `Storage::fake('harvest-raw')`; `tearDownUsesHarvestDatabase()` rolls the transaction back. The migration directory is not scanned by plain `migrate` (the migrator reads only the top level of `database/migrations`), so production migrations never touch the harvest database.

- [ ] **Step 1: Write the failing tests**

```php
use Illuminate\Support\Facades\Schema;

test('the harvest tables carry the brief columns', function (string $table, array $columns) {
    expect(Schema::connection('harvest')->hasColumns($table, $columns))->toBeTrue();
})->with([
    ['sources', ['name', 'base_url', 'access_type', 'license_policy', 'jurisdiction', 'rate_limit_per_minute', 'terms_checked_at', 'approved_by', 'approved_at', 'enabled']],
    ['harvest_runs', ['source_id', 'started_at', 'finished_at', 'status', 'counts', 'error']],
    ['raw_records', ['source_id', 'external_id', 'payload', 'checksum', 'fetched_at']],
    ['files', ['raw_record_id', 'format', 'source_url', 'storage_key', 'sha256', 'bytes', 'detected_mime', 'scanned_at', 'status']],
    ['license_evidence', ['raw_record_id', 'license', 'rights_statement', 'evidence_url', 'jurisdiction', 'auto_assessment', 'reviewer', 'decision', 'decided_at']],
    ['promotions', ['raw_record_id', 'bookflix_media_id', 'promoted_by', 'promoted_at']],
]);

test('a record is unique per source and external id', function () {
    $record = RawRecord::factory()->create(['external_id' => '84']);
    expect(fn () => RawRecord::factory()->create(['source_id' => $record->source_id, 'external_id' => '84']))->toThrow(QueryException::class);
});

test('models live on the harvest connection and nothing joins production', function () {
    expect((new Source)->getConnectionName())->toBe('harvest')->and((new HarvestFile)->getTable())->toBe('files')
        ->and(Schema::connection('harvest')->hasTable('media'))->toBeFalse()
        ->and(Schema::connection('harvest')->hasTable('users'))->toBeFalse();
});

test('the harvest disk is private and has no url', function () {
    expect(config('filesystems.disks.harvest-raw.url'))->toBeNull()->and(config('filesystems.disks.harvest-raw.serve'))->toBeFalse();
});

test('the harvest scheduler entry only runs when HARVEST_ENABLED is true', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->description, 'harvest:dispatch'));
    expect(config('bookflix.harvest.enabled'))->toBeFalse()->and($event->filtersPass(app()))->toBeFalse();
    config(['bookflix.harvest.enabled' => true]);
    expect($event->filtersPass(app()))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails.** `env -u AI_AGENT -u CLAUDECODE vendor/bin/pest tests/Feature/Harvest/HarvestSchemaTest.php`. Expected: FAIL (connection `harvest` not defined / classes missing). If `bookflix_harvest_test` does not exist locally, create it first (command in Global Constraints).
- [ ] **Step 3: Implement.** The migration creates the six tables with the brief's columns and P8-R1 additions: `sources.key` unique, `enabled` default false, `cadence` default `daily`, `consecutive_failures` smallint default 0; `raw_records` unique `(source_id, external_id)`, `state` default `new`, functional index via `$table->rawIndex("(payload->'normalized'->>'dedupe_key')", 'raw_records_dedupe_key_index')`; `files.status` default `queued`, `files.scan_status` default `not_scanned`; `bytes` bigint; `promotions.bookflix_media_id` and `sources.approved_by` plain bigints, no FKs to production; foreign keys only inside the harvest connection, `cascadeOnDelete` off (nothing is deleted silently). Models cast enums, jsonb and datetimes; `RawRecord` has `source()`, `file()`, `evidence()`, `promotion()`. Scheduler: `Schedule::call(fn () => HarvestScheduler::dispatchDue())->name('harvest:dispatch')->description('harvest:dispatch')->everyMinute()->when(fn () => (bool) config('bookflix.harvest.enabled'))`; `HarvestScheduler::dispatchDue()` is defined in Task 8.4 (create it now as an empty-bodied static returning `[]`; Task 8.4 fills it, its test is there). Run Pint.
- [ ] **Step 4: Run to verify it passes.** Same command, then `vendor/bin/pest tests/Feature/Catalog/CatalogSchemaTest.php` (production schema untouched). Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: harvest connection, private disk, queue and the six harvest tables"`

### Task 8.2: Source guard: F4 deny-list, vetting fields, owner approval

**Files:**
- Modify: `config/bookflix.php` (`harvest.excluded_hosts`, `harvest.excluded_names`), `app/Harvest/Models/Source.php`
- Create: `app/Harvest/SourceRefused.php`, `app/Harvest/ExcludedSources.php`, `app/Console/Commands/HarvestApprove.php`, `database/seeders/HarvestSourceSeeder.php`
- Test: `tests/Feature/Harvest/SourceGuardTest.php`

**Interfaces:** Produces `ExcludedSources::reason(string $baseUrl, string $name): ?string` (null when allowed); `Source::canRun(): bool` (`enabled`, `approved_at` and `approved_by` set, not paused, not excluded); `SourceRefused extends RuntimeException`; `php artisan harvest:approve {key} {--admin-email=} {--enable}`; seeder rows for `gutenberg` and `standardebooks`, both `enabled=false`, unapproved, with `vetting` filled from the brief's conditions.

`excluded_hosts` (domains, matched on host equality or `.domain` suffix; add `TODO(owner): confirm current domains` above it): `annas-archive.org`, `annas-archive.li`, `annas-archive.se`, `z-library.sk`, `z-lib.org`, `1lib.sk`, `singlelogin.re`, `libgen.is`, `libgen.rs`, `libgen.st`, `libgen.li`, `sci-hub.se`, `sci-hub.st`, `sci-hub.ru`, `mobilism.org`, `myanonamouse.net`, `welib.org`, `flibusta.is`, `oceanofpdf.com`, `oceanofepub.com`, `vdoc.pub`, `pdfcoffee.com`, `getcomics.org`, `getcomics.info`, `readcomiconline.li`, `batcave.biz`, `audiobookbay.lu`, `nyaa.si`, `mangafire.to`, `manganato.com`, `mangakatana.com`, `mangapill.com`, `mangahere.cc`, `fanfox.net`, `toonily.com`, `novelfire.net`, `ranobes.net`; directories `wotaku.wiki`, `theindex.moe`, `everythingmoe.com`; plus `mangadex.org` (metadata only, masterplan Global Constraints). `excluded_names` (lowercase substrings, tools and the unnamed rest): `libation`, `acsm-calibre-plugin`, `audible`, `lightnovel crawler`, `webtoepub`, `fmd2`, `comics downloader`, `hdoujindownloader`, `calibre download plugin`, `kindle jailbreak`, `suwayomi`, `magazine pdf` (`TODO(owner): name the magazine PDF sites the brief means`).

- [ ] **Step 1: Write the failing tests**

```php
test('an excluded host or name cannot be added, enabled or renamed into', function (string $url, string $name) {
    expect(fn () => Source::factory()->create(['base_url' => $url, 'name' => $name]))->toThrow(SourceRefused::class);
})->with([
    'shadow library' => ['https://annas-archive.org', 'Archive'],
    'subdomain' => ['https://www.libgen.is/x', 'Books'],
    'directory' => ['https://theindex.moe', 'Index'],
    'tool by name' => ['https://example.org', 'Lightnovel Crawler mirror'],
    'mangadex' => ['https://mangadex.org', 'Chapters'],
]);

test('every F4 site the brief names is on the list', function (string $host) {
    expect(config('bookflix.harvest.excluded_hosts'))->toContain($host);
})->with(['annas-archive.org', 'libgen.is', 'sci-hub.se', 'nyaa.si', 'mangafire.to', 'toonily.com', 'wotaku.wiki', 'everythingmoe.com']);

test('a source that was fine and is later pointed at an excluded host is refused on update', function () {
    $source = Source::factory()->create();
    expect(fn () => $source->update(['base_url' => 'https://z-library.sk']))->toThrow(SourceRefused::class);
});

test('a source cannot be enabled or run without the owner approval', function () {
    $source = Source::factory()->create();
    expect(fn () => $source->update(['enabled' => true]))->toThrow(SourceRefused::class)
        ->and($source->fresh()->canRun())->toBeFalse();
    DB::connection('harvest')->table('sources')->where('id', $source->id)->update(['enabled' => true]);
    expect($source->fresh()->canRun())->toBeFalse();
});

test('harvest:approve stamps an admin, then enables only on request', function () {
    $admin = User::factory()->admin()->create();
    $source = Source::factory()->create(['key' => 'demo']);

    $this->artisan('harvest:approve', ['key' => 'demo', '--admin-email' => $admin->email])->assertSuccessful();
    expect($source->fresh())->approved_by->toBe($admin->id)->approved_at->not->toBeNull()->enabled->toBeFalse();

    $this->artisan('harvest:approve', ['key' => 'demo', '--admin-email' => $admin->email, '--enable' => true])->assertSuccessful();
    expect($source->fresh()->canRun())->toBeTrue();
});

test('harvest:approve refuses a reader and an excluded source', function () {
    Source::factory()->create(['key' => 'demo']);
    $this->artisan('harvest:approve', ['key' => 'demo', '--admin-email' => User::factory()->create()->email])->assertFailed();
});
```

- [ ] **Step 2: Run to verify it fails.** `vendor/bin/pest tests/Feature/Harvest/SourceGuardTest.php`. Expected: FAIL (no guard, no command).
- [ ] **Step 3: Implement.** `Source::booted()` registers a `saving` hook: `ExcludedSources::reason()` non-null throws `SourceRefused`; `enabled` true while `approved_at` or `approved_by` is null throws `SourceRefused`. `canRun()` rechecks all of it so a raw DB update cannot run a source. The command resolves the admin on the default connection (`is_admin` must be true), requires `terms_checked_at` to be set (the owner records it with the approval: the command sets it to now and prints the vetting fields it approved), and writes `approved_by` (user id), `approved_at`; `--enable` also sets `enabled`. The seeder uses the `harvest` connection and `updateOrCreate` on `key`; it never sets approval.
- [ ] **Step 4: Run to verify it passes.** Same file. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: harvest sources refuse the F4 exclusion list and run only after owner approval"`

### Task 8.3: The adapter contract, Gutenberg and Standard Ebooks adapters, a comics skeleton

**Files:**
- Create: `app/Harvest/Contracts/HarvestAdapter.php`, `app/Harvest/Data/{HarvestBatch,HarvestFileOffer,RightsStatement}.php`, `app/Harvest/Adapters/{GutenbergAdapter,StandardEbooksAdapter,DigitalComicMuseumAdapter}.php`, `app/Catalog/Providers/Gutenberg/GutenbergRights.php`, `tests/Fixtures/harvest/{dcm-feed.json,gutenberg-84.rdf}`
- Modify: `app/Jobs/FetchContentFile.php` (use `GutenbergRights`), `config/bookflix.php` (`harvest.adapters`: `gutenberg`, `standardebooks`, `digitalcomicmuseum`)
- Test: `tests/Feature/Harvest/AdaptersTest.php`; existing `tests/Feature/Reader/FetchContentFileTest.php` must stay green.

**Interfaces:** Produces `HarvestAdapter`: `key(): string`; `fetch(Source $source, ?string $pageToken): HarvestBatch` (`records: list<ProviderRecord>`, `nextPageToken: ?string`, `notModified: bool`, `validators: array{etag?: string, last_modified?: string}`); `rights(ProviderRecord $record): RightsStatement` (`text: ?string`, `evidenceUrl: ?string`, `jurisdiction: ?string`; may do I/O); `fileOffer(ProviderRecord $record): ?HarvestFileOffer` (`url`, `format: ContentFormat`, `expectedSha256: ?string`); `normalize(ProviderRecord $record): NormalizedMedia`; `allowedHosts(): list<string>`; `metadataProvider(): ?MetadataProvider` (the wrapped provider for `IngestionPipeline`, null for skeletons). `GutenbergRights::url(string $id): string` and `GutenbergRights::parse(string $rdf): list<string>` (the pure part of today's private `rights()`, same `ContentFetchRefused` message).

- [ ] **Step 1: Write the failing tests** (`Http::fake()` and `Storage::fake('local')`; the Gutenberg catalogue CSV fixture is written to `catalog-feeds/gutenberg/` the way the existing provider tests do, see `tests/Feature/Catalog/` provider tests for the helper):

```php
test('the Gutenberg adapter delegates to the provider and states the rights from the official record', function () {
    Http::fake(['www.gutenberg.org/cache/epub/84/pg84.rdf' => Http::response(file_get_contents(base_path('tests/Fixtures/harvest/gutenberg-84.rdf')))]);
    $record = new ProviderRecord('gutenberg', '84', ['Title' => 'Frankenstein'], 'https://www.gutenberg.org/ebooks/84', now()->toImmutable());
    $adapter = app(GutenbergAdapter::class);

    expect($adapter->key())->toBe('gutenberg')
        ->and($adapter->fileOffer($record)->url)->toBe('https://www.gutenberg.org/cache/epub/84/pg84-images-3.epub')
        ->and($adapter->rights($record)->text)->toBe('Public domain in the USA.')
        ->and($adapter->allowedHosts())->toBe(config('bookflix.providers.gutenberg.allowed_hosts'))
        ->and($adapter->metadataProvider())->toBeInstanceOf(GutenbergProvider::class);
});

test('the Standard Ebooks adapter offers the feed epub with the entry rights text', function () {
    $record = new ProviderRecord('standardebooks', 'mary-shelley/frankenstein', ['title' => 'Frankenstein', 'rights' => 'Public domain in the US.', 'epub' => 'https://standardebooks.org/ebooks/mary-shelley/frankenstein/downloads/x.epub'], 'https://standardebooks.org/ebooks/mary-shelley/frankenstein', now()->toImmutable());
    $adapter = app(StandardEbooksAdapter::class);

    expect($adapter->rights($record)->text)->toBe('Public domain in the US.')->and($adapter->fileOffer($record)->format)->toBe(ContentFormat::Epub);
});

test('the comics skeleton sends If-None-Match, stores no records on 304 and reads fixture records otherwise', function () {
    $source = Source::factory()->create(['key' => 'digitalcomicmuseum', 'base_url' => 'https://comics.example', 'incremental' => ['etag' => '"v1"'], 'vetting' => ['feed_path' => '/feed.json']]);
    Http::fakeSequence('comics.example/feed.json')->push('', 304)->push(file_get_contents(base_path('tests/Fixtures/harvest/dcm-feed.json')), 200, ['ETag' => '"v2"']);
    $adapter = app(DigitalComicMuseumAdapter::class);

    expect($adapter->fetch($source, null)->notModified)->toBeTrue();
    $batch = $adapter->fetch($source, null);
    expect($batch->records)->toHaveCount(2)->and($batch->validators['etag'])->toBe('"v2"');
    Http::assertSent(fn ($r) => $r->hasHeader('If-None-Match', '"v1"'));
});

test('an adapter never offers a file on a host it does not own', function () {
    $record = new ProviderRecord('digitalcomicmuseum', '1', ['file_url' => 'https://evil.example/a.cbz', 'license' => 'Public domain'], null, now()->toImmutable());
    expect(app(DigitalComicMuseumAdapter::class)->fileOffer($record))->toBeNull();
});
```

`dcm-feed.json` is generated by the implementer: two records `{id, title, creators[], year, license, file_url, format}` with `file_url` on `comics.example`, invented titles, no real publisher.

- [ ] **Step 2: Run to verify it fails.** `vendor/bin/pest tests/Feature/Harvest/AdaptersTest.php`. Expected: FAIL (classes missing).
- [ ] **Step 3: Implement.** Gutenberg and Standard Ebooks adapters inject the existing provider and call `page()`, `normalize()`, `contentFile()`; they add no parsing of their own. `GutenbergAdapter::rights()` fetches `GutenbergRights::url($id)` through the provider's `SendsProviderRequests` pattern (use a small anonymous subclass-free helper: `Http::withUserAgent(config('bookflix.provider_user_agent'))->timeout(30)->retry(0)`), maps 404 to a null statement, and parses with `GutenbergRights::parse`. Refactor `FetchContentFile::rights()` to call `GutenbergRights::parse` and `requirePublicDomain` to use `GutenbergRights::url`; behaviour and messages unchanged. Standard Ebooks: `rights()` returns the entry's `rights` payload text, evidence URL = the record's `sourceUrl`, jurisdiction `US`. The comics adapter: `fetch()` GETs `base_url + vetting['feed_path']` with `If-None-Match`/`If-Modified-Since` from `$source->incremental`, 304 returns `HarvestBatch::notModified`, 200 maps the JSON; `fileOffer()` returns null unless the file host is in `allowedHosts()` (the source host plus `vetting['file_hosts']`); header comment: `// TODO(owner): skeleton for a feed shape that is assumed until vetting finds an official machine-readable feed (P8-R9); delete if there is none.` Run Pint.
- [ ] **Step 4: Run to verify it passes.** `vendor/bin/pest tests/Feature/Harvest/AdaptersTest.php tests/Feature/Reader/FetchContentFileTest.php tests/Feature/Reader/PublicDomainImportTest.php`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: harvest adapter contract with Gutenberg, Standard Ebooks and a comics skeleton"`

### Task 8.4: RunHarvest: incremental runs, good-citizen limits, circuit breaker, work-queue item

**Files:**
- Create: `app/Harvest/Jobs/RunHarvest.php`, `app/Harvest/HarvestScheduler.php` (replaces the Task 8.1 stub), `app/Harvest/Politeness.php`, `app/Harvest/HarvestWorkQueueSource.php`
- Modify: `config/bookflix.php` (`admin_work_queue_sources[] = HarvestWorkQueueSource::class`)
- Test: `tests/Feature/Harvest/RunHarvestTest.php`

**Interfaces:** Consumes `HarvestAdapter`, `Source::canRun()`, `HarvestBatch`. Produces `RunHarvest(int $sourceId)` (`ShouldBeUnique` per source, `onQueue('harvest')`, `backoff(): [60, 120, 240, 480]`, `tries 5`); `Politeness::userAgent(): string` (`Bookflix-Harvester/1.0 (+https://github.com/NightServant/bookflix; contact: <HARVEST_CONTACT_EMAIL>)`, throws `MissingHarvestContact` when unset); `Politeness::attempt(Source $source): bool` (per-source `RateLimiter` at `rate_limit_per_minute`); `HarvestScheduler::dispatchDue(): list<int>` (enabled and `canRun()` sources whose last run started longer ago than `cadence`: `hourly`, `daily`, `weekly`); `HarvestWorkQueueSource::items()` (paused source: `area` `operations`, `severity` `error`, `label` "Harvest paused: {name}", `count` = `consecutive_failures`, `oldestAt` = `paused_at`, `status` `paused`, `ctaLabel` "Open reading files", `ctaUrl` `route('admin.content')`; when the harvest database cannot be queried while enabled, one `error` item "Harvest database unreachable"; empty when `HARVEST_ENABLED` is false).

A run: refuse unless `canRun()`; set the UA (P8-R6); loop `adapter->fetch()` pages, each `Politeness::attempt` first (release the job when throttled); upsert `raw_records` by `(source_id, external_id)` with `checksum = sha256(json(payload sorted))`: unchanged checksum counts nothing, new counts `new`, changed counts `updated`; the payload also gets `normalized.dedupe_key` (Task 8.6). Store `validators` and `since` in `sources.incremental`. New and changed records go to `LicenseGate` and, when allowed, `DownloadHarvestFile` (Tasks 8.5, 8.7), stopping new downloads at `max_files` files or `max_bytes` bytes already stored (counted from `files` rows not failed), recorded as `capped` in the report. Success resets `consecutive_failures`; a failed run (adapter exception after the job's backoff is spent) increments it, and at `breaker_threshold` sets `paused_at`. `harvest_runs.report` holds `{capped, files_in_store, bytes_in_store, projected: {files, bytes, monthly_cost: ?float|null, line: string}}`; `line` reads "Projected at the current rate: N files, X MB; storage price not set" when `storage_price_per_gb_month` is null (no invented price).

- [ ] **Step 1: Write the failing tests** (a fake adapter bound in the test through `config(['bookflix.harvest.adapters.demo' => FakeAdapter::class])`, defined at the top of the test file, returning canned `HarvestBatch`es; `Bus::fake([DownloadHarvestFile::class])`)

```php
test('a run stores new records, skips unchanged ones on the next run, and counts updates', function () {
    $source = approvedSource('demo');
    FakeAdapter::$records = [fakeRecord('1', 'One'), fakeRecord('2', 'Two')];

    (new RunHarvest($source->id))->handle();
    expect(HarvestRun::first()->counts)->toMatchArray(['fetched' => 2, 'new' => 2, 'updated' => 0, 'failed' => 0]);

    FakeAdapter::$records = [fakeRecord('1', 'One'), fakeRecord('2', 'Two, revised')];
    (new RunHarvest($source->id))->handle();
    expect(HarvestRun::latest('id')->first()->counts)->toMatchArray(['fetched' => 2, 'new' => 0, 'updated' => 1]);
});

test('a conditional request that answers not modified stores nothing and keeps the validators', function () {
    $source = approvedSource('demo', ['incremental' => ['etag' => '"v1"']]);
    FakeAdapter::$notModified = true;
    (new RunHarvest($source->id))->handle();
    expect(RawRecord::count())->toBe(0)->and($source->fresh()->incremental['etag'])->toBe('"v1"')->and(HarvestRun::first()->status)->toBe('succeeded');
});

test('an unapproved or excluded source never runs and a missing contact address stops the run', function () {
    $source = Source::factory()->create(['key' => 'demo']);
    (new RunHarvest($source->id))->handle();
    expect(HarvestRun::count())->toBe(0);

    $approved = approvedSource('demo2');
    config(['bookflix.harvest.contact_email' => null]);
    expect(fn () => (new RunHarvest($approved->id))->handle())->toThrow(MissingHarvestContact::class);
});

test('the user agent names Bookflix and the contact address from the environment', function () {
    config(['bookflix.harvest.contact_email' => 'ops@example.test']);
    expect(Politeness::userAgent())->toContain('Bookflix-Harvester')->toContain('ops@example.test');
});

test('five consecutive failures pause the source and raise a work queue item, a success resets the count', function () {
    $source = approvedSource('demo');
    FakeAdapter::$throws = new ProviderUnavailable('down');

    foreach (range(1, 4) as $_) { (new RunHarvest($source->id))->failed(new ProviderUnavailable('down')); }
    expect($source->fresh())->consecutive_failures->toBe(4)->paused_at->toBeNull();
    (new RunHarvest($source->id))->failed(new ProviderUnavailable('down'));
    expect($source->fresh()->paused_at)->not->toBeNull()->and($source->fresh()->canRun())->toBeFalse();

    $item = collect((new HarvestWorkQueueSource)->items())->first();
    expect($item->label)->toBe('Harvest paused: '.$source->name)->and($item->severity)->toBe('error')->and($item->count)->toBe(5);
});

test('the file cap stops downloads and the report carries a projected cost line', function () {
    $source = approvedSource('demo', ['max_files' => 1]);
    FakeAdapter::$records = [fakeRecord('1', 'One'), fakeRecord('2', 'Two')];
    (new RunHarvest($source->id))->handle();

    Bus::assertDispatchedTimes(DownloadHarvestFile::class, 1);
    expect(HarvestRun::first()->report)->capped->toBeTrue()->and(HarvestRun::first()->report['projected']['line'])->toContain('storage price not set');
});

test('the scheduler dispatches only approved, enabled, unpaused sources that are due', function () {
    Bus::fake([RunHarvest::class]);
    approvedSource('due');
    Source::factory()->create(['key' => 'unapproved']);
    approvedSource('paused', ['paused_at' => now()]);

    expect(HarvestScheduler::dispatchDue())->toHaveCount(1);
});
```

`approvedSource()`, `fakeRecord()` and `FakeAdapter` are file-local helpers: `approvedSource` uses `Source::factory()->approved()` (state added to the factory in Task 8.1/8.2: sets `approved_by`, `approved_at`, `terms_checked_at`, `enabled`).

- [ ] **Step 2: Run to verify it fails.** `vendor/bin/pest tests/Feature/Harvest/RunHarvestTest.php`. Expected: FAIL (job missing).
- [ ] **Step 3: Implement** as described above. Use `RateLimiter` (array cache in tests) for `Politeness::attempt`; exponential backoff is the job's `backoff()`; `failed(Throwable)` is where the breaker counts, so one run that exhausts its tries counts once. `// ponytail: global user-agent config swap, per-request agent if the harvest queue ever runs other jobs` on the P8-R6 line. Run Pint.
- [ ] **Step 4: Run to verify it passes.** Same file plus `vendor/bin/pest tests/Feature/Harvest/HarvestSchemaTest.php`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: RunHarvest with incremental fetch, rate limit, backoff, caps and a circuit breaker"`

### Task 8.5: LicenseGate, safe download, file scanning and quarantine

**Files:**
- Create: `app/Harvest/LicenseGate.php`, `app/Harvest/Contracts/FileScanner.php`, `app/Harvest/Scanning/{NullScanner,ScanResult}.php`, `app/Harvest/Jobs/DownloadHarvestFile.php`, `app/Reader/SafeDownload.php`
- Modify: `app/Jobs/FetchContentFile.php` (delegate `download()` and `send()` to `SafeDownload`), `app/Providers/AppServiceProvider.php` (bind `FileScanner` to `NullScanner`), `config/bookflix.php` (`harvest.license_policies`), `app/Harvest/Jobs/RunHarvest.php` (call the gate for new and changed records)
- Test: `tests/Feature/Harvest/LicenseGateTest.php`, `tests/Feature/Harvest/DownloadHarvestFileTest.php`

**Interfaces:** Produces `LicenseGate::assess(RawRecord $record): LicenseEvidence` (creates or updates the one evidence row: `license`, `rights_statement`, `evidence_url`, `jurisdiction`, `auto_assessment` = `LicenseVerdict` value, `decision` `pending`; sets the record `state` to `queued` (allowed), `needs_review` (review) or `blocked`; dispatches `DownloadHarvestFile` only for `allowed`). `FileScanner::scan(string $path): ScanResult` (enum `Clean`, `Infected`, `NotScanned`). `SafeDownload::toFile(string $url, string $temp, int $maxBytes, string $userAgent): void` and `SafeDownload::send(callable $request): Response` (the current private `download()` and `send()` bodies moved, behaviour and messages unchanged: host check stays with the caller, no redirects, size caps from headers and progress, 429 and 5xx become `ProviderUnavailable`).

`config('bookflix.harvest.license_policies')`: keyed by `sources.license_policy`, each `{allow: list<regex>, block: list<regex>, jurisdictions: list<string>}`: `us_public_domain` allows `/Public domain in the USA/i` (Gutenberg's own wording, copied from `FetchContentFile`) and `/Public domain in the US\b/i`, blocks `/copyrighted|all rights reserved/i`, jurisdictions `['US']`; `cc_by_or_public_domain` allows `/CC[ -]BY(?![ -]N)|Creative Commons Attribution|public domain/i`, blocks `/NC|NonCommercial|ND/i` (a non-commercial licence needs the owner's call: it goes to review, so it is also listed in `review` by the rule below). Rule: statement null or empty, no allow match, any block match, a jurisdiction not in the policy list, or a policy key with no config entry all give `Review` (blocked is only for an explicit block match on a source whose policy lists it); never `Allowed` by default.

- [ ] **Step 1: Write the failing tests**

```php
test('an ambiguous, empty or unknown rights statement is never downloaded', function (?string $statement) {
    Http::fake();
    $record = recordWithRights($statement);

    $evidence = app(LicenseGate::class)->assess($record);

    expect($evidence->auto_assessment)->toBe(LicenseVerdict::Review)->and($record->fresh()->state)->toBe(RecordState::NeedsReview);
    Http::assertNothingSent();
    Bus::assertNotDispatched(DownloadHarvestFile::class);
})->with([null, '', 'Some rights reserved', 'Licensed to the public, see page', 'Public domain in Canada']);

test('a matching statement is allowed and queues the download, a copyright statement is blocked', function () {
    $allowed = app(LicenseGate::class)->assess(recordWithRights('Public domain in the USA.'));
    expect($allowed->auto_assessment)->toBe(LicenseVerdict::Allowed);
    Bus::assertDispatched(DownloadHarvestFile::class);

    $blocked = app(LicenseGate::class)->assess(recordWithRights('Copyrighted. Read the copyright notice inside the book.'));
    expect($blocked->auto_assessment)->toBe(LicenseVerdict::Blocked);
});

test('RunHarvest never fetches the file of a record the gate sends to review', function () {
    // fake adapter returns a record with rights null and a file offer; run, then:
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'file.example'));
});
```

```php
// DownloadHarvestFileTest.php: Storage::fake('harvest-raw'); Http::fake(['library.example/*' => Http::response(tinyEpub(), 200)]);
test('a valid epub is stored with its checksum, detected type and an honest not-scanned flag', function () {
    $file = queuedFile('https://library.example/a.epub');
    (new DownloadHarvestFile($file->raw_record_id))->handle(app(FileScanner::class));
    $file->refresh();
    expect($file)->status->toBe(FileStatus::Downloaded)->sha256->toBe(hash('sha256', tinyEpub()))->detected_mime->toBe('application/epub+zip')->scan_status->toBe('not_scanned')->scanned_at->toBeNull();
    Storage::disk('harvest-raw')->assertExists($file->storage_key);
});

test('a bad file is quarantined, kept and never ready', function (string $bytes, string $reason) {
    Http::fake(['library.example/*' => Http::response($bytes, 200)]);
    $file = queuedFile('https://library.example/a.epub');
    (new DownloadHarvestFile($file->raw_record_id))->handle(app(FileScanner::class));
    $file->refresh();
    expect($file->status)->toBe(FileStatus::Quarantined)->and($file->failure_reason)->toContain($reason)->and($file->rawRecord->state)->toBe(RecordState::Quarantined);
    Storage::disk('harvest-raw')->assertExists($file->storage_key);
    expect($file->storage_key)->toStartWith('quarantine/');
})->with([
    'html renamed to epub' => ['<html>not a book</html>', 'not an EPUB'],
    'zip slip' => [zipWithEntry('../evil.txt'), 'unsafe'],
    'zip that is not an epub' => [zipWithEntry('a.txt'), 'valid EPUB'],
]);

test('an infected file is quarantined', function () {
    app()->bind(FileScanner::class, fn () => new class implements FileScanner { public function scan(string $path): ScanResult { return ScanResult::Infected; } });
    // same flow; assert Quarantined, failure_reason contains 'malware'.
});

test('a file on a host the adapter does not own, over the size cap, or at a 404 fails without being kept', function () {
    // three cases: evil.example host; Content-Length above bookflix.harvest.max_file_mb; 404. Each: FileStatus::Failed, storage_key null, no quarantine file, record state needs_review? no: state stays queued with failure_reason set.
});
```

Helpers (`recordWithRights`, `queuedFile`, `zipWithEntry`) are file-local, built from the factories; `zipWithEntry()` follows `tests/Feature/Reader/ZipGuardTest.php`.

- [ ] **Step 2: Run to verify it fails.** `vendor/bin/pest tests/Feature/Harvest/LicenseGateTest.php tests/Feature/Harvest/DownloadHarvestFileTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** `DownloadHarvestFile` (`onQueue('harvest')`, unique per record): re-check the file host against `adapter->allowedHosts()` and `ExcludedSources`, https only, then `SafeDownload::toFile` into a temp file; refusals (`ContentFetchRefused`, host, size, 404) set `files.status=failed` and `failure_reason`, store nothing. Then detect type by bytes with `finfo` (not the name or `Content-Type`), run `EpubInspector`/`ZipGuard` for epub and cbz and a `%PDF` check for pdf, compute `sha256`, compare `expectedSha256` when the offer has one, and run the injected `FileScanner`. Any failure at this stage (wrong type, `UnsafeArchive`, checksum mismatch, `Infected`) moves the bytes to `quarantine/{ulid}` on `harvest-raw` and sets `quarantined`, the record `quarantined`; nothing is ever deleted. A good file goes to `raw/{source_key}/{ulid}.{ext}`; `NotScanned` leaves `scanned_at` null and `scan_status=not_scanned` (D2-12); `Clean` sets both. Dedupe and enrichment follow in Task 8.6 (call `HarvestPipeline::afterDownload($record)` there, stubbed to set `state=downloaded` now). Run Pint.
- [ ] **Step 4: Run to verify it passes.** Both files plus `vendor/bin/pest tests/Feature/Reader/FetchContentFileTest.php tests/Feature/Harvest/RunHarvestTest.php`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: licence gate, safe download with type-by-bytes, scanning seam and quarantine"`

### Task 8.6: Dedupe against harvest and production, enrichment

**Files:**
- Create: `app/Harvest/HarvestDeduplicator.php`, `app/Harvest/HarvestEnricher.php`, `app/Harvest/HarvestPipeline.php`
- Modify: `app/Harvest/Jobs/RunHarvest.php` (write `payload.normalized` with `dedupe_key`, `isbns`, `title`, `creators`), `app/Harvest/Jobs/DownloadHarvestFile.php` (call `HarvestPipeline::afterDownload`)
- Test: `tests/Feature/Harvest/HarvestDedupeTest.php`

**Interfaces:** Produces `HarvestDeduplicator::check(RawRecord $record): ?DuplicateOf` (`DuplicateOf` is a readonly value: `kind` `checksum|identifier|title_author`, `side` `harvest|production`, `reference` string) and `HarvestEnricher::enrich(RawRecord $record): void` (adds `payload.enriched` from metadata-only providers already in the production catalogue: description and cover URL only, read from `Media` found by `Deduplicator::match`, no HTTP); `HarvestPipeline::afterDownload(RawRecord $record): void` (dedupe, then enrich, then `state` `duplicate` or `ready`). Dedupe key: `Str::ascii`, lowercase, punctuation stripped, single-spaced title plus `|` plus the first creator's last word. Harvest side queries only the `harvest` connection (checksum on `files.sha256` of another record, `dedupe_key` through the functional index, ISBN inside `payload.normalized.isbns`); production side calls `Deduplicator::match(NormalizedMedia)` on the default connection and the two answers are combined in PHP.

- [ ] **Step 1: Write the failing tests**

```php
test('the same bytes under another title are a checksum duplicate', function () {
    $first = readyRecord('One', sha: str_repeat('a', 64));
    $second = recordWithFile('Other title', sha: str_repeat('a', 64));
    expect(app(HarvestDeduplicator::class)->check($second))->toMatchObject(['kind' => 'checksum', 'side' => 'harvest', 'reference' => (string) $first->id]);
});

test('the same normalized title and author across two sources are a duplicate', function () {
    readyRecord('Frankenstein; or, The Modern Prometheus', creators: ['Mary Shelley']);
    $other = recordWithFile('Frankenstein or The Modern Prometheus!', creators: ['Mary W. Shelley']);
    expect(app(HarvestDeduplicator::class)->check($other)->kind)->toBe('title_author');
});

test('a work already in production is found through the production deduplicator, with no cross-connection query', function () {
    $media = Media::factory()->create(['title' => 'Dracula']);
    // creator + identifier set up the way tests/Feature/Catalog/DeduplicatorTest.php does for an ISBN match
    $record = recordWithFile('Dracula', isbns: [$isbn]);
    DB::connection('harvest')->enableQueryLog(); DB::enableQueryLog();
    $verdict = app(HarvestDeduplicator::class)->check($record);
    expect($verdict)->toMatchObject(['side' => 'production', 'reference' => (string) $media->id]);
    expect(collect(DB::connection('harvest')->getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, '"media"')))->toBeEmpty();
});

test('a unique record becomes ready and a duplicate never does', function () {
    $unique = recordWithFile('A Brand New Tale');
    HarvestPipeline::afterDownload($unique);
    expect($unique->fresh()->state)->toBe(RecordState::Ready);

    $dupe = recordWithFile('A Brand New Tale');
    HarvestPipeline::afterDownload($dupe);
    expect($dupe->fresh()->state)->toBe(RecordState::Duplicate);
});

test('enrichment copies only a description and a cover url from the matching production story', function () {
    // production Media with a synopsis; harvest record for it; enrich; payload.enriched has exactly keys description, cover_url; no HTTP (Http::assertNothingSent()).
});
```

- [ ] **Step 2: Run to verify it fails.** `vendor/bin/pest tests/Feature/Harvest/HarvestDedupeTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** as specified; `readyRecord`, `recordWithFile` are file-local factory helpers. Run Pint.
- [ ] **Step 4: Run to verify it passes.** That file plus `vendor/bin/pest tests/Feature/Catalog/DeduplicatorTest.php tests/Feature/Harvest`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: dedupe harvested records against harvest and production, and enrich from the catalogue"`

### Task 8.7: Internal API, HarvestApi and promotion

**Files:**
- Create: `routes/internal.php`, `app/Http/Middleware/VerifyHarvestToken.php`, `app/Http/Controllers/Internal/HarvestController.php`, `app/Harvest/{ReadyQuery,ReadyItem,HarvestApi,AutoApproval}.php`, `app/Harvest/Jobs/PromoteRecord.php`
- Modify: `bootstrap/app.php` (`withRouting(then: ...)` mounting `routes/internal.php` under group `api`, prefix `internal/harvest`, name `internal.harvest.`, middleware `harvest.token` and `throttle:60,1`; alias `harvest.token`)
- Test: `tests/Feature/Harvest/InternalApiTest.php`, `tests/Feature/Harvest/PromoteRecordTest.php`

**Interfaces:** Produces `GET internal.harvest.ready` returning `{ready: ReadyItem[], review: ReadyItem[]}` (`ReadyItem` fields: `record_id`, `title`, `creators`, `source`, `format`, `bytes`, `sha256`, `scan_status`, `license`, `rights_statement`, `evidence_url`, `jurisdiction`, `auto_assessment`); `POST internal.harvest.promote` `{record, admin_id}` queues `PromoteRecord`; `POST internal.harvest.decide` `{record, decision: approved|rejected, admin_id}` (P8-R5; approval queues `DownloadHarvestFile`). `HarvestApi::ready(): array{ready: list<ReadyItem>, review: list<ReadyItem>}`, `promote(int $recordId, int $adminId): void`, `decide(int $recordId, string $decision, int $adminId): void` (in process via `ReadyQuery` and the jobs when `api_url` is null, over HTTP with the bearer token otherwise). `AutoApproval::allows(RawRecord $record): bool`. `PromoteRecord(int $rawRecordId, int $adminId)`.

- [ ] **Step 1: Write the failing tests**

```php
test('the internal api refuses a missing, wrong or unconfigured token', function (?string $sent, ?string $configured, int $status) {
    config(['bookflix.harvest.service_token' => $configured]);
    $headers = $sent === null ? [] : ['Authorization' => "Bearer {$sent}"];
    $this->getJson(route('internal.harvest.ready'), $headers)->assertStatus($status);
    $this->postJson(route('internal.harvest.promote'), ['record' => 1, 'admin_id' => 1], $headers)->assertStatus($status);
})->with([
    'no token' => [null, 'right-token-value', 401],
    'wrong token' => ['wrong-token-value', 'right-token-value', 401],
    'server has no token' => ['anything', null, 503],
]);

test('the right token lists ready and review items with licence evidence and the scan state', function () {
    config(['bookflix.harvest.service_token' => 'right-token-value']);
    $ready = readyRecord('Frankenstein');   // state ready, evidence 'Public domain in the USA.', file scan_status not_scanned
    $this->getJson(route('internal.harvest.ready'), ['Authorization' => 'Bearer right-token-value'])
        ->assertOk()->assertJsonPath('ready.0.record_id', $ready->id)->assertJsonPath('ready.0.scan_status', 'not_scanned')->assertJsonPath('ready.0.rights_statement', 'Public domain in the USA.');
});

test('the token is compared in constant time', function () {
    expect(file_get_contents(app_path('Http/Middleware/VerifyHarvestToken.php')))->toContain('hash_equals');
});

test('promote queues a job, decide approves a review item and queues its download', function () {
    Bus::fake(); // assert PromoteRecord dispatched with the admin id; decide approved sets decision, reviewer, decided_at and dispatches DownloadHarvestFile; rejected sets state blocked and dispatches nothing.
});
```

```php
// PromoteRecordTest.php: Storage::fake('harvest-raw'); Storage::fake(ContentSource::disk()); app(MediaTypeSeeder::class)->run();
test('promotion copies the file to the production disk and creates a verified reading file and a promotion row', function () {
    $record = readyRecord('Frankenstein', adapter: 'gutenberg');
    (new PromoteRecord($record->id, $admin->id))->handle(app(IngestionPipeline::class));

    $source = ContentSource::where('external_key', 'gutenberg:'.$record->external_id)->firstOrFail();
    expect($source)->status->toBe(ContentStatus::Ready)->verified_by->toBe($admin->id)->license->toContain('Public domain')->checksum->toBe($record->file->sha256);
    Storage::disk(ContentSource::disk())->assertExists($source->storage_path);
    Storage::disk('harvest-raw')->assertExists($record->file->storage_key);      // the harvest copy stays
    expect(Promotion::where('raw_record_id', $record->id)->value('bookflix_media_id'))->toBe($source->media_id)->and($record->fresh()->state)->toBe(RecordState::Promoted);
});

test('promoting twice, or a record that is not ready, changes nothing', function () {
    // second handle(): still one ContentSource and one Promotion; a Quarantined or NeedsReview record: ContentFetchRefused thrown, no media, no file on the production disk.
});

test('auto approval is off by default, and never publishes an unscanned file even when switched on', function () {
    $record = readyRecord('Frankenstein');                       // scan_status not_scanned
    expect(config('bookflix.harvest.auto_approve'))->toBeFalse()->and(AutoApproval::allows($record))->toBeFalse();
    config(['bookflix.harvest.auto_approve' => true]);
    expect(AutoApproval::allows($record))->toBeFalse();          // D2-12
    $record->file->update(['scan_status' => 'clean', 'scanned_at' => now()]);
    expect(AutoApproval::allows($record->fresh()))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails.** `vendor/bin/pest tests/Feature/Harvest/InternalApiTest.php tests/Feature/Harvest/PromoteRecordTest.php`. Expected: FAIL (routes missing).
- [ ] **Step 3: Implement.** The middleware answers 503 when the configured token is empty, else 401 unless `hash_equals($configured, (string) $request->bearerToken())`. `PromoteRecord` (queue `harvest`, unique per record): refuse unless the record is `ready` with a `downloaded` file; map the stored payload back to a `ProviderRecord` and run `IngestionPipeline::import()` with `adapter->metadataProvider()` (skeleton adapters without a provider are refused with a plain message), find the media through `MediaExternalId`, call `RecordContentFile::handle()` with the adapter's offer, then stream the file from `harvest-raw` to `content/pd/{ulid}.{ext}` on `ContentSource::disk()` and `forceFill` `storage_path`, `byte_size`, `checksum`, `mime_type`, `status=ready`, `verified_by`, `verified_at`, `license` from the evidence, `jurisdiction` from the evidence (Phase 7 column); write the `Promotion` row last, inside the one place that touches both connections. `AutoApproval::allows`: config true, `auto_assessment` allowed, `scan_status` clean; when it allows, `HarvestPipeline::afterDownload` dispatches `PromoteRecord` with `admin_id` null-safe (`0` means system; the Reading files screen labels it). Run Pint.
- [ ] **Step 4: Run to verify it passes.** Both files plus `vendor/bin/pest tests/Feature/Reader/AdminContentVerificationTest.php tests/Feature/Harvest`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: token-guarded internal harvest API and the promotion job"`

### Task 8.8: Reading files shows harvest items; F9 display check

**Files:**
- Modify: `resources/views/pages/admin/content/⚡index.blade.php` (a section above the table), `resources/views/components/⚡story-reviews.blade.php` (outside rating row), `tests/Feature/Social/StoryReviewsTest.php` (the `'4.1 / 5'` assertion)
- Test: `tests/Feature/Harvest/ReadingFilesHarvestTest.php`, `tests/Feature/Social/StoryReviewsTest.php`, `tests/Feature/Admin/AdminAccessTest.php` (add the three new actions to the demoted-admin dataset)

**Interfaces:** Consumes `HarvestApi`. Component actions `verifyHarvested(int $recordId)`, `allowDownload(int $recordId)`, `rejectHarvested(int $recordId)`, each `Gate::authorize('access-admin')` first; record ids are not stored in public properties. The section renders only when `config('bookflix.harvest.enabled')` is true; each row has `data-test="harvest-{id}"`, the licence text and evidence link (`rel="noopener noreferrer"`, https only), jurisdiction, and the badge text "Not scanned" when `scan_status` is `not_scanned`. Use existing `x-admin-table`/`x-admin-empty`/`x-cta` components; Phase 5 restyles them later without touching this contract.

- [ ] **Step 1: Write the failing tests**

```php
test('the harvest section is hidden while the harvester is off', function () {
    Livewire::actingAs(User::factory()->admin()->create())->test('pages::admin.content.index')->assertDontSeeHtml('data-section="harvest-ready"');
});

test('ready items show licence evidence, jurisdiction and a not-scanned badge, and Verify promotes', function () {
    config(['bookflix.harvest.enabled' => true]);
    Bus::fake([PromoteRecord::class]);
    $record = readyRecord('Frankenstein');
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('pages::admin.content.index')
        ->assertSee('Frankenstein')->assertSee('Public domain in the USA.')->assertSee('Not scanned')->assertSee('US')
        ->call('verifyHarvested', $record->id);

    Bus::assertDispatched(PromoteRecord::class, fn ($job) => $job->rawRecordId === $record->id && $job->adminId === $admin->id);
});

test('review items can be allowed or rejected, and a reader cannot', function () {
    config(['bookflix.harvest.enabled' => true]);
    $record = reviewRecord('Unclear Rights');
    Livewire::actingAs(User::factory()->create())->test('pages::admin.content.index')->assertForbidden();
    Livewire::actingAs(User::factory()->admin()->create())->test('pages::admin.content.index')->assertSee('Unclear Rights')->call('rejectHarvested', $record->id);
    expect($record->fresh()->state)->toBe(RecordState::Blocked);
});

test('with the harvest database unreachable the page still loads and says so', function () {
    config(['bookflix.harvest.enabled' => true, 'database.connections.harvest.database' => 'does_not_exist']);
    DB::purge('harvest');
    Livewire::actingAs(User::factory()->admin()->create())->test('pages::admin.content.index')->assertOk()->assertSee('The harvest database could not be reached.');
});
```

```php
// StoryReviewsTest.php, F9 (replace the '4.1 / 5' line and add):
test('an outside rating reads "4.1 on Open Library (2,345 ratings)", links to the source and stays out of the reader reviews', function () {
    ExternalSource::query()->updateOrCreate(['key' => 'openlibrary'], ExternalSource::factory()->make(['key' => 'openlibrary', 'name' => 'Open Library'])->getAttributes());
    ExternalRating::factory()->create(['media_id' => $this->media->id, 'source' => 'openlibrary', 'score' => 82, 'votes' => 2345, 'url' => 'https://openlibrary.org/works/OL1W']);
    $this->actingAs($this->reader);

    $html = storyReviews($this->media)->assertSee('4.1 on Open Library (2,345 ratings)')->html();
    expect(htmlQuery($html, '//aside[@aria-label="Ratings"]//a[@href="https://openlibrary.org/works/OL1W"][@rel="noopener noreferrer nofollow ugc"]'))->toHaveCount(1)
        ->and(htmlQuery($html, '//*[@data-section="reader-reviews"]//*[contains(., "on Open Library")]'))->toHaveCount(0);
});
```

If the reader-review list has no `data-section="reader-reviews"` attribute today, add it in the same edit (it is a hook, not a visual change). `config('bookflix.reviews.outside_enabled')` is not touched; the existing "off by default" test in `OutsideReviewsTest` must still pass.

- [ ] **Step 2: Run to verify it fails.** `vendor/bin/pest tests/Feature/Harvest/ReadingFilesHarvestTest.php tests/Feature/Social/StoryReviewsTest.php`. Expected: FAIL (section missing; rating still "4.1 / 5").
- [ ] **Step 3: Implement.** Screen: computed `harvest()` calls `HarvestApi::ready()` inside try/catch for the query exception, rendering the unreachable message instead of a 500; actions call `HarvestApi` and toast via `Flux::toast` like the existing `save()`. Story reviews: in the outside rating row render `{{ __(':value on :source', ...) }}` and `({{ trans_choice(':count rating|:count ratings', ...) }})` as one line, keep the `/ max` scale as `title`/sr-only text only for sources whose scale is not 5 (`/ 100` for AniList stays visible: "72 / 100 on AniList (2,301 ratings)"); update the old assertions in the same test to the new lines.
- [ ] **Step 4: Run to verify it passes.** The two files, `vendor/bin/pest tests/Feature/Admin tests/Feature/Reviews tests/Feature/Components/RatingDisplayTest.php`. Expected: PASS. Then `composer test` (not this task's gate, the phase's).
- [ ] **Step 5: Commit** in two commits: `git commit -m "feat: Reading files lists harvested items with licence evidence and a not-scanned badge"` and `git commit -m "fix: outside ratings read as '4.1 on Open Library (2,345 ratings)' with a source link"`

### Task 8.9: Vetting notes and the owner's pilot runbook

**Files:**
- Create: `docs/superpowers/plans/mp2-phase-8-vetting.md`, `docs/superpowers/plans/mp2-phase-8-pilot-runbook.md`
- Test: `tests/Feature/Harvest/VettingNotesTest.php`

**Interfaces:** none. The notes are documentation the brief explicitly requests (F3 "vetting notes"); no website, robots file or terms page is fetched by the agent: every field the agent cannot know from the brief says `unknown, owner to check`.

- [ ] **Step 1: Write the failing test**

```php
test('every F3 and fmhy candidate has a vetting section, all pending owner approval', function (string $candidate) {
    $notes = file_get_contents(base_path('docs/superpowers/plans/mp2-phase-8-vetting.md'));
    expect($notes)->toContain("## {$candidate}");
    $section = str($notes)->after("## {$candidate}")->before("\n## ")->toString();
    foreach (['Official site', 'Machine-readable licence', 'Automation allowed', 'Jurisdictions', 'Owner approval'] as $field) {
        expect($section)->toContain("**{$field}:**");
    }
    expect($section)->toContain('Owner approval:** pending owner approval')->not->toContain('approved on');
})->with([
    'Project Gutenberg', 'Standard Ebooks', 'Wikisource', 'Internet Archive', 'Faded Page', 'Unglue.it', 'OAPEN', 'OpenStax', 'LibreTexts',
    'Open Textbook Library', 'Wikibooks', 'Comic Book Plus', 'Digital Comic Museum', 'LibriVox', 'DPLA', 'Open Library', 'BookBrainz', 'ISFDB',
    'MangaUpdates', 'Baen Free Library', 'ManyBooks', 'Loyal Books', 'FreeBannedBooks', 'By the Fireplace', 'DigiLibraries', 'ExClassics',
    'Free-Programming-Books', 'Pepper&Carrot',
]);

test('the runbook never enables, approves or runs anything for the owner by itself', function () {
    $runbook = file_get_contents(base_path('docs/superpowers/plans/mp2-phase-8-pilot-runbook.md'));
    expect($runbook)->toContain('harvest:approve')->toContain('HARVEST_ENABLED=true')->toContain('Owner approval')->toContain('stop');
});
```

- [ ] **Step 2: Run to verify it fails.** `vendor/bin/pest tests/Feature/Harvest/VettingNotesTest.php`. Expected: FAIL (files missing).
- [ ] **Step 3: Write the two documents.** Vetting notes: one `## <Name>` section per candidate with the five bold fields plus **Conditions from the brief** (copied from F3: for example Gutenberg "official catalogue files and mirrors, never crawl the site", Faded Page "public domain in Canada, gate by jurisdiction or skip", Internet Archive "public domain or openly licensed items only, never controlled lending", NC licences "only if Bookflix stays non-commercial", metadata-only sources "no files"), the brief's fmhy candidates flagged "vet per title, not per site", and **Role**: adapter, metadata only, or vetting note only. Every `Owner approval` line reads `pending owner approval`. The two comics sources add a line "pilot candidate; if neither allows automated access, the pilot is Gutenberg and comics become admin-run manual imports (F5)". Runbook (for the owner, in order): create the databases (`createdb bookflix_harvest`), set the env names from Global Constraints (contact address, service token generated by the owner, never pasted into chat), `php artisan migrate --database=harvest --path=database/migrations/harvest`, `php artisan db:seed --class=HarvestSourceSeeder`, read the vetting note, then `php artisan harvest:approve gutenberg --admin-email=<owner address> --enable`, set `HARVEST_ENABLED=true`, run `php artisan horizon` (supervisor `harvest`), cap the pilot at 50 titles (`sources.max_files`), then stop and read the run report (counts, failures, duplicates, classification results, projected cost) before anything else; no promotion, extra source, larger batch or deployment without the owner's written approval. State plainly that the agent has not run any of it.
- [ ] **Step 4: Run to verify it passes.** The file, then `vendor/bin/pest tests/Feature/Harvest`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "docs: harvest source vetting notes and the owner's pilot runbook"`

## Phase exit

- `composer test` green (it needs `bookflix_harvest_test`; CI step added in Task 8.1). Browser files: none touched; run `vendor/bin/pest tests/Browser/AdminShellTest.php` because the Reading files page changed.
- `/security-review` focused on: token comparison, deny-list bypass (subdomain, case, trailing dot), SSRF through file URLs (host allow-list, https only, no redirects), zip handling, anything written to the production connection from `harvest` jobs.
- Whole-branch review on the most capable model; one fix wave; ledger records the baseline re-check, rulings P8-R1..R9 with costs, the commit range per task, and every `TODO(owner)` left.
- PR body = Part I report table (Item | Status | What changed (files) | Notes) with a row for every item: F3 approved-source adapters (Fixed: Gutenberg, Standard Ebooks; others Needs decision pending vetting), F3 vetting checklist and notes (Fixed: `mp2-phase-8-vetting.md`), F3 fmhy candidates (Fixed as notes only), F3 metadata-only sources (Already OK: existing providers), F4 deny-list (Fixed: `config/bookflix.php`, `Source.php`, `ExcludedSources.php`), F5 infrastructure (Fixed, local-first per D2-2; separate Neon project and bucket Needs decision), F5 schema (Fixed), F5 workflow stages (Fixed, one row per stage), F5 good citizen (Fixed; robots-policy reading Needs decision: noted per source in vetting), F5 rollout (Needs decision: owner pilot, nothing run), F5 auto-approve (Already OK: off by default), F5 malware scan (Needs decision: no scanner approved, D2-12), F9 display (Fixed per P8-R7), F9 off switch (Already OK), Part I harvester tests (Fixed). Open questions: which pilot source, ClamAV approval, bucket and Neon project, who approves each source. PR rules: masterplan Part G, no `Co-Authored-By`, no AI attribution, never merged by the agent; if Phase 7 is still open, branch from its tip and open the PR after it merges (D2-24 style stacking).
