# Phase 9: Official platforms and creator licensing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a reader follow series that live on official platforms and jump to the right episode with one tap, and let admins host licensed creator work that the reader only opens while a licence is active and covers the reader's country.

**Architecture:** A config-driven `PlatformRegistry` (13 platforms, all `confirmed => false` until a human checks each) feeds `platform_links` (per story) and `user_follows` (per reader). Following puts the story in the reader's library, so Library and Continue Reading mix hosted, uploaded and followed titles with one label helper. Licensing is one gate (`LicenseGate`) called from the existing `ContentAccessGate`, with admin-only licence records, a daily expiry job and a pre-expiry alert.

**Tech Stack:** Laravel 13, Livewire 4 single-file pages, Flux free, Pest 5 (Feature + browser), Postgres, Laravel database notifications + mail, PHP `intl` (already loaded) for country names. No new dependency.

**Spec:** `docs/superpowers/specs/2026-11-26-cleanup-brief.md` sections F7, F8, the Part E bullet "Followed series on other platforms (F7)", and the Part I "Licensing and platform tests" bullet; masterplan `docs/superpowers/plans/2026-11-26-bookflix-masterplan-2.md` Part F "Phase 9" and F.0 registry row 9.

**Branch:** `mp2-phase-9-platforms-licensing` · **Ledger:** `docs/superpowers/plans/mp2-phase-9-ledger.md` · **Depends on:** Phase 3 (legal sections, crisis-resource region), Phase 4 (`settings.edit`, `x-settings.section`, `x-settings.summary-card`, `settingToggle`), Phase 5 (`x-admin.tabs`, `AdminTable`, `WorkQueueSource`, `WorkQueueItem`), Phase 8 (none directly; read its `ContentSource` changes in the baseline re-check).

## Global Constraints

Every Global Constraint in the masterplan applies. Phase-specific:

- Platform names render as plain text only. No logos, no platform brand colours.
- Registry: exactly 13 platforms, keys `webtoon`, `tapas`, `tappytoon`, `lezhin`, `manta`, `manga-plus`, `shonen-jump`, `k-manga`, `comikey`, `globalcomix`, `marvel-unlimited`, `dc-universe-infinite`, `kindle-comixology`; every one ships `confirmed => false`. Flipping one needs a ledger entry (Task 9.1 Step 3b) and the owner's say-so.
- External links: `target="_blank" rel="noopener noreferrer"`, `https` URLs only, host must match the platform's `url_patterns`.
- Never scrape a platform page. New-episode data comes only from `MangaUpdates` and `AniList` payloads through the existing providers, or an official feed URL set in config (none are set today).
- Email only for readers with `profiles.email_new_episodes = true` (default false). In-app notifications need only the per-follow `notify` flag (default true).
- Labels, exactly: `Reads in Bookflix`, `Your file`, `On {Platform}`. Return prompt copy: `Did you finish Episode {n}?`. Link copy: `Continue on {Platform}, Episode {n}`.
- Country: `profiles.country`, ISO 3166-1 alpha-2, uppercase, nullable. Unknown country sees worldwide licences only (D2-21). No IP geolocation.
- Licence dates are calendar dates compared in `config('app.timezone')`. Expiry warning window `bookflix.licensing.warn_days` (env `BOOKFLIX_LICENSE_WARN_DAYS`, default 21).
- Agreement files live on a private disk `licenses` (local driver, `storage/app/private/licenses`), never `public`, never served by URL; admins open them through a signed, admin-only route.
- No contract, revenue-term or legal text is drafted anywhere. `revenue_terms` is a free-text field the owner fills. Page copy that needs legal wording is `TODO(owner)`.
- No real contact detail, no real person, no secret in code, tests or fixtures. Test emails use `@example.com`.

## Review Focus

1. **The return prompt fires in the same click that opened the link, or twice, or for another reader's follow.** Opening a platform tab must not show "Did you finish Episode 43?" in the original tab at once, answering must clear it (asked once), and a forged follow id from another account must change nothing. → Task 9.3.
2. **An unconfirmed platform leaks onto a page** (story card, follow button, Library label, notification) because one view reads the table instead of the registry. → Task 9.1 (registry) and Task 9.2 (story page).
3. **A licensed file stays readable after expiry, or in a country the licence doesn't cover**, through a path that doesn't call the gate (an already-open stream URL, a source with two licences, a reader with no country). → Task 9.7.
4. **The expiry job unpublishes a work another active licence still covers, or alerts every night instead of once.** → Task 9.8.
5. **Notifications reach the wrong people**: a follower who turned notify off, an email to someone who never opted in, or a flood of notices for episodes the reader already read. → Task 9.5.

## Cross-phase interfaces

**Consumes:** `x-settings.section` (props `id`, `title`, `description`), `x-settings.summary-card`, Alpine `settingToggle`, route `settings.edit` and page `pages::settings.index` (Phase 4); `x-admin.tabs` (`items`), trait `App\Livewire\Concerns\AdminTable`, `App\Admin\Contracts\WorkQueueSource`, `App\Admin\WorkQueueItem`, `config('bookflix.admin_work_queue_sources')`, `x-confirm-dialog`, `x-cta` variants (Phase 5); `R/legal/sections/*.blade.php` and route `legal.privacy` (Phase 3); the Phase 3 method that picks an entry of `bookflix.crisis_resources` for a user (find it in Task 9.6); `App\Actions\Library\AddToLibrary`, `LogProgress`, `UnverifyContentSource`, `App\Reader\ContentAccessGate`, `App\Reader\UploadContentSource`, `Media::isGatedFor`.

**Produces (F.0 registry row 9, exact):**
- Tables `platform_links`, `user_follows`, `content_licenses`, `content_license_media`, `creator_applications`, `notifications` (Laravel's); columns `profiles.country`, `profiles.email_new_episodes`.
- `App\Platforms\PlatformRegistry`: `all(): array<string, array{name: string, confirmed: bool, url_patterns: list<string>}>`, `confirmed(): array` (same shape, confirmed only), `find(string $key): ?array`, `name(string $key): ?string`, `isConfirmed(string $key): bool`, `matches(string $key, string $url): bool`.
- `App\Licensing\LicenseGate::allows(ContentSource $source, User $user): bool`, called from `ContentAccessGate::allows`.
- Routes `publish` (GET/POST `/publish`), `publish.thanks` (GET `/publish/thanks`); admin routes `admin.applications` (`/admin/applications`), `admin.licenses` (`/admin/licenses`), `admin.licenses.agreement` (`/admin/licenses/{license}/agreement`, signed).
- `App\Licensing\LicenseWorkQueueSource`, `App\Platforms\CreatorApplicationWorkQueueSource` (both `implements WorkQueueSource`).
- Phase-internal names later tasks use: models `PlatformLink`, `UserFollow`, `ContentLicense`, `CreatorApplication`; `App\Platforms\FollowProgress`; `App\Library\ReadingPlace::labels(User $user, Collection $media): array<int, string>`; `App\Platforms\NewEpisodes::check(): int`; `App\Notifications\NewEpisodeAvailable`, `App\Notifications\LicenseExpiringSoon`; command `bookflix:check-new-episodes`, command `bookflix:expire-licenses`.

## Rulings

- **P9-R1.** `platform_links` and `user_follows` carry three columns the brief does not list: `platform_links.latest_episode` (unsigned int, nullable) and `latest_episode_checked_at`, needed to tell a new episode from an old one. Cost if wrong: low, two nullable columns.
- **P9-R2.** Following a story calls `AddToLibrary` (status Reading) so Library and Continue Reading need no second query. A follow without a library row cannot exist. Cost if wrong: a reader who removes the story from the library keeps the follow (we keep the follow and show it again if they re-add). Low.
- **P9-R3.** While a platform is unconfirmed, an existing `external_link` "Where to read" row for it keeps rendering exactly as today (it is an admin-verified link). Once the platform is confirmed, the row is hidden and the `platform_links` copy renders instead. This refines D2-20 so flipping nothing changes nothing. Cost if wrong: duplicate rows for one release cycle at worst.
- **P9-R4.** Episode URL template uses the single placeholder `{episode}` and must start with `https://`. No template means the link goes to `series_url` with the note "This platform has no direct episode link, so this opens the series page." Cost if wrong: low.
- **P9-R5.** Return-prompt trigger, exact: a follow with `opened_at` set and `opened_episode` set, rendered by any request other than the click that set them. Concretely the prompt shows (a) on a later load of the story page or the Library page, and (b) in the original tab when it becomes visible again (Alpine `visibilitychange` calls `$wire.$refresh()`). It never shows in the response to the click itself (`$this->skipRender()`). Answering "Yes" or "Not yet" clears both columns, so it is asked once per open. Cost if wrong: a prompt that appears too eagerly annoys, too late loses progress updates. Medium.
- **P9-R6.** Release data: `AniListReleases` reads `chapters`, `MangaUpdatesReleases` reads `latest_chapter` from the existing provider `find()` payload for the story's `media_external_ids` row. Executor confirms both key names against `tests/Fixtures` provider fixtures or the provider's code before relying on them (Task 9.5 Step 3); if a key is absent the source returns null and the story is skipped. No platform feed is configured (`feed_url_template` stays null everywhere): record in the ledger as "Needs decision: confirm an official feed per platform". Cost if wrong: fewer notifications, never wrong ones.
- **P9-R7.** A licensed `ContentSource` whose story is covered by no `content_licenses` row behaves as before (the Standard Ebooks / CC files keep working). Only stories listed in `content_license_media` are gated. Cost if wrong: an admin who uploads licensed creator work without a licence record gets an open file. Mitigated: the admin upload action (Task 9.8) requires a licence. Medium.
- **P9-R8.** Territories: `worldwide` boolean plus `territories` json list of ISO codes (no fake "WW" code). `rights` is stored and shown; no reader download route exists today, so `stream` and `stream_download` differ only on record. Cost if wrong: low until a download feature exists.
- **P9-R9.** "Unpublish" an expired work means `UnverifyContentSource` on its licensed sources (reversible by re-verifying after renewal). Files stay on the private disk. Cost if wrong: low.
- **P9-R10.** Country names come from `Locale::getDisplayRegion` over a code list in `config('bookflix.licensing.countries')` (the officially assigned ISO 3166-1 alpha-2 codes, 249). Cost if wrong: low.
- **P9-R11.** The admin file upload reuses `UploadContentSource`'s content sniffing by making its private `detect()` a public `inspect(string $path): ContentFormat` (no behaviour change). Cost if wrong: a second sniffer would drift from it.

## Baseline re-check

Run first; record each result in the ledger.
1. `grep -rn "external_link\|ExternalLink" app resources/views` : confirm `SourceChooser::links` and `where-to-read.blade.php` are still the only readers of those rows.
2. Open `app/Reader/ContentAccessGate.php`, `app/Reader/UploadContentSource.php` (is `detect()` still private? did Phase 7 rename it?), `app/Reader/SourceChooser.php`.
3. `php artisan route:list` (full, not `--except-vendor`): confirm `publish`, `admin.applications`, `admin.licenses` are free; note the Phase 3 and Phase 4 routes exist.
4. `grep -rn "function regionFor" app/Ai/Safety` : confirm Phase 3's `App\Ai\Safety\CrisisResources::regionFor(?Request $request = null): string` exists (the only place the crisis region is read); record it in the ledger.
5. `grep -rn "settings.index\|x-settings.section" resources/views` : record the settings page path and how a `profile` section saves.
6. `grep -rn "WorkQueueSource\|admin_work_queue_sources\|AdminTable\|x-admin.tabs" app config resources` : record Phase 5's exact constructor of `WorkQueueItem`.
7. `ls database/migrations | grep -i notif` (the `notifications` table must still be missing; `User` already uses `Notifiable`) and `grep -n "country\|email_new" database/migrations/*profiles*` (both columns must be absent).
8. `grep -rn "continue-reading" resources/views/pages/home` : the Home line that renders a Continue Reading tile.

## Tasks

### Task 9.1: Schema, models and the platform registry

**Files:**
- Create: `database/migrations/2026_11_27_100000_create_platform_links_and_user_follows_tables.php`, `database/migrations/2026_11_27_100100_add_country_and_email_new_episodes_to_profiles_table.php`, `database/migrations/2026_11_27_100200_create_notifications_table.php` (via `php artisan make:notifications-table --no-interaction`, then rename to this timestamp if needed)
- Create: `app/Models/PlatformLink.php`, `app/Models/UserFollow.php`, `app/Platforms/PlatformRegistry.php`, `database/factories/PlatformLinkFactory.php`, `database/factories/UserFollowFactory.php` (all via `php artisan make:model ... -f --no-interaction` where a model, then edit)
- Modify: `config/bookflix.php` (add `'platforms'` key, after `'navigation'`), `app/Models/Profile.php` (`#[Fillable]`, casts, `@property`), `.env.example` (none needed yet)
- Test: `tests/Feature/Platforms/PlatformRegistryTest.php`, `tests/Feature/Platforms/PlatformSchemaTest.php`

**Interfaces:** Produces `PlatformRegistry` (see Cross-phase), `PlatformLink` (`media(): BelongsTo`, `episodeUrl(int $episode): ?string`), `UserFollow` (`user()`, `media()`, `link(): ?PlatformLink`, casts `notify` bool, `opened_at` datetime). `platform_links`: id, `media_id` FK cascade, `platform` string(40), `series_url` text, `episode_url_template` text nullable, `regions` json nullable, `latest_episode` unsignedInteger nullable, `latest_episode_checked_at` timestamp nullable, timestamps, unique(`media_id`,`platform`). `user_follows`: id, `user_id` FK cascade, `media_id` FK cascade, `platform` string(40), `last_episode_read` unsignedInteger nullable, `notify` boolean default true, `opened_episode` unsignedInteger nullable, `opened_at` timestamp nullable, timestamps, unique(`user_id`,`media_id`,`platform`). `profiles`: `country` char(2) nullable, `email_new_episodes` boolean default false.

- [ ] **Step 1: Write the failing tests**

```php
// tests/Feature/Platforms/PlatformRegistryTest.php (pattern: tests/Feature/Reader/ContentAccessGateTest.php)
use App\Platforms\PlatformRegistry;
test('the registry lists exactly the thirteen platforms, none confirmed, each with url patterns', function () {
    $all = app(PlatformRegistry::class)->all();
    expect(array_keys($all))->toBe(['webtoon', 'tapas', 'tappytoon', 'lezhin', 'manta', 'manga-plus', 'shonen-jump', 'k-manga', 'comikey', 'globalcomix', 'marvel-unlimited', 'dc-universe-infinite', 'kindle-comixology']);
    foreach ($all as $platform) {
        expect($platform['confirmed'])->toBeFalse()->and($platform['url_patterns'])->not->toBeEmpty()->and($platform['name'])->not->toBe('');
    }
    expect(app(PlatformRegistry::class)->confirmed())->toBe([]);
});
test('matches() accepts an https url on a listed host and nothing else', function () {
    config(['bookflix.platforms.webtoon.url_patterns' => ['webtoons.com']]);
    $registry = app(PlatformRegistry::class);
    expect($registry->matches('webtoon', 'https://www.webtoons.com/en/x/y/list?title_no=1'))->toBeTrue()
        ->and($registry->matches('webtoon', 'http://www.webtoons.com/en/x'))->toBeFalse()
        ->and($registry->matches('webtoon', 'https://webtoons.com.evil.example/x'))->toBeFalse()
        ->and($registry->matches('webtoon', 'javascript:alert(1)'))->toBeFalse()
        ->and($registry->matches('nope', 'https://webtoons.com/x'))->toBeFalse();
});
```

```php
// tests/Feature/Platforms/PlatformSchemaTest.php
use App\Models\{Media, PlatformLink, User, UserFollow};
test('episodeUrl fills {episode} and refuses a template that is not https or lacks the placeholder', function () {
    $link = PlatformLink::factory()->make(['episode_url_template' => 'https://example.com/ep/{episode}']);
    expect($link->episodeUrl(43))->toBe('https://example.com/ep/43');
    expect(PlatformLink::factory()->make(['episode_url_template' => null])->episodeUrl(3))->toBeNull()
        ->and(PlatformLink::factory()->make(['episode_url_template' => 'http://example.com/{episode}'])->episodeUrl(3))->toBeNull()
        ->and(PlatformLink::factory()->make(['episode_url_template' => 'https://example.com/ep'])->episodeUrl(3))->toBeNull();
});
```
- [ ] **Step 2: Run to verify it fails**: `env -u AI_AGENT -u CLAUDECODE vendor/bin/pest tests/Feature/Platforms`. Expected: class `PlatformRegistry` / `PlatformLink` not found.
- [ ] **Step 3: Implement.**
  a. Migrations per the Interfaces list. `Profile`: add `country`, `email_new_episodes` to `#[Fillable]`, cast `email_new_episodes` to `boolean`, add `@property` lines. Factories: `PlatformLinkFactory` defaults `platform => 'webtoon'`, `series_url => 'https://example.com/series/1'`, template null; `UserFollowFactory` defaults `platform => 'webtoon'`, `notify => true`, `media_id`/`user_id` from factories.
  b. `config/bookflix.php` `'platforms'`: an associative array keyed by the 13 keys in the Global Constraints order, each `['name' => ..., 'confirmed' => false, 'url_patterns' => [<host suffixes>], 'feed_url_template' => null]`. Names: Webtoon, Tapas, Tappytoon, Lezhin, Manta, MANGA Plus, Shonen Jump, K Manga, Comikey, GlobalComix, Marvel Unlimited, DC Universe Infinite, Kindle / comiXology. Fill `url_patterns` with each platform's own site host(s) as best known, and put `// TODO(owner): host list unverified until the research step in the ledger` above the array. Comment block above states: "A platform is shown to readers only when `confirmed` is true."
  c. `PlatformRegistry::matches` : parse with `parse_url`, require scheme `https`, accept a host equal to a pattern or ending in `.` + pattern. `confirmed()` filters `all()`. Read config on each call (the tests change config at runtime).
  d. `PlatformLink::episodeUrl` per the test.
  e. **Research ledger step (human in the loop, not scraping):** create `docs/superpowers/plans/mp2-phase-9-ledger.md` with a table, one row per platform: `key | official site (owner supplies) | URL structure of a series page | stable episode URL? (template or none) | terms checked by (owner initials) / date | official feed or API | decision (confirmed true/false)`. The executor fills nothing it cannot read from the owner's note; every row starts `pending owner`. Flipping any `confirmed` to true is a separate commit after the owner fills that row.
- [ ] **Step 4: Run to verify it passes**: `vendor/bin/pest tests/Feature/Platforms tests/Feature/Catalog/CatalogSchemaTest.php`. Expected: PASS. Run `php artisan migrate` once on the dev database (never `migrate:fresh`).
- [ ] **Step 5: Commit**: `git commit -m "feat: platform links, follows and a registry of unconfirmed official platforms"`

### Task 9.2: Copy "Where to read" rows, render from platform_links, and the follow and continue link

**Files:**
- Create: `database/migrations/2026_11_27_100300_copy_external_links_into_platform_links.php`, `resources/views/components/⚡platform-follow.blade.php` (a Livewire component like `⚡library-controls.blade.php`, used as `<livewire:platform-follow :media="$media" />`)
- Modify: `resources/views/components/where-to-read.blade.php`, `resources/views/pages/stories/⚡show.blade.php` (`readState()` lines ~235-249 and the `<x-where-to-read>` call near line 443), `app/Reader/SourceChooser.php` (`linksFrom`)
- Test: `tests/Feature/Platforms/CopyExternalLinksMigrationTest.php`, `tests/Feature/Platforms/WhereToReadPlatformsTest.php`, `tests/Feature/Platforms/PlatformFollowTest.php`

**Interfaces:** Consumes `PlatformRegistry`, `PlatformLink`, `UserFollow`, `AddToLibrary::handle(User, Media, ReadingStatus, array $context = []): LibraryEntry`. Produces Livewire component `platform-follow` with public `Media $media`, actions `follow(string $platform)`, `unfollow(string $platform)`, `toggleNotify(string $platform)`, `opened(string $platform)` (records the click), computed `follows`, `links`.

- [ ] **Step 1: Write the failing tests**

```php
// CopyExternalLinksMigrationTest.php. The migration's logic lives in a class the migration calls, so the test can run it: App\Platforms\CopyExternalLinks::run(): int (rows created).
test('verified external_link rows on a platform host are copied, never moved or deleted', function () {
    config(['bookflix.platforms.tapas.url_patterns' => ['tapas.io']]);
    $media = Media::factory()->create();
    $row = ContentSource::factory()->for($media)->licensed()->externalLink()->verified()->create(['url' => 'https://tapas.io/series/example']);
    $other = ContentSource::factory()->for($media)->licensed()->externalLink()->verified()->create(['url' => 'https://publisher.example.com/book']);
    expect(app(CopyExternalLinks::class)->run())->toBe(1);
    $this->assertDatabaseHas('platform_links', ['media_id' => $media->id, 'platform' => 'tapas', 'series_url' => 'https://tapas.io/series/example']);
    expect(ContentSource::count())->toBe(2)->and(PlatformLink::count())->toBe(1);
    expect(app(CopyExternalLinks::class)->run())->toBe(0); // idempotent
});
```

```php
// WhereToReadPlatformsTest.php (pattern: tests/Feature/Reader/StoryReadActionsTest.php, $this->page helper)
test('an unconfirmed platform never renders, with or without a link row', function () {
    PlatformLink::factory()->for($this->media)->create(['platform' => 'webtoon', 'series_url' => 'https://example.com/s']);
    ($this->page)()->assertDontSee('Webtoon')->assertDontSee('Follow on')->assertDontSee('https://example.com/s');
});
test('a confirmed platform shows its name as text, a follow button and a new-tab link with noopener', function () {
    config(['bookflix.platforms.webtoon.confirmed' => true, 'bookflix.platforms.webtoon.url_patterns' => ['example.com']]);
    PlatformLink::factory()->for($this->media)->create(['platform' => 'webtoon', 'series_url' => 'https://example.com/s']);
    ($this->page)()->assertSee('Webtoon')->assertSee('Follow')->assertSee('target="_blank"', false)->assertSee('rel="noopener noreferrer"', false)->assertDontSee('<img', false);
});
test('while a platform is unconfirmed its old external_link row still shows; once confirmed only the platform_links entry does', function () {
    config(['bookflix.platforms.tapas.url_patterns' => ['tapas.io']]);
    ($this->link)(['url' => 'https://tapas.io/series/x', 'rights_holder' => 'Tapas Row']);
    PlatformLink::factory()->for($this->media)->create(['platform' => 'tapas', 'series_url' => 'https://tapas.io/series/x']);
    ($this->page)()->assertSee('Tapas Row');
    config(['bookflix.platforms.tapas.confirmed' => true]);
    ($this->page)()->assertDontSee('Tapas Row')->assertSee('Tapas');
});
test('a platform link whose url fails the registry host check is not rendered', function () {
    config(['bookflix.platforms.webtoon.confirmed' => true, 'bookflix.platforms.webtoon.url_patterns' => ['webtoons.com']]);
    PlatformLink::factory()->for($this->media)->create(['platform' => 'webtoon', 'series_url' => 'https://evil.example/s']);
    ($this->page)()->assertDontSee('evil.example');
});
```

```php
// PlatformFollowTest.php
beforeEach(function () {
    config(['bookflix.platforms.webtoon.confirmed' => true, 'bookflix.platforms.webtoon.url_patterns' => ['example.com']]);
    $this->user = User::factory()->create();
    $this->media = Media::factory()->create();
    $this->link = PlatformLink::factory()->for($this->media)->create(['platform' => 'webtoon', 'series_url' => 'https://example.com/s', 'episode_url_template' => 'https://example.com/s/ep/{episode}']);
});
test('following stores a follow, puts the story in the library as Reading, and is idempotent', function () {
    Livewire::actingAs($this->user)->test('platform-follow', ['media' => $this->media])->call('follow', 'webtoon')->call('follow', 'webtoon');
    expect(UserFollow::where('user_id', $this->user->id)->count())->toBe(1)
        ->and(LibraryEntry::where('user_id', $this->user->id)->value('status'))->toBe(ReadingStatus::Reading);
});
test('the continue link names the platform and the next episode and opens the episode url', function () {
    UserFollow::factory()->for($this->user)->for($this->media)->create(['platform' => 'webtoon', 'last_episode_read' => 42]);
    Livewire::actingAs($this->user)->test('platform-follow', ['media' => $this->media])
        ->assertSee('Continue on Webtoon, Episode 43')->assertSee('https://example.com/s/ep/43', false);
});
test('with no episode template the link goes to the series page and says so', function () {
    $this->link->update(['episode_url_template' => null]);
    UserFollow::factory()->for($this->user)->for($this->media)->create(['platform' => 'webtoon', 'last_episode_read' => 42]);
    Livewire::actingAs($this->user)->test('platform-follow', ['media' => $this->media])
        ->assertSee('https://example.com/s', false)->assertSee('This platform has no direct episode link, so this opens the series page.');
});
test('an unconfirmed or unknown platform cannot be followed', function () {
    config(['bookflix.platforms.webtoon.confirmed' => false]);
    Livewire::actingAs($this->user)->test('platform-follow', ['media' => $this->media])->call('follow', 'webtoon');
    expect(UserFollow::count())->toBe(0);
    Livewire::actingAs($this->user)->test('platform-follow', ['media' => $this->media])->call('follow', 'not-a-platform');
    expect(UserFollow::count())->toBe(0);
});
```
- [ ] **Step 2: Run to verify it fails**: `vendor/bin/pest tests/Feature/Platforms/CopyExternalLinksMigrationTest.php tests/Feature/Platforms/WhereToReadPlatformsTest.php tests/Feature/Platforms/PlatformFollowTest.php`. Expected: missing `CopyExternalLinks`, component `platform-follow`.
- [ ] **Step 3: Implement.**
  - `App\Platforms\CopyExternalLinks::run(): int`: for each verified `external_link` `ContentSource` with an `https` url, find the first registry platform (confirmed or not) that `matches()`, `firstOrCreate` a `platform_links` row on (`media_id`, `platform`) with `series_url` = the row's url; return how many were created. The migration's `up()` calls it; `down()` does nothing (non-destructive). Never touches `content_sources`.
  - `where-to-read.blade.php` takes an extra `$platformLinks` prop (collection of `PlatformLink`); keep the card but render platform rows first: name as text (`PlatformRegistry::name`), same row markup, same `rel`. `readState()` in the story page additionally loads `PlatformLink` rows for the story whose platform `isConfirmed` and whose `series_url` `matches()`, and `SourceChooser::linksFrom` drops an `external_link` row whose host matches a platform that is confirmed (P9-R3). Show the card when either list is non-empty (still only without a readable file, as today).
  - `⚡platform-follow`: `Gate::authorize('use-reader-side')` in each action; `follow` requires `isConfirmed` and an existing `PlatformLink` for (media, platform) else returns silently; creates the follow with `firstOrCreate` and calls `AddToLibrary` with `ReadingStatus::Reading`. Next episode shown = `($follow->last_episode_read ?? 0) + 1`. Link `href` = `$link->episodeUrl($n) ?? $link->series_url`; `wire:click="opened('webtoon')"` on the anchor (Task 9.3 implements the body; here a method that only authorizes and finds the follow).
  - Embed `<livewire:platform-follow :media="$media" />` inside the card area of the story page for reader accounts.
- [ ] **Step 4: Run to verify it passes**: `vendor/bin/pest tests/Feature/Platforms tests/Feature/Reader/StoryReadActionsTest.php tests/Feature/Components`. Expected: PASS (existing "Where to read" tests stay green).
- [ ] **Step 5: Commit**: `git commit -m "feat: follow a series on a confirmed platform and continue on its episode"`

### Task 9.3: The return prompt and progress

**Files:**
- Create: `app/Platforms/FollowProgress.php`, `tests/Feature/Platforms/ReturnPromptTest.php`, `tests/Browser/PlatformFollowTest.php`
- Modify: `resources/views/components/⚡platform-follow.blade.php` (the prompt, manual set, `opened`), `resources/views/pages/library/⚡index.blade.php` (include the prompt at the top, see Step 3)

**Interfaces:** Produces `FollowProgress::opened(UserFollow $follow): void` (sets `opened_episode = (last_episode_read ?? 0) + 1`, `opened_at = now()`), `FollowProgress::answer(UserFollow $follow, bool $finished): void`, `FollowProgress::setEpisode(UserFollow $follow, int $episode): void` (validation 0 to 100000, throws `ValidationException`). Component actions `answer(int $followId, bool $finished)`, `setEpisode(int $followId, int $episode)`, `pendingFollows` computed (follows of the viewer with `opened_at` not null).

- [ ] **Step 1: Write the failing tests**

```php
// ReturnPromptTest.php (same beforeEach as PlatformFollowTest: confirmed webtoon link, a follow with last_episode_read 42)
test('clicking the link records the open but does not show the prompt in that same response', function () {
    Livewire::actingAs($this->user)->test('platform-follow', ['media' => $this->media])
        ->call('opened', 'webtoon')->assertDontSee('Did you finish Episode 43?');
    $follow = $this->follow->fresh();
    expect($follow->opened_episode)->toBe(43)->and($follow->opened_at)->not->toBeNull();
});
test('the prompt shows on a later load of the story page and of the Library, and names the episode', function () {
    app(FollowProgress::class)->opened($this->follow);
    Livewire::actingAs($this->user)->test('pages::stories.show', ['slug' => $this->media->slug])->assertSee('Did you finish Episode 43?');
    Livewire::actingAs($this->user)->test('pages::library.index')->assertSee('Did you finish Episode 43?');
});
test('one tap on Yes sets the progress to the opened episode and the prompt is gone for good', function () {
    app(FollowProgress::class)->opened($this->follow);
    Livewire::actingAs($this->user)->test('platform-follow', ['media' => $this->media])
        ->call('answer', $this->follow->id, true)->assertDontSee('Did you finish Episode 43?');
    $follow = $this->follow->fresh();
    expect($follow->last_episode_read)->toBe(43)->and($follow->opened_episode)->toBeNull()->and($follow->opened_at)->toBeNull();
    Livewire::actingAs($this->user)->test('pages::stories.show', ['slug' => $this->media->slug])->assertDontSee('Did you finish');
});
test('Not yet clears the prompt and leaves progress alone; progress never goes backwards', function () {
    app(FollowProgress::class)->opened($this->follow);
    Livewire::actingAs($this->user)->test('platform-follow', ['media' => $this->media])->call('answer', $this->follow->id, false);
    expect($this->follow->fresh()->last_episode_read)->toBe(42)->and($this->follow->fresh()->opened_at)->toBeNull();
    $this->follow->update(['last_episode_read' => 50, 'opened_episode' => 43, 'opened_at' => now()]);
    app(FollowProgress::class)->answer($this->follow->fresh(), true);
    expect($this->follow->fresh()->last_episode_read)->toBe(50);
});
test('the reader can set the episode by hand, within range', function () {
    Livewire::actingAs($this->user)->test('platform-follow', ['media' => $this->media])->call('setEpisode', $this->follow->id, 12);
    expect($this->follow->fresh()->last_episode_read)->toBe(12);
    Livewire::actingAs($this->user)->test('platform-follow', ['media' => $this->media])->call('setEpisode', $this->follow->id, -1)->assertHasErrors();
    Livewire::actingAs($this->user)->test('platform-follow', ['media' => $this->media])->call('setEpisode', $this->follow->id, 100001)->assertHasErrors();
    expect($this->follow->fresh()->last_episode_read)->toBe(12);
});
test('another reader\'s follow id changes nothing and answers 404', function () {
    $other = UserFollow::factory()->for(User::factory()->create())->for($this->media)->create(['platform' => 'webtoon', 'last_episode_read' => 5, 'opened_episode' => 6, 'opened_at' => now()]);
    Livewire::actingAs($this->user)->test('platform-follow', ['media' => $this->media])->call('answer', $other->id, true)->assertNotFound();
    Livewire::actingAs($this->user)->test('platform-follow', ['media' => $this->media])->call('setEpisode', $other->id, 99)->assertNotFound();
    expect($other->fresh()->last_episode_read)->toBe(5);
});
```

```php
// tests/Browser/PlatformFollowTest.php (pattern: tests/Browser/StoryPageTest.php)
// Seed: confirmed webtoon (config override via a test service-provider tweak or env), reader signed in, follow with last_episode_read 42, template 'https://127.0.0.1/ep/{episode}'.
// 1. Visit story page at 1280x800. Assert the link text "Continue on Webtoon, Episode 43" has target "_blank" and rel containing "noopener". Assert "Did you finish Episode 43?" is NOT in the DOM.
// 2. Click the link (the new tab opens; ignore it), then dispatch visibilitychange on the original page (script: document.dispatchEvent(new Event('visibilitychange'))).
// 3. Assert "Did you finish Episode 43?" appears, click "Yes", assert it disappears and the page text now reads "Continue on Webtoon, Episode 44".
// 4. At 375x667 assert document.documentElement.scrollWidth <= window.innerWidth.
```
- [ ] **Step 2: Run to verify it fails**: `vendor/bin/pest tests/Feature/Platforms/ReturnPromptTest.php`. Expected: `FollowProgress` missing.
- [ ] **Step 3: Implement.**
  - `FollowProgress` as described. `answer(true)`: `last_episode_read = max(existing ?? 0, opened_episode)`; always null both opened columns. Update the library row's `updated_at` (touch) so Continue Reading orders it first.
  - Component: `opened()` calls `FollowProgress::opened` then `$this->skipRender()`. `answer`/`setEpisode` look the follow up through `auth()->user()->follows()` (add `follows(): HasMany` to `User`) with `findOrFail`, so a foreign id is a 404. The prompt block lists `pendingFollows` only for confirmed platforms. Root element gets `x-data @visibilitychange.window="if (!document.hidden) $wire.$refresh()"`. Manual set: a small number input with a "Save" secondary button, inline field error from the validation message.
  - Library page: render `<livewire:platform-follow-prompt />` is NOT a new component: add a `prompt` prop to `platform-follow` (`:media="null"` means "all my pending follows") and place `<livewire:platform-follow :media="null" />` above the status nav on the Library page, so one component serves both pages.
- [ ] **Step 4: Run to verify it passes**: `vendor/bin/pest tests/Feature/Platforms tests/Feature/Library && vendor/bin/pest tests/Browser/PlatformFollowTest.php tests/Browser/StoryPageTest.php tests/Browser/LibraryPageTest.php`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: ask once if a followed episode was finished, one tap updates progress"`

### Task 9.4: One library with three labels

**Files:**
- Create: `app/Library/ReadingPlace.php`, `tests/Feature/Library/ReadingPlaceTest.php`
- Modify: `resources/views/pages/library/⚡index.blade.php` (computed `places`, grid line ~335 and list `x-result-row` slot ~314), `app/Discovery/ContinueReading.php` (set attribute `reading_place` on each media), `resources/views/pages/home/⚡index.blade.php` (Continue Reading tile, near line 310), `app/Models/User.php` (`follows()`; if not added in 9.3)
- Test: `tests/Feature/Library/ReadingPlaceTest.php`, extend `tests/Feature/Discovery/DiscoverPageTest.php` only if Continue Reading assertions live there (else a new `tests/Feature/Library/ContinueReadingPlacesTest.php`)

**Interfaces:** Produces `ReadingPlace::labels(User $user, Collection $media): array<int, string>`: `[media id => label]`, one label per story, never more than 3 queries for any size of list.

- [ ] **Step 1: Write the failing tests**

```php
test('each story is labelled by where it is read: a file the reader can open, their own file, or a confirmed platform, and nothing else', function () {
    config(['bookflix.platforms.tapas.confirmed' => true]);
    $user = User::factory()->create();
    $hosted = Media::factory()->create(); ContentSource::factory()->for($hosted)->publicDomain()->verified()->ready()->create();
    $mine = Media::factory()->create();   ContentSource::factory()->for($mine)->userOwned($user)->ready()->create();
    $followed = Media::factory()->create(); UserFollow::factory()->for($user)->for($followed)->create(['platform' => 'tapas']);
    $bare = Media::factory()->create(); UserFollow::factory()->for($user)->for($bare)->create(['platform' => 'webtoon']); // unconfirmed: no label
    UserFollow::factory()->for($user)->for($hosted)->create(['platform' => 'tapas']); // a hosted file wins over a follow
    expect(ReadingPlace::labels($user, collect([$hosted, $mine, $followed, $bare])))->toBe([
        $hosted->id => 'Reads in Bookflix', $mine->id => 'Your file', $followed->id => 'On Tapas',
    ]);
});
test('Library shows the label under each tile and Continue Reading carries it', function () {
    config(['bookflix.platforms.tapas.confirmed' => true]);
    $user = User::factory()->create();
    $media = Media::factory()->create(['title' => 'Followed Tale']);
    UserFollow::factory()->for($user)->for($media)->create(['platform' => 'tapas']);
    app(AddToLibrary::class)->handle($user, $media, ReadingStatus::Reading);
    Livewire::actingAs($user)->test('pages::library.index')->assertSee('Followed Tale')->assertSee('On Tapas');
    $shelf = app(ContinueReading::class)->shelf($user);
    expect($shelf->items->first()->getAttribute('reading_place'))->toBe('On Tapas');
});
```

(Shelf's item collection property: read `App\Discovery\Shelf` for its real property name when writing the assertion.)
- [ ] **Step 2: Run to verify it fails**: `vendor/bin/pest tests/Feature/Library/ReadingPlaceTest.php`. Expected: class `ReadingPlace` not found.
- [ ] **Step 3: Implement.** `ReadingPlace::labels`: query 1, readable sources for the media ids (`ContentSource` where `media_id in`, filtered per source by `ContentAccessGate::allows`, reuse `setRelation('media')` as `SourceChooser::readable` does); query 2, follows for the ids; label order: user-owned readable => `Your file`; any other readable => `Reads in Bookflix`; else a follow on a confirmed platform => `On {name}`. Library page: computed `places` for the page's media; print `<span class="...text-legal text-ink-subtle">{{ label }}</span>` in the tile's status line and the list row's meta line (plain text, no logo). `ContinueReading::shelf`: call `labels` once after loading and `setAttribute('reading_place', ...)`; Home tile prints it where it prints progress.
- [ ] **Step 4: Run to verify it passes**: `vendor/bin/pest tests/Feature/Library tests/Feature/Discovery tests/Feature/HomeTest.php && vendor/bin/pest tests/Browser/LibraryPageTest.php tests/Browser/HomeTest.php`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: Library and Continue Reading label where each title is read"`

### Task 9.5: New episodes, notifications and the privacy section

**Files:**
- Create: `app/Platforms/Releases/ReleaseSource.php` (interface `latestEpisode(PlatformLink $link): ?int`), `app/Platforms/Releases/MangaUpdatesReleases.php`, `app/Platforms/Releases/AniListReleases.php`, `app/Platforms/NewEpisodes.php`, `app/Console/Commands/CheckNewEpisodes.php`, `app/Notifications/NewEpisodeAvailable.php`, `resources/views/components/new-episodes.blade.php`, `resources/views/legal/sections/followed-series.blade.php`
- Modify: `routes/console.php` (schedule), `resources/views/pages/library/⚡index.blade.php` (render `<x-new-episodes />` above the status nav), the Phase 3 privacy view (include the new section, found in the baseline), `resources/views/pages/settings/⚡index.blade.php` (email toggle, Task 9.6 adds country in the same section: do the toggle here)
- Test: `tests/Feature/Platforms/NewEpisodesTest.php`, `tests/Feature/Platforms/NewEpisodeNotificationTest.php`, `tests/Feature/Legal/FollowedSeriesPolicyTest.php`

**Interfaces:** Produces `NewEpisodes::check(): int` (number of notifications sent), `NewEpisodeAvailable(PlatformLink $link, int $episode)` (`via`: `database`, plus `mail` only when `$notifiable->profile->email_new_episodes`), command `bookflix:check-new-episodes` scheduled `everySixHours()`.

- [ ] **Step 1: Write the failing tests**

```php
// NewEpisodesTest.php. Providers are faked at the HTTP layer; fixtures are generated arrays, never real responses.
// Helper: releases(int|null $latest) binds a fake ReleaseSource into the container returning $latest.
beforeEach(function () {
    config(['bookflix.platforms.webtoon.confirmed' => true, 'bookflix.platforms.webtoon.url_patterns' => ['example.com']]);
    Notification::fake();
    $this->media = Media::factory()->create();
    $this->link = PlatformLink::factory()->for($this->media)->create(['platform' => 'webtoon', 'series_url' => 'https://example.com/s', 'latest_episode' => 10]);
    $this->user = User::factory()->create();
    $this->follow = UserFollow::factory()->for($this->user)->for($this->media)->create(['platform' => 'webtoon', 'last_episode_read' => 9, 'notify' => true]);
});
test('a higher latest episode notifies each follower who has notify on, once', function () {
    $off = UserFollow::factory()->for(User::factory()->create())->for($this->media)->create(['platform' => 'webtoon', 'notify' => false, 'last_episode_read' => 1]);
    fakeReleases(12);
    expect(app(NewEpisodes::class)->check())->toBe(1);
    Notification::assertSentTo($this->user, NewEpisodeAvailable::class, fn ($n) => $n->episode === 12);
    Notification::assertNotSentTo($off->user, NewEpisodeAvailable::class);
    expect($this->link->fresh()->latest_episode)->toBe(12);
    app(NewEpisodes::class)->check(); // same latest: nothing more
    Notification::assertSentToTimes($this->user, NewEpisodeAvailable::class, 1);
});
test('the first sighting only records the number, and a reader already caught up is not told', function () {
    $this->link->update(['latest_episode' => null]);
    fakeReleases(12);
    app(NewEpisodes::class)->check();
    Notification::assertNothingSent();
    $this->follow->update(['last_episode_read' => 13]);
    fakeReleases(14); $this->link->update(['latest_episode' => 12]);
    app(NewEpisodes::class)->check();
    Notification::assertSentTo($this->user, NewEpisodeAvailable::class); // 14 > 13
    Notification::fake();
    $this->follow->update(['last_episode_read' => 20]); $this->link->update(['latest_episode' => 14]); fakeReleases(15);
    app(NewEpisodes::class)->check();
    Notification::assertNothingSent();
});
test('an unconfirmed platform and a source with no answer are skipped', function () {
    config(['bookflix.platforms.webtoon.confirmed' => false]); fakeReleases(99);
    expect(app(NewEpisodes::class)->check())->toBe(0);
    config(['bookflix.platforms.webtoon.confirmed' => true]); fakeReleases(null);
    expect(app(NewEpisodes::class)->check())->toBe(0)->and($this->link->fresh()->latest_episode)->toBe(10);
});
```

```php
// NewEpisodeNotificationTest.php
test('the notification is stored for in-app display and carries the episode and the story', function () {
    $user = User::factory()->create();
    $user->notify(new NewEpisodeAvailable($link, 12));
    expect($user->notifications()->first()->data)->toMatchArray(['media_id' => $link->media_id, 'episode' => 12, 'platform' => 'webtoon']);
});
test('email goes only to readers who opted in', function () {
    Mail::fake();
    $off = User::factory()->create(); $on = User::factory()->create(); $on->profile()->update(['email_new_episodes' => true]);
    $off->notify(new NewEpisodeAvailable($link, 12)); $on->notify(new NewEpisodeAvailable($link, 12));
    expect((new NewEpisodeAvailable($link, 12))->via($off))->toBe(['database'])->and((new NewEpisodeAvailable($link, 12))->via($on))->toBe(['database', 'mail']);
    $mail = (new NewEpisodeAvailable($link, 12))->toMail($on);
    expect($mail->subject)->toContain('Episode 12');
});
test('the Library lists unread new-episode notices and marks them read', function () {
    Livewire::actingAs($user)->test('pages::library.index')->assertSee('Episode 12 of')->assertSee('Continue on Webtoon, Episode 12')->call('markEpisodeNoticesRead');
    expect($user->unreadNotifications()->count())->toBe(0);
});
```

```php
// FollowedSeriesPolicyTest.php
test('the privacy policy says what we keep about followed series and that links leave Bookflix', function () {
    $this->get(route('legal.privacy'))->assertOk()->assertSeeInOrder(['Followed series', 'which series you follow', 'the episode you last read'])
        ->assertSee('third-party site')->assertSee('its own privacy policy');
});
```
- [ ] **Step 2: Run to verify it fails**: `vendor/bin/pest tests/Feature/Platforms/NewEpisodesTest.php tests/Feature/Platforms/NewEpisodeNotificationTest.php tests/Feature/Legal/FollowedSeriesPolicyTest.php`. Expected: classes missing; policy test fails on the heading.
- [ ] **Step 3: Implement.**
  - Sources: build on the provider `find()` (`MangaUpdatesProvider`, `AniListProvider`) through the container; find the story's id with `MediaExternalId` joined to `ExternalSource.key`; return `(int)` of `payload['latest_chapter']` / `payload['chapters']` when a positive int, else null (P9-R6: confirm both keys against the providers' fixtures first, record in the ledger). A provider exception (`ProviderUnavailable`) is caught per link and skipped. `NewEpisodes::check` loops confirmed-platform links, asks each source in order (MangaUpdates, then AniList) for the first non-null answer, and when the answer is above `latest_episode` (and `latest_episode` is not null) notifies each follow where `notify` is true and `last_episode_read < answer`; it always stores `latest_episode` and `latest_episode_checked_at`. Chunk by 100 links.
  - `NewEpisodeAvailable`: data `{media_id, media_title, platform, platform_name, episode, url}`; mail text plain, one line, subject `New episode: {title}, Episode {n}`; the mail's action goes to `route('stories.show', slug)`, never to the platform. `queue` it (`ShouldQueue`).
  - `x-new-episodes`: unread notifications of this type for the viewer, each a plain row "Episode {n} of {title} is out." plus the Continue link text from the follow; Library gets `markEpisodeNoticesRead()` action.
  - Settings: toggle `email_new_episodes` saved immediately with `settingToggle`, label "Email me about new episodes", helper "Off by default. In-app notices stay on for the series you follow."
  - Privacy section `followed-series.blade.php`: heading `Followed series`; text states Bookflix stores which series you follow, the platform, the episode you last read and whether you want notices; that following sends nothing to the platform; that opening a link takes you to a third-party site with its own privacy policy and Bookflix cannot see what you do there; that email notices are off unless you turn them on; and a second short paragraph that a creator application stores the name, email and text the applicant typed. Wording beyond these facts is `{{-- TODO(owner): legal review --}}`.
  - Schedule: `Schedule::command('bookflix:check-new-episodes')->everySixHours();`
- [ ] **Step 4: Run to verify it passes**: `vendor/bin/pest tests/Feature/Platforms tests/Feature/Legal tests/Feature/Catalog`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: new-episode notices from release data, email by opt-in, privacy section for followed series"`

### Task 9.6: Reader country in Settings, used by crisis resources

**Files:**
- Create: `app/Licensing/Countries.php` (`all(): array<string, string>` ISO code => English name; `isValid(string $code): bool`)
- Modify: `config/bookflix.php` (add `'licensing' => ['countries' => [...249 codes...], 'warn_days' => (int) env('BOOKFLIX_LICENSE_WARN_DAYS', 21)]`), `.env.example` (`BOOKFLIX_LICENSE_WARN_DAYS=21`), `resources/views/pages/settings/⚡index.blade.php` (country select in `x-settings.summary-card`, per the registry), the Phase 3 crisis region method (found in the baseline)
- Test: `tests/Feature/Settings/CountrySettingTest.php`, extend the Phase 3 safety test file (named in the baseline; likely `tests/Feature/Ai/SafetyCheckTest.php`)

**Interfaces:** Settings page gains public `?string $country` and action `saveCountry()`; `CrisisResources::regionFor()` (Phase 3) prefers `$user->profile->country` when it is a key of `bookflix.crisis_resources`, else its previous logic, else `default`.

- [ ] **Step 1: Write the failing tests**

```php
test('a reader saves their country, stored uppercase, and can clear it', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user)->test('pages::settings.index')->set('country', 'jp')->call('saveCountry')->assertHasNoErrors();
    expect($user->profile->fresh()->country)->toBe('JP');
    Livewire::actingAs($user)->test('pages::settings.index')->set('country', '')->call('saveCountry');
    expect($user->profile->fresh()->country)->toBeNull();
});
test('a code that is not an assigned ISO code is refused', function (string $bad) {
    Livewire::actingAs(User::factory()->create())->test('pages::settings.index')->set('country', $bad)->call('saveCountry')->assertHasErrors('country');
})->with(['XX', 'USA', 'U1', "US'--", 'ZZ']);
test('crisis resources prefer the reader\'s saved country over any guess', function () {
    config(['bookflix.crisis_resources' => ['default' => ['name' => 'Default'], 'JP' => ['name' => 'Japan list']]]);
    $user = User::factory()->create(); $user->profile()->update(['country' => 'JP']);
    // Call the Phase 3 method named in the baseline with $user and assert it returns the 'JP' entry; with country null it returns 'default'.
});
```
- [ ] **Step 2: Run to verify it fails**: `vendor/bin/pest tests/Feature/Settings/CountrySettingTest.php`. Expected: `Countries` missing.
- [ ] **Step 3: Implement.** `saveCountry`: `Gate::authorize('use-reader-side')`; validate `['nullable', 'string', Rule::in(array_keys(Countries::all()))]` after `strtoupper(trim())`; blank becomes null; `$user->profile()->updateOrCreate([], ['country' => ...])`. The select shows `Countries::all()` with a first option "Not set" and a helper line: "Some licensed stories can only be read in the countries their licence covers. If you leave this empty you will only see worldwide stories." Inline success text per Phase 4's pattern. `Countries::all()` = config codes mapped through `Locale::getDisplayRegion('-'.$code, 'en')`, sorted by name.
- [ ] **Step 4: Run to verify it passes**: `vendor/bin/pest tests/Feature/Settings tests/Feature/Ai`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: reader country in Settings, preferred by crisis resources"`

### Task 9.7: Licence records and the LicenseGate

**Files:**
- Create: `database/migrations/2026_11_27_110000_create_content_licenses_tables.php`, `app/Models/ContentLicense.php`, `database/factories/ContentLicenseFactory.php`, `app/Enums/LicenseRights.php` (`Stream = 'stream'`, `StreamDownload = 'stream_download'`), `app/Licensing/LicenseGate.php`
- Modify: `app/Reader/ContentAccessGate.php` (`allows`, the final `match`), `config/filesystems.php` (disk `licenses`)
- Test: `tests/Feature/Licensing/LicenseGateTest.php`, extend `tests/Feature/Reader/ContentAccessGateTest.php`, `tests/Feature/Reader/StreamContentTest.php`

**Interfaces:** Produces `content_licenses`: id, `licensor` string(255), `rights` enum(`stream`,`stream_download`), `worldwide` boolean default false, `territories` json default `[]`, `starts_on` date, `ends_on` date, `revenue_terms` text nullable, `agreement_path` string nullable (private disk `licenses`), `approved_by` FK users nullOnDelete nullable, `approved_at` timestamp nullable, `expiry_warned_at` timestamp nullable, `expired_handled_at` timestamp nullable, timestamps. `content_license_media`: `content_license_id` FK cascade, `media_id` FK cascade, primary key (both). Model: `media(): BelongsToMany`, `approver(): BelongsTo`, `isActiveOn(CarbonInterface $date): bool` (approved, `starts_on <= date <= ends_on`), `coversCountry(?string $code): bool` (`worldwide` true, or `$code` non-null and in `territories`), scopes `active()`.

- [ ] **Step 1: Write the failing tests**

```php
// LicenseGateTest.php. Helper licensed($media, $attrs) creates an approved licence covering $media.
test('a licensed file with no licence record behaves as before', function () {
    $source = ContentSource::factory()->licensed()->verified()->ready()->create();
    expect(app(LicenseGate::class)->allows($source, User::factory()->create()))->toBeTrue();
});
test('an active worldwide licence allows any reader, including one with no country', function () {
    [$source] = licensedSource(['worldwide' => true]);
    expect(app(LicenseGate::class)->allows($source, User::factory()->create()))->toBeTrue();
});
test('an expired licence blocks reading, the day after its end date and not the day of', function () {
    [$source] = licensedSource(['worldwide' => true, 'ends_on' => today()]);
    $user = User::factory()->create();
    expect(app(LicenseGate::class)->allows($source, $user))->toBeTrue();
    $this->travelTo(today()->addDay()->startOfDay());
    expect(app(LicenseGate::class)->allows($source, $user))->toBeFalse();
});
test('a territory licence allows a reader in the list and blocks outside it and with no country', function () {
    [$source] = licensedSource(['worldwide' => false, 'territories' => ['JP', 'KR']]);
    $in = User::factory()->create(); $in->profile()->update(['country' => 'KR']);
    $out = User::factory()->create(); $out->profile()->update(['country' => 'US']);
    $none = User::factory()->create();
    expect(app(LicenseGate::class)->allows($source, $in))->toBeTrue()
        ->and(app(LicenseGate::class)->allows($source, $out))->toBeFalse()
        ->and(app(LicenseGate::class)->allows($source, $none))->toBeFalse();
});
test('two licences: one lapsed and one active in the reader\'s country allows; none matching blocks', function () {
    [$source, $media] = licensedSource(['worldwide' => false, 'territories' => ['US'], 'ends_on' => today()->subDay()]);
    licenseFor($media, ['worldwide' => false, 'territories' => ['JP']]);
    $jp = User::factory()->create(); $jp->profile()->update(['country' => 'JP']);
    $us = User::factory()->create(); $us->profile()->update(['country' => 'US']);
    expect(app(LicenseGate::class)->allows($source, $jp))->toBeTrue()->and(app(LicenseGate::class)->allows($source, $us))->toBeFalse();
});
```

```php
// extend ContentAccessGateTest.php
test('the access gate refuses a licensed file the licence gate refuses, so every stream and page route stops with it', function () {
    [$source] = licensedSource(['worldwide' => false, 'territories' => ['JP']]);
    expect((new ContentAccessGate)->allows(User::factory()->create(), $source))->toBeFalse();
});
// extend StreamContentTest.php: a signed stream URL issued before expiry returns 403 after the licence ends.
test('a stream url signed before expiry stops working once the licence has ended', function () {
    [$source] = licensedSource(['worldwide' => true, 'ends_on' => today()]);
    $user = User::factory()->create();
    $url = app(ContentUrl::class)->stream($user, $source); // use the real signer method from App\Reader\ContentUrl
    $this->actingAs($user)->get($url)->assertOk();
    $this->travelTo(today()->addDay()->startOfDay());
    $this->actingAs($user)->get($url)->assertForbidden();
});
```
- [ ] **Step 2: Run to verify it fails**: `vendor/bin/pest tests/Feature/Licensing/LicenseGateTest.php`. Expected: `ContentLicense` missing.
- [ ] **Step 3: Implement.** `LicenseGate::allows(ContentSource $source, User $user)`: if `access_type !== Licensed` return true; load licences covering `$source->media_id` through the pivot; none => true (P9-R7); else true iff some licence `isActiveOn(today())` and `coversCountry($user->profile->country)`. One query. `ContentAccessGate::allows`: in the `match`, `AccessType::Licensed => $source->isVerified() && app(LicenseGate::class)->allows($source, $user)`. `filesystems.php`: disk `licenses`, driver `local`, root `storage_path('app/private/licenses')`, `serve` false, `throw` false. `ContentLicenseFactory` default: `licensor 'Example Studio'`, `rights stream`, `worldwide true`, dates today-1 to today+1 year, `approved_by` an admin factory, `approved_at now()`; states `expired()`, `unapproved()`. Add `licensedSource()` and `licenseFor()` helpers to `tests/Pest.php` (returns `[ContentSource, Media]`).
- [ ] **Step 4: Run to verify it passes**: `vendor/bin/pest tests/Feature/Licensing tests/Feature/Reader`. Expected: PASS (all existing reader tests stay green).
- [ ] **Step 5: Commit**: `git commit -m "feat: content licences and a LicenseGate in the reader access gate"`

### Task 9.8: Admin licences, licensed upload, expiry job and alert

**Files:**
- Create: `resources/views/pages/admin/licenses/⚡index.blade.php`, `app/Http/Controllers/Admin/LicenseAgreementController.php`, `app/Licensing/UploadLicensedFile.php`, `app/Licensing/LicenseWorkQueueSource.php`, `app/Licensing/ExpireLicenses.php`, `app/Console/Commands/ExpireLicenses.php` (command `bookflix:expire-licenses`; name the class `ExpireLicensesCommand` to avoid the clash), `app/Notifications/LicenseExpiringSoon.php`
- Modify: `routes/admin.php`, `app/Reader/UploadContentSource.php` (`detect()` to public `inspect()`, P9-R11), `config/bookflix.php` (`admin_navigation` Catalog items and `admin_work_queue_sources`), `routes/console.php` (daily), `.env.example`
- Test: `tests/Feature/Licensing/AdminLicensesTest.php`, `tests/Feature/Licensing/ExpireLicensesTest.php`, `tests/Feature/Licensing/LicenseWorkQueueSourceTest.php`, `tests/Feature/Licensing/UploadLicensedFileTest.php`

**Interfaces:** Produces `UploadLicensedFile::handle(User $admin, ContentLicense $license, Media $media, UploadedFile $file): ContentSource` (requires `$media` in the licence's pivot; stores on `ContentSource::disk()` at `content/licensed/{license id}/{ulid}.{ext}`; `access_type` Licensed, `verified_by` the admin, `verified_at` now, `license` text `Licensed by {licensor} (licence #{id})`, `rights_holder` licensor; refuses non-admins). `ExpireLicenses::handle(): array{unpublished: int, warned: int}`. Page `pages::admin.licenses.index` (create licence form, list with search and status filter via `AdminTable`, "Upload file" per covered story, agreement upload and signed open link).

- [ ] **Step 1: Write the failing tests**

```php
// AdminLicensesTest.php (pattern: tests/Feature/Admin/AdminAccessTest.php from Phase 1; Phase 1's route-guard sweep already covers who may open the new admin routes; the tests here cover the actions)
test('an admin records a licence with its covered stories and a private agreement file', function () {
    Storage::fake('licenses');
    $admin = User::factory()->admin()->create(); $media = Media::factory()->create();
    Livewire::actingAs($admin)->test('pages::admin.licenses.index')
        ->set('licensor', 'Example Studio')->set('rights', 'stream')->set('worldwide', false)->set('territories', 'jp, kr')
        ->set('startsOn', '2026-01-01')->set('endsOn', '2027-01-01')->set('revenueTerms', 'Per the signed agreement.')
        ->set('mediaIds', [$media->id])->set('agreement', UploadedFile::fake()->create('agreement.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();
    $license = ContentLicense::first();
    expect($license->territories)->toBe(['JP', 'KR'])->and($license->approved_by)->toBe($admin->id)->and($license->media->pluck('id')->all())->toBe([$media->id]);
    Storage::disk('licenses')->assertExists($license->agreement_path);
});
test('an unknown territory code, an end before the start, and a non-pdf agreement are refused', function (array $set, string $field) {
    Livewire::actingAs(User::factory()->admin()->create())->test('pages::admin.licenses.index')->set($set)->call('save')->assertHasErrors($field);
})->with([
    'bad code' => [['licensor' => 'A', 'territories' => 'XX', 'startsOn' => '2026-01-01', 'endsOn' => '2027-01-01', 'mediaIds' => [1]], 'territories'],
    'dates reversed' => [['licensor' => 'A', 'worldwide' => true, 'startsOn' => '2027-01-01', 'endsOn' => '2026-01-01', 'mediaIds' => [1]], 'endsOn'],
]);
test('the agreement opens only through a signed admin url and never from the public disk', function () {
    $admin = User::factory()->admin()->create(); $license = ContentLicense::factory()->create(['agreement_path' => 'a/b.pdf']);
    Storage::fake('licenses'); Storage::disk('licenses')->put('a/b.pdf', '%PDF-1.4');
    $this->actingAs($admin)->get(route('admin.licenses.agreement', $license))->assertForbidden(); // unsigned
    $this->actingAs($admin)->get(URL::signedRoute('admin.licenses.agreement', $license))->assertOk();
    $this->actingAs(User::factory()->create())->get(URL::signedRoute('admin.licenses.agreement', $license))->assertForbidden();
});
```

```php
// UploadLicensedFileTest.php (use the tinyEpub() helper from tests/Pest.php or the existing UploadContentSource tests)
test('an admin uploads a licensed file for a story the licence covers, and readers in territory can open it', function () {
    [$license, $media] = [ContentLicense::factory()->create(['worldwide' => true]), Media::factory()->create()];
    $license->media()->attach($media);
    $source = app(UploadLicensedFile::class)->handle(User::factory()->admin()->create(), $license, $media, tinyEpubUpload());
    expect($source->access_type)->toBe(AccessType::Licensed)->and($source->verified_by)->not->toBeNull();
    expect((new ContentAccessGate)->allows(User::factory()->create(), $source->fresh('media')))->toBeTrue();
});
test('a reader cannot upload a licensed file, and a story outside the licence is refused', function () {
    $license = ContentLicense::factory()->create();
    expect(fn () => app(UploadLicensedFile::class)->handle(User::factory()->create(), $license, Media::factory()->create(), tinyEpubUpload()))->toThrow(AuthorizationException::class);
    expect(fn () => app(UploadLicensedFile::class)->handle(User::factory()->admin()->create(), $license, Media::factory()->create(), tinyEpubUpload()))->toThrow(ValidationException::class);
});
```

```php
// ExpireLicensesTest.php
test('an expired licence unverifies its licensed sources and is handled once', function () {
    [$source, $media] = licensedSource(['worldwide' => true, 'ends_on' => today()->subDay()]);
    expect(app(ExpireLicenses::class)->handle()['unpublished'])->toBe(1);
    expect($source->fresh()->verified_at)->toBeNull();
    expect(app(ExpireLicenses::class)->handle()['unpublished'])->toBe(0);
});
test('a story another active licence still covers stays published', function () {
    [$source, $media] = licensedSource(['worldwide' => true, 'ends_on' => today()->subDay()]);
    licenseFor($media, ['worldwide' => true, 'ends_on' => today()->addYear()]);
    app(ExpireLicenses::class)->handle();
    expect($source->fresh()->verified_at)->not->toBeNull();
});
test('admins are mailed once when a licence is within the warning window, and not before it', function () {
    Notification::fake();
    $admins = User::factory()->admin()->count(2)->create(); User::factory()->create();
    licenseFor(Media::factory()->create(), ['ends_on' => today()->addDays(21)]);
    licenseFor(Media::factory()->create(), ['ends_on' => today()->addDays(22)]);
    expect(app(ExpireLicenses::class)->handle()['warned'])->toBe(1);
    Notification::assertSentTo($admins, LicenseExpiringSoon::class);
    app(ExpireLicenses::class)->handle();
    Notification::assertSentToTimes($admins->first(), LicenseExpiringSoon::class, 1);
});
```

```php
// LicenseWorkQueueSourceTest.php
test('a licence ending in the window (warning) and an expired one not yet handled (error) each raise one catalog item with a link to admin.licenses', function () {
    // seed one of each; assert pluck('severity') sorted is ['error','warning'], area 'catalog', ctaUrl route('admin.licenses').
});
```
- [ ] **Step 2: Run to verify it fails**: `vendor/bin/pest tests/Feature/Licensing`. Expected: page, controller, actions missing.
- [ ] **Step 3: Implement.**
  - Page: `Gate::authorize('access-admin')` in `boot()` and every action; `#[Locked]` on any id property; `use AdminTable` for search (licensor) and a status filter (active, expiring, expired) with `10` per page; territory input is a comma list, uppercased and validated with `Rule::in(Countries codes)` when `worldwide` is false; `agreement` is a `WithFileUploads` property validated `mimes:pdf|max:10240`, stored `->store('agreements', 'licenses')`; `mediaIds` validated `exists:media,id`. Save sets `approved_by = auth()->id()`, `approved_at = now()` (an admin creating it is the approval). Show the "Upload file" action per covered story, calling `UploadLicensedFile`.
  - `LicenseAgreementController` (`signed:relative` + the admin group's `can:access-admin`): streams from the `licenses` disk or 404.
  - `ExpireLicenses::handle`: (1) licences with `ends_on < today` and `expired_handled_at` null: for each licensed `ContentSource` of its media not covered by another `active()` licence, call `UnverifyContentSource`; stamp `expired_handled_at`; count unpublished sources. (2) licences with `ends_on` between today and `today + warn_days`, `expiry_warned_at` null: `Notification::send(User::where('is_admin', true)->get(), new LicenseExpiringSoon($license))` (mail + database, plain text: licensor, end date, story count, link to `admin.licenses`), stamp `expiry_warned_at`.
  - `LicenseWorkQueueSource::items()`: yields `WorkQueueItem` area `catalog`: severity `warning` for licences in the warning window (count, oldest = soonest `ends_on`, label `Licences ending soon`, cta `Review licences`), severity `error` for expired licences with `expired_handled_at` null (the job has not run yet), label `Expired licences not yet unpublished`. Register in `config('bookflix.admin_work_queue_sources')`.
  - Nav: add `Applications` and `Licenses` to the Catalog group items in `admin_navigation` and to the Phase 5 `x-admin.tabs` items of that group; routes `licenses` and `licenses/{license}/agreement` in `routes/admin.php`. Command scheduled `->dailyAt('01:10')`.
- [ ] **Step 4: Run to verify it passes**: `vendor/bin/pest tests/Feature/Licensing tests/Feature/Security tests/Feature/Admin tests/Feature/Reader && vendor/bin/pest tests/Browser/AdminShellTest.php`. Expected: PASS (Phase 1's admin-route sweep covers the new pages).
- [ ] **Step 5: Commit**: `git commit -m "feat: admin licences, licensed uploads, expiry job and pre-expiry alert"`

### Task 9.9: "Publish on Bookflix" page and the applications tab

**Files:**
- Create: `database/migrations/2026_11_27_120000_create_creator_applications_table.php`, `app/Models/CreatorApplication.php`, `database/factories/CreatorApplicationFactory.php`, `app/Http/Controllers/PublishController.php`, `app/Http/Requests/StoreCreatorApplicationRequest.php`, `resources/views/publish/show.blade.php`, `resources/views/publish/thanks.blade.php` (both on the landing layout, like Phase 3's `legal` views), `resources/views/pages/admin/applications/⚡index.blade.php`, `app/Platforms/CreatorApplicationWorkQueueSource.php`
- Modify: `routes/web.php` (public routes, outside the auth group), `routes/admin.php`, `app/Providers/AppServiceProvider.php` (limiter `publish-applications`), `config/bookflix.php` (`admin_work_queue_sources`, nav), `resources/views/components/landing/footer.blade.php` (one link "Publish on Bookflix" in the Account column)
- Test: `tests/Feature/Publish/PublishApplicationTest.php`, `tests/Feature/Admin/CreatorApplicationsTest.php`, `tests/Browser/PublishPageTest.php`

**Interfaces:** `creator_applications`: id, `name` string(120), `email` string(255), `works` text, `links` text nullable, `rights_held` text, `status` string default `pending` (`pending`, `contacted`, `accepted`, `declined`), `reviewed_by` FK users nullOnDelete nullable, `reviewed_at` timestamp nullable, `admin_note` text nullable, timestamps. `CreatorApplication::$fillable = name,email,works,links,rights_held`. Routes `publish` GET, `publish` POST (named `publish.store`? No: registry says `publish` GET/POST, so both verbs share the name `publish`: register the POST as `Route::post('publish', ...)` without a second name, and use `route('publish')` for the form action), `publish.thanks`. Admin route `admin.applications`.

- [ ] **Step 1: Write the failing tests**

```php
// PublishApplicationTest.php
test('the page is public and shows the form', function () {
    $this->get(route('publish'))->assertOk()->assertSee('Publish on Bookflix')->assertSee('name="works"', false)->assertSee('name="rights_held"', false);
});
test('a valid application is stored, then redirected to the thank-you page, which cannot be refreshed into a second post', function () {
    $this->post(route('publish'), ['name' => 'Alex Example', 'email' => 'alex@example.com', 'works' => 'A webcomic', 'links' => 'https://example.com', 'rights_held' => 'I own all rights', 'confirm' => '1'])
        ->assertRedirect(route('publish.thanks'));
    $this->assertDatabaseHas('creator_applications', ['email' => 'alex@example.com', 'status' => 'pending']);
    $this->get(route('publish.thanks'))->assertOk()->assertSee('Thanks');
    $this->get(route('publish.thanks'))->assertRedirect(route('publish')); // flash is gone: no direct hit
    expect(CreatorApplication::count())->toBe(1);
});
test('invalid input comes back with inline errors and keeps what was typed', function (array $bad, string $field) {
    $valid = ['name' => 'Alex', 'email' => 'alex@example.com', 'works' => 'W', 'rights_held' => 'R', 'confirm' => '1'];
    $this->from(route('publish'))->post(route('publish'), [...$valid, ...$bad])->assertRedirect(route('publish'))->assertSessionHasErrors($field);
    expect(CreatorApplication::count())->toBe(0);
})->with([
    'no email' => [['email' => ''], 'email'], 'bad email' => [['email' => 'nope'], 'email'],
    'no works' => [['works' => ''], 'works'], 'no rights' => [['rights_held' => ''], 'rights_held'],
    'too long' => [['works' => str_repeat('a', 5001)], 'works'], 'not confirmed' => [['confirm' => ''], 'confirm'],
]);
test('the sixth post in an hour from one address is throttled', function () {
    foreach (range(1, 5) as $i) { $this->post(route('publish'), [])->assertRedirect(); }
    $this->post(route('publish'), [])->assertStatus(429);
    $this->get(route('publish'))->assertOk(); // the GET page is never throttled
});
```

```php
// CreatorApplicationsTest.php
test('an admin sets a status and a note, stamping who and when, and a demoted admin is refused', function () {
    $admin = User::factory()->admin()->create(); $app = CreatorApplication::factory()->create();
    $page = Livewire::actingAs($admin)->test('pages::admin.applications.index');
    $page->call('review', $app->id, 'accepted', 'Follow up by email.');
    expect($app->fresh())->status->toBe('accepted')->reviewed_by->toBe($admin->id)->admin_note->toBe('Follow up by email.');
    $admin->forceFill(['is_admin' => false])->save();
    $page->call('review', $app->id, 'declined', '')->assertForbidden();
});
test('the work queue shows pending applications with the oldest age', function () {
    CreatorApplication::factory()->count(2)->create(['status' => 'pending']);
    $item = collect((new CreatorApplicationWorkQueueSource)->items())->first();
    expect($item->count)->toBe(2)->and($item->area)->toBe('catalog')->and($item->ctaUrl)->toBe(route('admin.applications'));
});
```

```php
// tests/Browser/PublishPageTest.php (pattern: tests/Browser/PrivacyPageTest.php)
// 1. At 375x667, 768x1024 and 1280x800: visit /publish, assert document.documentElement.scrollWidth <= window.innerWidth, assert the submit button is visible, and every input has an associated <label>.
// 2. Submit empty: assert inline error text appears next to "Email" (an element with role="alert" or aria-describedby on the input) and focus lands on the first invalid field.
// 3. Fill every field, submit, assert the URL ends with /publish/thanks and the heading contains "Thanks"; press browser back and assert the form is empty (no resubmission).
```
- [ ] **Step 2: Run to verify it fails**: `vendor/bin/pest tests/Feature/Publish tests/Feature/Admin/CreatorApplicationsTest.php`. Expected: route `publish` not defined.
- [ ] **Step 3: Implement.** `StoreCreatorApplicationRequest` rules: `name` required string max 120; `email` required `email:rfc` max 255; `works` required string max 5000; `links` nullable string max 2000; `rights_held` required string max 5000; `confirm` accepted. `PublishController::store` creates with the validated fields only, `redirect()->route('publish.thanks')->with('application_received', true)`; `thanks()` redirects to `publish` unless `session('application_received')`. Limiter `publish-applications`: `Limit::perHour(5)->by($request->ip())` applied only to the POST route via `throttle:publish-applications`. Views use the landing layout and `<x-cta>` primary (one red button), plain copy: heading "Publish on Bookflix", intro "Tell us about your work and the rights you hold. We read every application and reply by email." followed by `{{-- TODO(owner): response time, what happens next, licence terms; no contract text is written here --}}`. The confirm checkbox label: "I hold the rights to the works I list." Admin page: `AdminTable` (search by name and email, status filter, 10 per page), `review(int $id, string $status, string $note)` with `Gate::authorize('access-admin')` and `Rule::in(['pending','contacted','accepted','declined'])`, row detail shown escaped (`{{ }}` only). Source: pending count, `oldestAt` the oldest `created_at`, label `Creator applications`, severity `info`, cta `Review applications`.
- [ ] **Step 4: Run to verify it passes**: `vendor/bin/pest tests/Feature/Publish tests/Feature/Admin tests/Feature/Security tests/Feature/LandingTest.php && vendor/bin/pest tests/Browser/PublishPageTest.php tests/Browser/LandingTest.php tests/Browser/AdminShellTest.php`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: Publish on Bookflix application form and admin review tab"`

## Phase exit

1. `composer test` green (Pint, PHPStan level 7, Unit and Feature).
2. Run each touched browser file: `vendor/bin/pest tests/Browser/PlatformFollowTest.php tests/Browser/PublishPageTest.php tests/Browser/StoryPageTest.php tests/Browser/LibraryPageTest.php tests/Browser/HomeTest.php tests/Browser/AdminShellTest.php tests/Browser/LandingTest.php tests/Browser/NoHorizontalScrollTest.php` (the last file exists from Phase 2; add `/publish` to its route list).
3. `/code-review` or a whole-branch review on the most capable model, naming the five Review Focus items; one fix wave.
4. Ledger up to date: baseline results, the 13-row platform research table (all `pending owner`), P9-R6 key check, deferred minors, commit range per task.
5. PR against `main` (or the previous phase's tip while its PR is open, D2-24; rebase onto `origin/main` first); body ends with the Part I table. No `Co-Authored-By`, no AI attribution. Do not merge.

| Item | Expected status | Files |
|---|---|---|
| F7 platforms, registry of 13, unconfirmed hidden, branding as text | Fixed (every platform stays unconfirmed: Needs decision per platform) | `config/bookflix.php`, `PlatformRegistry`, `where-to-read.blade.php` |
| F7 `platform_links`, `user_follows`, D2-20 copy of "Where to read" rows | Fixed | migrations, `PlatformLink`, `UserFollow`, `CopyExternalLinks` |
| F7 one tap to the right episode, return prompt, manual progress | Fixed | `⚡platform-follow.blade.php`, `FollowProgress` |
| F7 new episodes, in-app notices, opt-in email | Fixed; official feeds Needs decision | `NewEpisodes`, `NewEpisodeAvailable`, release sources |
| F7 one library view and labels | Fixed | `ReadingPlace`, Library page, `ContinueReading` |
| D2-21 reader country; Part E followed-series privacy section | Fixed (legal wording `TODO(owner)`) | `Countries`, Settings page, `legal/sections/followed-series.blade.php` |
| F8 Publish page (PRG) and admin review tab | Fixed | `PublishController`, views, `admin/applications` |
| F8 `content_licenses`, pivot, private agreement, admin-only upload | Fixed | migration, `ContentLicense`, `UploadLicensedFile`, `licenses` disk |
| F8 enforcement, expiry job, pre-expiry alert | Fixed | `LicenseGate`, `ContentAccessGate`, `ExpireLicenses`, `LicenseWorkQueueSource` |
| F8 contracts not drafted | Already OK | none |
| Part I licensing and platform tests | Fixed | `tests/Feature/Licensing`, `tests/Feature/Platforms`, browser files above |
