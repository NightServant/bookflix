# Phase 6: Pages, SEO and trust Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every page a real title, description and share card, give every failure a Bookflix-styled page and a readable message, add breadcrumbs with structured data, and serve covers at sane sizes.

**Architecture:** One head partial (`partials/head.blade.php`) owns title, description, Open Graph, Twitter, manifest and theme colour; pages pass `title` and `description` through `layoutData`, and a route-name map in `config/bookflix.php` covers every other page. Error pages share `errors/layout.blade.php`, which touches no session, auth or database state (the 404 shelf query is wrapped). Breadcrumbs are one component that renders the trail and its `BreadcrumbList` JSON-LD with the request's CSP nonce. A single Livewire request hook replaces Livewire's raw error modal with a readable toast.

**Tech Stack:** Blade, Livewire 4 request hook (JS), Flux free toasts, GD (WebP present: `gd_info()['WebP Support']` is true locally), Pest 5 Feature + browser, Playwright CLI (already installed for browser tests) to render PNGs from SVG. No new dependency.

**Spec:** `docs/superpowers/specs/2026-11-26-cleanup-brief.md` C1 (privacy page excluded: Phase 3), C2, C3, and the C4 bullets "Success and error messages on every form" and "Vercel Analytics"; masterplan `docs/superpowers/plans/2026-11-26-bookflix-masterplan-2.md` Part F "Phase 6" and F.0 row 6.

**Branch:** `mp2-phase-6-pages-seo` · **Ledger:** `docs/superpowers/plans/mp2-phase-6-ledger.md` · **Depends on:** Phase 1 (429 throttles exist), Phase 3 (legal pages use the landing layout and its `description` prop), Phase 4 (`settings.edit`, new landing chrome), Phase 5 (admin nav config, `x-admin.tabs`). Phase 9 reuses `errors/layout` conventions for `publish.thanks`.

## Global Constraints

Every Global Constraint in the masterplan applies. Phase-specific:

- Title format `"{Page} | Bookflix"` (separator ` | `, site name from `config('app.name')`); story `"{title} by {author} | Bookflix"`; a page with no title is `Bookflix`.
- Meta description: plain text, at most 155 characters plus one `…`, cut on a word boundary (`Str::limit(..., 155, '…', preserveWords: true)`).
- og:image is `public/og-image.png`, 1200×630, original art, colours only from tokens (`#000000`, `#e50914`, `#ffffff`, `#b3b3b3`, `#414141`). No cover art in it (D2-11).
- `robots.txt` has no `Sitemap:` line (none exists) and no API paths (`routes/api.php` does not exist).
- Cover proxy `?w=` accepts exactly `160`, `320`, `480`; any other value is ignored (original served), so no arbitrary file is ever written.
- Error pages: no `auth()`, no `session()`, no Eloquent in the layout or in 403/419/429/500/503; only the 404 shelf query runs, inside `try/catch`.
- JSON-LD uses `@json($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)` and `nonce="{{ Vite::cspNonce() }}"`. No `{!! !!}`.
- Copy is plain, no heavy em-dash use. No contact details, no promises about when a service returns.
- Browser tests use built assets: run `npm run build` before any browser file.

## Review Focus

Six items (the first is new; the old five follow).

0. **A guest can run a write action on the now-public story page** (`add`, `save`, `saveRating`, `toggleCollection`, `sendReport`, ...) because Livewire's update request only re-runs `auth` when the route had it, or sees a private reviewer's name. → Task 6.0 (per-action guest refusals, review identity test).
1. **A story or series title containing `</script>` breaks out of the JSON-LD tag** and runs script on a page every reader opens. → Task 6.4 (escape test with a hostile title).
2. **The 500 or 503 page queries the database or session**, so exactly when the database is down the visitor gets a blank page. → Task 6.3 (a query-forbidding hook while rendering both).
3. **A server error on a Livewire form shows Livewire's raw HTML error modal** (a stack trace in debug, an unstyled page in production) or fails silently. → Task 6.7 (browser test asserts the toast and that `#livewire-error` is absent).
4. **A meta description cut mid-word, holding HTML or `&amp;`, or empty (`content=""`) for a story with no synopsis.** → Task 6.1 (exact trimming cases and the no-synopsis fallback).
5. **`?w=` on the cover proxy lets anyone fill the disk with one file per width.** → Task 6.6 (`?w=999` serves the original and writes nothing).

## Cross-phase interfaces

**Consumes:** `DeweyCounts::rootsWithCounts(?User)`, `DeweyHierarchy::ancestors()`, `x-subject-tile`, `x-cta`, `x-app-logo`, `Media::coverBackdropUrl()` pattern, `config('bookflix.admin_navigation')` (as Phase 5 left it), the landing layout's `description` prop (Phase 3 legal pages pass it).

**Produces (registry row 6, exact):**
- `<x-breadcrumbs :items="[[label, url|null], ...]" :leaf="?string" />`: visible trail plus JSON-LD. `resources/views/components/breadcrumbs.blade.php`.
- `resources/views/errors/{403,404,419,429,500,503}.blade.php` on `resources/views/errors/layout.blade.php` (`@extends('errors::layout')`, sections `title`, `code`, `heading`, `body`, `actions`).
- `partials/head.blade.php` accepts `$title`, `$description`, `$ogImage` (absolute URL, default `asset('og-image.png')`).
- `GET /covers/{media}?w=160|320|480` serves WebP, falls back to the original.
- Extra names (not in the registry, added here): `App\Support\MetaDescription::make(?string $text, int $limit = 155): ?string`, `App\Admin\AdminTrail::for(?string $routeName, ?string $pageTitle = null): array`, `Media::coverAlt(): string`, `Media::coverSrcset(): ?string`, config keys `bookflix.seo.default_description` and `bookflix.seo.descriptions` (route name to string).

## Rulings

- **P6-R1** The error layout is guest-neutral: header is the logo linking to `route('landing')` (which sends a signed-in reader home), no account menu. An unmatched URL runs no `web` middleware, so there is no session to read. *Cost if wrong:* a signed-in reader sees a plain header on an error page; low.
- **P6-R2** 404 "popular shelves" = `DeweyCounts::rootsWithCounts(null)` sorted by `stories_count` desc, top 4, as `x-subject-tile`. The guest count keeps mature titles out. *Cost if wrong:* counts differ from a reader's own; low.
- **P6-R3** Descriptions come from a route-name map (`bookflix.seo.descriptions`) plus a site default; story, series, shelf and collection pages build their own. *Cost:* two places to edit; low.
- **P6-R4** Story author in the title is the first creator by `pivot.position`; no creator, no " by ". *Cost:* a multi-author book shows one name; low.
- **P6-R5** (owner decision) Story pages are PUBLIC. `stories.show` (GET `stories/{slug}`) moves out of the `auth,verified,reader` group to its own route keeping the name, with middleware `reader` (it passes guests, and sends a signed-in admin to `admin.index` on a page view exactly as today) and `throttle:story-guest` (`RateLimiter::for('story-guest', fn ($r) => $r->user() ? Limit::none() : Limit::perMinute(120)->by($r->ip()))`). Series, explore and shelf pages stay sign-in only: for a guest every link to them goes to sign-in (the login redirect). The story's Read button points to `reader.show`; Phase 7 opens that route to guests, until then it sends them to sign-in. Guest reading is Phase 7's job. Mature and restricted stories follow `MatureAccess`; `ProhibitedContent` is never shown. Because Livewire only re-runs middleware the route had, every action on the page's components authorises itself. *Cost if wrong:* a guest-exposed write action or leaked identity; this is why Task 6.0 runs first and why guest exposure is Review Focus 1.
- **P6-R6** `srcset` and WebP apply to covers the proxy already serves (`cache` policy hosts, today MangaDex), not to hotlinked ones: resizing those would route every cover through our server. Proxying all covers is a **Needs decision**. GD WebP is present, so availability is not the blocker; AVIF is not supported by GD here and is not attempted. *Cost:* most covers stay full-size.
- **P6-R7** Livewire form server errors are handled once, by a request hook that toasts, not per form. Plain POST forms end on the 500 page. *Cost:* the message is a toast, not beside the field; low.
- **P6-R8** Server-error tests for Livewire forms are browser tests that make the action throw through `Livewire\on('call', ...)`. This assumes the Pest browser server runs in the test process (existing browser tests rely on `actingAs`, so it does); the baseline re-check confirms. *Cost:* if not, injection moves to an env flag.
- **P6-R9** Settings breadcrumbs: Not applicable (Phase 4 makes one `/settings` page). Admin breadcrumbs come from the admin nav config via `AdminTrail`. *Cost:* if Phase 5 renames config keys the trail test is adapted in Task 6.4.
- **P6-R10** Visible story breadcrumb keeps ending at the shelf (existing tests require the class as `aria-current`); the story title is added to the JSON-LD only (`leaf`). *Cost:* none for users.
- **P6-R11** Thank-you pages: Not applicable today (no contact, feedback or waitlist form; reports and reviews finish inline with a toast). Phase 9 builds `publish.thanks`.
- **P6-R12** Analytics: **Needs decision**, nothing added (no deployment target; D2-6). Options for the owner: Vercel Web Analytics if hosted on Vercel, else a self-hosted, cookie-free counter on the actual host. Adding one needs a privacy-policy line (Phase 3 text).
- **P6-R13** Copyright year: Already OK (`landing/footer.blade.php` uses `now()->year`; `tests/Feature/LandingTest.php:203` asserts it).

## Baseline re-check

Run first; record each result in the ledger.

- [ ] `php -m | grep -i -E 'gd|imagick'` and `php -r 'echo json_encode(gd_info()["WebP Support"]);'` prints `true`. If false, Task 6.6 becomes "Needs decision" and only `coverAlt` ships.
- [ ] Open `resources/views/partials/head.blade.php`, `layouts/{app,app/header,admin,landing,reader,assistant,auth,auth/split}.blade.php`: confirm only landing emits description/og tags and that `title` is still passed as `$title`.
- [ ] `ls resources/views/errors` is absent; `ls public` shows `favicon.ico favicon.svg apple-touch-icon.png robots.txt` and no manifest.
- [ ] `php artisan route:list` (full, not `--except-vendor`): list every GET page route Phases 3 to 5 added (`legal.privacy`, `legal.terms`, `settings.edit`, `admin.settings`); append them to the page datasets in Tasks 6.1 and 6.3.
- [ ] `grep -rn 'wire:submit\|<form' resources/views` and `grep -rn 'wire:click="save\|wire:click="update'`: re-derive the form table in Task 6.8 against Phases 4 and 5 (Settings toggles and admin tables change it). Record added or removed forms.
- [ ] `grep -rn '{!!' resources/views tests` and open any test that allow-lists raw output (Phase 1 Task 1.6): the JSON-LD uses `@json`, so nothing to add, but confirm.
- [ ] Open `config/bookflix.php` `admin_navigation` and `tests/Feature/Classification/ExploreTest.php:36`, `tests/Feature/Discovery/StoryPageTest.php:131,151,412` (the breadcrumb contracts Task 6.4 must keep).
- [ ] Confirm the browser server is in-process: in a scratch browser test, set `config(['x' => 1])` before `visit()` and read it from a route; (P6-R8).

## Tasks

### Task 6.0: Public story pages (runs first; the SEO tasks assume it)

**Files:**
- Modify: `routes/web.php` (line 29: take `stories/{slug}` out of the `auth,verified,reader` group, `->middleware(['reader', 'throttle:story-guest'])->name('stories.show')`), `app/Providers/AppServiceProvider.php` (the `story-guest` limiter next to the existing limiters), `pages/stories/⚡show.blade.php` (`recordVisit` already returns for guests; guest branches in the template), `components/⚡library-controls.blade.php`, `components/⚡story-reviews.blade.php`, `components/why-this.blade.php` call site, `public/robots.txt` (Task 6.2 owns the file: no `Disallow` covers `/stories/`, so add a test line only), `tests/Feature/Links/InternalLinksTest.php` (Phase 2's crawler: add `stories.show` with a seeded public story to its guest pages).
- Test: `tests/Feature/Stories/PublicStoryPageTest.php`, following `tests/Feature/Discovery/StoryPageTest.php`, `tests/Feature/Access/MatureGateTest.php` (`matureStory()`), `tests/Feature/Security/*` from Phase 1 (Livewire replay helpers).

**Interfaces:** Consumes `MatureAccess`, `Media::isGatedFor`, `ProhibitedContent`, `Gate use-reader-side`. Produces limiter `story-guest`; route `stories.show` public.

- [ ] **Step 1: Write the failing tests**

```php
test('a guest gets a normal story page and a signed-in admin is still sent to admin', function () {
    $media = fullMedia();
    $this->get(route('stories.show', $media->slug))->assertOk()->assertSee('Dune');
    $this->actingAs(User::factory()->admin()->create())->get(route('stories.show', $media->slug))->assertRedirect(route('admin.index'));
});

test('a mature story is gated for a guest and a prohibited one is a 404', function () {
    $this->get(route('stories.show', matureStory()->slug))->assertForbidden()->assertDontSee('Erotic Tale');
    // arrange a media row whose ProhibitedContent check matches, as MatureGateTest or the ProhibitedContent tests do:
    $this->get(route('stories.show', prohibitedStory()->slug))->assertNotFound();
});

test('the guest page has no write controls, no "Why this?", and sign-in prompts instead', function () {
    $html = $this->get(route('stories.show', fullMedia()->slug))->assertOk()->getContent();

    expect($html)->not->toContain('livewire:library-controls')->not->toContain('wire:click="add"')->not->toContain('Why this')
        ->and($html)->toContain('Sign in to add to your library')->and($html)->toContain(route('login'));
    expect(htmlQuery($html, '//a[contains(@href, "'.route('series.show', 'x').'")]')->length)->toBe(0); // series links point to sign-in for guests
});

test('a guest view records no interaction and queues no AI work', function () {
    Queue::fake(); Event::fake();
    $this->get(route('stories.show', fullMedia()->slug))->assertOk();

    expect(UserInteraction::count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('reviews are readable by a guest; a private profile shows a neutral name', function () {
    $media = fullMedia();
    $public = Review::factory()->for($media)->create();                 // reviewer with a public profile
    $private = Review::factory()->for($media)->create();                // set the profile visibility field the baseline re-check names to private
    $html = Livewire::test('story-reviews', ['mediaId' => $media->id])->html();
    // guest: name of $public shown, name of $private absent, "A Bookflix reader" shown instead
});

test('every action on the story page components refuses a guest', function (string $component, string $method, array $params) {
    $media = fullMedia();
    // replay as a guest: Livewire::test($component, [...])->call($method, ...$params) with no actor must assertForbidden() (or assertRedirect(route('login'))) and change no row
})->with([
    ['library-controls', 'add', []], ['library-controls', 'setStatus', ['reading']], ['library-controls', 'toggleFavorite', []], ['library-controls', 'remove', []],
    ['library-controls', 'logProgress', []], ['library-controls', 'saveRating', []], ['library-controls', 'clearRating', []],
    ['library-controls', 'toggleCollection', [1]], ['library-controls', 'createCollection', []],
    ['story-reviews', 'save', []], ['story-reviews', 'edit', []], ['story-reviews', 'delete', []], ['story-reviews', 'openReport', ['review', 1]], ['story-reviews', 'sendReport', []],
    // any other action found on the story page's components by grepping `public function` in them
]);

test('titles, description, canonical, share tags and breadcrumb data render for a guest', function () {
    $media = fullMedia();
    $html = $this->get(route('stories.show', $media->slug))->getContent();

    expect($html)->toContain('Dune by Frank Herbert | Bookflix')->toContain('<link rel="canonical" href="'.route('stories.show', $media->slug).'">')
        ->toContain('<meta name="description"')->toContain('og:image')->toContain('twitter:card')->toContain('"@type":"BreadcrumbList"');
});

test('robots.txt allows the story pages', function () {
    expect(file_get_contents(public_path('robots.txt')))->not->toContain('/stories');
});

test('guests are throttled to 120 story views a minute per IP, readers are not', function () {
    $slug = fullMedia()->slug;
    foreach (range(1, 120) as $i) { $this->get(route('stories.show', $slug))->assertOk(); }
    $this->get(route('stories.show', $slug))->assertStatus(429);
    $this->actingAs(User::factory()->create())->get(route('stories.show', $slug))->assertOk();
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/pest tests/Feature/Stories/PublicStoryPageTest.php`
Expected: FAIL (guest redirected to login; limiter missing).

- [ ] **Step 3: Implement**
  - Route and limiter as in P6-R5.
  - Story page template: for a guest (`auth()->guest()`), replace `<livewire:library-controls>` with an `x-cta` "Sign in to add to your library" (`route('login')`), hide `Why this?`, show series, shelf and similar-story links pointing to `route('login')` (they stay sign-in only), and the Read button to `route('reader.show', ...)`.
  - Guest guard in every public action of `library-controls` and `story-reviews`: first line `abort_unless(auth()->check(), 403)` (or the existing `Gate::authorize('use-reader-side')`, which already refuses `null` users: verify, and add the explicit guard where it does not). `canWrite` stays false for guests.
  - Review author for a guest: show the real name only when the reviewer's profile is public (field named in the baseline re-check), else `__('A Bookflix reader')` with no avatar photo. A signed-in reader's view is unchanged.
  - `config('bookflix.ai.enabled')` branches already read stored summaries only; add no job dispatch to a guest view (the test proves it).
  - Canonical link goes in the head partial (Task 6.1): `<link rel="canonical" href="{{ url()->current() }}">`.

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/pest tests/Feature/Stories tests/Feature/Discovery/StoryPageTest.php tests/Feature/Access tests/Feature/Social tests/Feature/Library tests/Feature/Links tests/Feature/Security`
Expected: PASS. Existing tests that expect a guest redirect from `stories.show` are updated to the new behaviour.

- [ ] **Step 5: Commit**: `git commit -m "feat: public story pages for guests with a per-IP throttle"`

### Task 6.1: Titles and meta descriptions on every page

**Files:**
- Create: `app/Support/MetaDescription.php`, `tests/Unit/MetaDescriptionTest.php`, `tests/Feature/Seo/PageMetaTest.php`
- Modify: `resources/views/partials/head.blade.php`; `resources/views/layouts/landing.blade.php` (delete its own description/og block, lines 11-16); `resources/views/layouts/app.blade.php` (pass `:description="$description ?? null"` to `layouts::admin` and `layouts::app.header`); `config/bookflix.php` (new `seo` array); `resources/views/pages/stories/⚡show.blade.php` (`render()` line 80), `pages/series/⚡show.blade.php` (line 43), `pages/explore/⚡show.blade.php` (line 32), `pages/collections/⚡show.blade.php` (line 64); `tests/Feature/Reader/ReaderPageTest.php:96`
- Test files to follow: `tests/Feature/Discovery/StoryPageTest.php` (arrange with `fullMedia()`), `tests/Feature/LandingTest.php`.

**Interfaces:** Produces `MetaDescription::make(?string $text, int $limit = 155): ?string`; head partial reads `$title`, `$description`; config `bookflix.seo.default_description` (string) and `bookflix.seo.descriptions` (route name to string).

- [ ] **Step 1: Write the failing tests**

```php
// tests/Unit/MetaDescriptionTest.php
use App\Support\MetaDescription;

test('blank input has no description', function (?string $text) {
    expect(MetaDescription::make($text))->toBeNull();
})->with([null, '', '   ', '<p></p>']);

test('a short synopsis is returned as is, tags stripped, entities decoded, whitespace squished', function () {
    expect(MetaDescription::make('A short synopsis.'))->toBe('A short synopsis.')
        ->and(MetaDescription::make("<p>Line one.</p>\n\n  Line   two."))->toBe('Line one. Line two.')
        ->and(MetaDescription::make('Fish &amp; Chips'))->toBe('Fish & Chips');
});

test('exactly 155 characters is kept; one more is cut with an ellipsis', function () {
    expect(MetaDescription::make(str_repeat('a', 155)))->toBe(str_repeat('a', 155))
        ->and(MetaDescription::make(str_repeat('a', 156)))->toBe(str_repeat('a', 155).'…');
});

test('a long text is cut on a word boundary, never mid-word', function () {
    // 60 words of "word": the cut keeps 30 whole words (149 characters) plus the ellipsis.
    expect(MetaDescription::make(str_repeat('word ', 60)))->toBe(rtrim(str_repeat('word ', 30)).'…');
    // A word that straddles character 155 is dropped whole.
    expect(MetaDescription::make(str_repeat('a', 150).' bbbbbbbb ccc'))->toBe(str_repeat('a', 150).'…');
});
```

```php
// tests/Feature/Seo/PageMetaTest.php  (helper: signIn(string $actor) returns the acting user)
test('every page title is "{Page} | Bookflix" and carries a description', function (string $route, string $actor) {
    $user = match ($actor) { 'guest' => null, 'admin' => User::factory()->admin()->create(), 'unverified' => User::factory()->unverified()->create(), default => User::factory()->create() };
    $user && $this->actingAs($user);
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $html = $this->get(route($route))->assertOk()->getContent();

    expect($html)->toMatch('#<title>\s*[^<|]+ \| Bookflix\s*</title>#')->not->toContain(' - Bookflix</title>')
        ->and($html)->toContain('<meta name="description" content="'.e(config("bookflix.seo.descriptions.{$route}", config('bookflix.seo.default_description'))).'">');
})->with([
    'landing' => ['landing', 'guest'], 'login' => ['login', 'guest'], 'register' => ['register', 'guest'], 'forgot' => ['password.request', 'guest'],
    'verify' => ['verification.notice', 'unverified'],
    'home' => ['home', 'reader'], 'search' => ['search', 'reader'], 'discover' => ['discover', 'reader'], 'explore' => ['explore.index', 'reader'],
    'library' => ['library.index', 'reader'], 'upload' => ['library.upload', 'reader'], 'collections' => ['collections.index', 'reader'],
    'history' => ['history.index', 'reader'], 'profile' => ['profile.show', 'reader'], 'assistant' => ['assistant', 'reader'],
    'profile settings' => ['profile.edit', 'reader'], 'preferences' => ['preferences.edit', 'reader'], 'privacy settings' => ['privacy.edit', 'reader'], 'security' => ['security.edit', 'reader'],
    'admin' => ['admin.index', 'admin'], 'admin classifications' => ['admin.classifications', 'admin'], 'admin content' => ['admin.content', 'admin'], 'admin duplicates' => ['admin.duplicates', 'admin'],
    'admin media' => ['admin.media.index', 'admin'], 'admin models' => ['admin.models', 'admin'], 'admin sync' => ['admin.providers', 'admin'],
    'admin reports' => ['admin.moderation', 'admin'], 'admin staff picks' => ['admin.staff-picks', 'admin'], 'admin users' => ['admin.users', 'admin'],
    // append here the page routes Phases 3 to 5 added (baseline re-check)
]);

test('a story is titled "{title} by {author} | Bookflix" and described by its synopsis', function () {
    $media = fullMedia();   // Dune by Frank Herbert, "A desert planet and the spice that rules it."
    $html = $this->actingAs(User::factory()->create())->get(route('stories.show', $media->slug))->assertOk()->getContent();

    expect($html)->toContain('Dune by Frank Herbert | Bookflix')
        ->and($html)->toContain('<meta name="description" content="A desert planet and the spice that rules it.">');
});

test('a story with no creator is titled "{title} | Bookflix" and one with no synopsis gets a fallback description, never an empty one', function () {
    $media = Media::factory()->create(['title' => 'Plain Tale', 'description' => null]);
    $html = $this->actingAs(User::factory()->create())->get(route('stories.show', $media->slug))->assertOk()->getContent();

    expect($html)->toContain('<title>')->toContain('Plain Tale | Bookflix')->not->toContain(' by  |')->not->toContain('content=""')
        ->and($html)->toContain('<meta name="description" content="Plain Tale on Bookflix.');
});

test('a long synopsis is trimmed to 155 characters on a word boundary in the story description', function () {
    $media = Media::factory()->create(['description' => str_repeat('word ', 60)]);
    preg_match('/<meta name="description" content="([^"]*)"/', $this->actingAs(User::factory()->create())->get(route('stories.show', $media->slug))->getContent(), $m);

    expect($m[1])->toBe(rtrim(str_repeat('word ', 30)).'…');
});

test('series, shelf and collection pages name themselves in the title', function () {
    $this->seed(DeweySeeder::class);
    $this->actingAs(User::factory()->create());
    $series = Series::factory()->create(['title' => 'Dune Chronicles', 'description' => 'Six novels.']);

    expect($this->get(route('series.show', $series->slug))->getContent())->toContain('Dune Chronicles | Bookflix')->toContain('content="Six novels."')
        ->and($this->get(route('explore.show', '813'))->getContent())->toContain('813 American Fiction | Bookflix');
});

test('og and twitter titles use the same formatted title', function () {
    expect($this->get(route('login'))->getContent())->toContain('<meta property="og:title" content="Log in | Bookflix">');
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/pest tests/Unit/MetaDescriptionTest.php tests/Feature/Seo/PageMetaTest.php`
Expected: FAIL, `MetaDescription` class not found; titles still `... - Bookflix`.

- [ ] **Step 3: Implement**
  - `MetaDescription::make`: `strip_tags`, `html_entity_decode`, `Str::of(...)->squish()`, return null when empty, else `->limit($limit, '…', preserveWords: true)->toString()`.
  - Head partial: `$formatted = filled($title ?? null) ? $title.' | '.config('app.name') : config('app.name')`; `$desc = filled($description ?? null) ? $description : config('bookflix.seo.descriptions.'.request()->route()?->getName(), config('bookflix.seo.default_description'))`; emit `<title>`, `<meta name="description">`, `og:title`, `og:description`, `og:type` (`website`), `og:site_name` (Task 6.2 adds image, twitter and manifest). Landing layout keeps its `description` prop and passes it into the head include.
  - `config/bookflix.php` `seo`: `default_description` = "Bookflix keeps every comic, manga, novel and webcomic you read on one calm shelf, filed the way a library would." plus one plain sentence per route in the dataset above, written from what that page does (no numbers, no promises).
  - Story `render()`: title `$media->title.($author ? ' by '.$author : '')`, description `MetaDescription::make($media->description) ?? __(':title on Bookflix. See its summary, its series and where it sits in the library.', ...)`, both via `->title()` and `layoutData([... 'description' => ...])`. Series: title as now, description `MetaDescription::make($series->description) ?? __('Every volume of :title in reading order.')`. Explore show: `->title($code.' '.$name)` and `__('Stories shelved under :class in the Bookflix library, from its sections down to single stories.')`. Collection: description from `MetaDescription::make($collection->description)` else `__('A collection of stories on Bookflix.')`.
  - `ReaderPageTest.php:96`: change the regex to `'/<title>\s*The Open Book \| Bookflix/'`.

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/pest tests/Unit/MetaDescriptionTest.php tests/Feature/Seo tests/Feature/LandingTest.php tests/Feature/Reader/ReaderPageTest.php tests/Feature/CspTest.php`
Expected: PASS. If `LandingTest` pins the old landing og block, update it to read the head's output.

- [ ] **Step 5: Commit**: `git commit -m "feat: titles and meta descriptions on every page"`

### Task 6.2: Share card, manifest and robots

**Files:**
- Create: `resources/images/og-image.svg` (source), `public/og-image.png`, `public/icon-192.png`, `public/icon-512.png`, `public/site.webmanifest`, `tests/Feature/Seo/ShareAndRobotsTest.php`
- Modify: `resources/views/partials/head.blade.php`, `public/robots.txt`

**Interfaces:** head accepts `$ogImage`; produces `/site.webmanifest`, `/og-image.png`.

- [ ] **Step 1: Write the failing tests**

```php
test('the head carries Open Graph and Twitter card tags with the site image', function () {
    $html = $this->get(route('login'))->getContent();

    expect($html)->toContain('<meta property="og:image" content="'.asset('og-image.png').'">')
        ->toContain('<meta property="og:image:width" content="1200">')->toContain('<meta property="og:image:height" content="630">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->toContain('<meta name="twitter:image" content="'.asset('og-image.png').'">')
        ->toContain('<meta property="og:site_name" content="Bookflix">')
        ->toContain('<link rel="manifest" href="/site.webmanifest">')
        ->toContain('<meta name="theme-color" content="#000000">');
});

test('og-image.png is 1200 by 630 and its source uses only token colours', function () {
    expect(getimagesize(public_path('og-image.png'))[0].'x'.getimagesize(public_path('og-image.png'))[1])->toBe('1200x630')
        ->and(filesize(public_path('og-image.png')))->toBeLessThan(300_000);

    preg_match_all('/#[0-9a-fA-F]{6}\b/', file_get_contents(resource_path('images/og-image.svg')), $found);
    expect(array_diff(array_map('strtolower', array_unique($found[0])), ['#000000', '#e50914', '#ffffff', '#b3b3b3', '#414141']))->toBe([]);
});

test('the manifest names the app and every icon it lists exists at its size', function () {
    $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)->toMatchArray(['name' => 'Bookflix', 'short_name' => 'Bookflix', 'start_url' => '/', 'display' => 'standalone', 'background_color' => '#000000', 'theme_color' => '#000000']);

    foreach ($manifest['icons'] as $icon) {
        expect(public_path(ltrim($icon['src'], '/')))->toBeFile();
        $icon['type'] === 'image/png' && expect(implode('x', array_slice(getimagesize(public_path(ltrim($icon['src'], '/'))), 0, 2)))->toBe($icon['sizes']);
    }
});

test('robots.txt blocks private and auth paths, names no sitemap, and leaves public pages open', function () {
    $lines = array_map('trim', file(public_path('robots.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

    expect($lines)->toBe(['User-agent: *', 'Disallow: /admin', 'Disallow: /settings', 'Disallow: /library', 'Disallow: /login', 'Disallow: /register', 'Disallow: /forgot-password', 'Disallow: /reset-password', 'Disallow: /two-factor-challenge', 'Disallow: /user/', 'Disallow: /email/', 'Disallow: /passkeys/', 'Disallow: /livewire']);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/pest tests/Feature/Seo/ShareAndRobotsTest.php`
Expected: FAIL (missing tags, files, robots lines).

- [ ] **Step 3: Implement**
  - `og-image.svg` (1200×630): `<rect fill="#000000">`; the logo mark from `public/favicon.svg` scaled to 160 px at (96, 235); wordmark "Bookflix" 96 px bold `#ffffff` at x 300; tagline "Your Library. Every Story." 40 px `#b3b3b3`; a 2 px `#414141` rule above the bottom margin. System font stack `Helvetica Neue, Arial, sans-serif` (Netflix Sans is never used).
  - Render: `npx playwright screenshot --viewport-size=1200,630 "file://$PWD/resources/images/og-image.svg" public/og-image.png`; icons: same command on `public/favicon.svg` with `--viewport-size=192,192` and `512,512`. Open each PNG with the Read tool and confirm it is not blank and the mark is not clipped.
  - `site.webmanifest`: fields from the test, `icons`: `/favicon.svg` (`any`, `image/svg+xml`), `/icon-192.png`, `/icon-512.png`, `/apple-touch-icon.png` (180x180).
  - Head: add `og:image`, size tags, `og:url` (`url()->current()`), `twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`, manifest link, `theme-color`. `$ogImage ?? asset('og-image.png')`.
  - `robots.txt`: exactly the lines in the test.

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/pest tests/Feature/Seo tests/Feature/CspTest.php tests/Feature/Components/NoRawColorsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**: `git commit -m "feat: share image, web manifest and robots rules"`

### Task 6.3: Error pages that work with and without a database

**Files:**
- Create: `resources/views/errors/{layout,403,404,419,429,500,503}.blade.php`, `tests/Feature/Http/ErrorPagesTest.php`, `tests/Browser/ErrorPagesTest.php`
- Test files to follow: `tests/Feature/Http/SecurityHeadersTest.php` (guest requests), `tests/Browser/LayoutTest.php` (viewport options).

**Interfaces:** Consumes `DeweyCounts`, `x-subject-tile`, `x-cta`, `x-app-logo`, `partials.head`. Produces the six views.

- [ ] **Step 1: Write the failing tests**

```php
beforeEach(function () {
    config(['app.debug' => false]);   // debug renders Laravel's own page instead of ours
    Route::middleware('web')->group(function () {
        Route::get('/__abort/{status}', fn (int $status) => abort($status))->whereNumber('status');
        Route::get('/__retry', fn () => abort(429, '', ['Retry-After' => 30]));
        Route::get('/__boom', fn () => throw new RuntimeException('boom'));
        Route::get('/__down', fn () => throw new PDOException('connection refused'));
    });
});

dataset('errors', [403 => [403, "You can't open this page"], 404 => [404, "We can't find that page"], 419 => [419, 'This page has expired'], 429 => [429, 'Too many requests'], 500 => [500, 'Something went wrong on our side'], 503 => [503, 'Bookflix is briefly unavailable']]);

test('each error page renders for a guest and a signed-in reader with a way home', function (int $status, string $heading) {
    foreach ([null, User::factory()->create()] as $user) {
        $user && $this->actingAs($user);
        $this->get("/__abort/{$status}")->assertStatus($status)->assertSee($heading)->assertSee(route('landing'), false)
            ->assertSee('| Bookflix</title>', false);
    }
})->with('errors');

test('429 says how long to wait when the response says so', function () {
    $this->get('/__retry')->assertStatus(429)->assertSee('30 seconds');
});

test('500 and 503 render without a single database query', function (string $uri) {
    $user = User::factory()->create();
    DB::beforeExecuting(fn () => throw new LogicException('an error page ran a query'));

    $this->actingAs($user)->get($uri)->assertStatus(str_contains($uri, '503') ? 503 : 500)->assertSee('Go home');
})->with(['/__boom', '/__down', '/__abort/503']);

test('an unknown address is a styled 404 for a guest, with search and a home link', function () {
    $html = $this->get('/no-such-page')->assertNotFound()->assertSee("We can't find that page")->getContent();

    expect(htmlQuery($html, '//form[@method="get"][contains(@action, "/search")]//input[@name="q"]')->length)->toBe(1);
});

test('a missing story slug is a styled 404 for a signed-in reader', function () {
    $this->actingAs(User::factory()->create())->get(route('stories.show', 'no-such-story'))->assertNotFound()->assertSee("We can't find that page");
});

test('the 404 shelves come from a real query and never count mature titles for a guest', function () {
    $this->seed(DeweySeeder::class);
    classified(Media::factory()->create(), '813');
    classified(matureStory(), '813');

    $this->get('/no-such-page')->assertNotFound()->assertSee(route('explore.show', '800'), false)->assertSee('1 story');
});

test('the 404 still renders when the shelf query fails', function () {
    DB::beforeExecuting(fn () => throw new PDOException('down'));

    $this->get('/no-such-page')->assertNotFound()->assertSee("We can't find that page")->assertDontSee('Popular shelves');
});

test('JSON callers keep getting JSON', function () {
    $this->getJson('/__abort/404')->assertNotFound()->assertJsonStructure(['message']);
});
```

Browser (`tests/Browser/ErrorPagesTest.php`): `visit('/no-such-page', ['viewport' => ['width' => 375, 'height' => 667]])->assertSee("We can't find that page")` and `->assertScript('document.documentElement.scrollWidth <= window.innerWidth')`, plus the same at 1280×800.

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/pest tests/Feature/Http/ErrorPagesTest.php`
Expected: FAIL (Laravel's default pages: wrong headings, no home link).

- [ ] **Step 3: Implement**
  - `layout.blade.php`: `<!DOCTYPE html>` shell like `layouts/reader.blade.php` (dark class, `@include('partials.head', ['title' => trim($__env->yieldContent('title')), 'description' => trim($__env->yieldContent('heading'))])`, `@livewireScriptConfig`), skip link, `<main id="main" tabindex="-1">` with `x-app-logo :href="route('landing')"`, a `text-legal text-ink-subtle` code line, `<h1 class="text-section md:text-title">`, a `text-body text-ink-subtle` paragraph, an actions row (`x-cta` primary "Go home" to `route('landing')`) and an optional `body` section. Tokens only, no shadows, no new colours.
  - Pages (copy, plain): 403 "You can't open this page" / "Your account does not have access to this page. If you expected to, check you are signed in with the right account." 404 "We can't find that page" / "The link may be old or mistyped." 419 "This page has expired" / "Your session timed out. Go back, refresh the page and try again." 429 "Too many requests" / "Wait about `{{ $exception->getHeaders()['Retry-After'] ?? 60 }} seconds`, then try again." (render "N seconds"). 500 "Something went wrong on our side" / "It is not you. Try again in a moment." 503 "Bookflix is briefly unavailable" / "We are doing maintenance. Try again in a few minutes."
  - 404 only: a `<form method="get" action="{{ route('search') }}" role="search">` with a labelled `q` input (`flux:input`) and `x-cta variant="secondary"` "Search"; then `@php try { $shelves = app(DeweyCounts::class)->rootsWithCounts(null)->sortByDesc('stories_count')->take(4); } catch (Throwable) { $shelves = collect(); } @endphp` and, when not empty, a "Popular shelves" `<h2>` with `x-subject-tile` in a grid. No Eloquent or `auth()` anywhere else.

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/pest tests/Feature/Http tests/Feature/Components/NoRawColorsTest.php tests/Feature/Access && npm run build && vendor/bin/pest tests/Browser/ErrorPagesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**: `git commit -m "feat: Bookflix error pages for 403, 404, 419, 429, 500 and 503"`

### Task 6.4: Breadcrumbs with structured data, on story, series, shelf and admin pages

**Files:**
- Create: `resources/views/components/breadcrumbs.blade.php`, `app/Admin/AdminTrail.php`, `tests/Feature/Components/BreadcrumbsTest.php`, `tests/Feature/Seo/BreadcrumbTrailsTest.php`
- Modify: `resources/views/components/dewey-breadcrumb.blade.php` (becomes a wrapper), `pages/stories/⚡show.blade.php` (line 339: add `:leaf`), `pages/series/⚡show.blade.php` (header, above the `Series` eyebrow), `resources/views/layouts/admin.blade.php` (`<main>`, before `{{ $slot }}`)
- Test files to follow: `tests/Feature/Discovery/StoryPageTest.php:131-155,412-419` (must keep passing), `tests/Feature/Classification/ExploreTest.php:36`, `tests/Feature/CspTest.php` (`nonceOf`; name the new helper `ldNonce`, since Pest helpers are global).

**Interfaces:** Produces `<x-breadcrumbs :items :leaf />`; `AdminTrail::for(?string $routeName, ?string $pageTitle = null): list<array{0: string, 1: ?string}>`. `dewey-breadcrumb` keeps its props and markup contract (root without `sm:flex`, middle crumbs `hidden sm:flex`, `sm:hidden` ellipsis, last crumb `aria-current="page"`).

- [ ] **Step 1: Write the failing tests**

```php
// BreadcrumbsTest.php: renderedComponent()/htmlQuery() as in other component tests
test('the trail renders a nav with the last crumb as the current page and the middle ones collapsible', function () {
    $html = renderedComponent('<x-breadcrumbs :items="$items" />', ['items' => [['Home', '/home'], ['A', '/a'], ['B', '/b'], ['Now', null]]]);

    expect(htmlQuery($html, '//nav[@aria-label="Breadcrumb"]//li//a')->length)->toBe(3)
        ->and(htmlQuery($html, '//nav//*[@aria-current="page"][normalize-space()="Now"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//nav//li[contains(@class,"sm:flex")][.//a]')->length)->toBe(2)
        ->and(htmlQuery($html, '//nav//li[contains(@class,"sm:hidden")][normalize-space()="…"]')->length)->toBe(1);
});

test('empty items render nothing and a two-item trail has no ellipsis', function () {
    expect(trim(renderedComponent('<x-breadcrumbs :items="[]" />')))->toBe('');
    expect(htmlQuery(renderedComponent('<x-breadcrumbs :items="$i" />', ['i' => [['Home', '/h'], ['Now', null]]]), '//li[contains(@class,"sm:hidden")]')->length)->toBe(0);
});
```

```php
// BreadcrumbTrailsTest.php
function ldBlocks(string $html): array
{
    preg_match_all('#<script type="application/ld\+json" nonce="([^"]*)">(.*?)</script>#s', $html, $m, PREG_SET_ORDER);

    return array_map(fn ($b) => ['nonce' => $b[1], 'data' => json_decode($b[2], true, flags: JSON_THROW_ON_ERROR)], $m);
}
function ldNames(array $block): array { return array_column($block['data']['itemListElement'], 'name'); }

test('a story page lists Explore, the Dewey path and the story in JSON-LD, with this request\'s nonce', function () {
    $media = fullMedia();
    $response = $this->actingAs(User::factory()->create())->get(route('stories.show', $media->slug))->assertOk();
    preg_match("/script-src [^;]*'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $csp);
    [$block] = ldBlocks($response->getContent());

    expect($block['nonce'])->toBe($csp[1])
        ->and($block['data']['@type'])->toBe('BreadcrumbList')
        ->and(ldNames($block))->toBe(['Explore the Library', '800 Literature', '810 American Literature', '813 American Fiction', 'Dune'])
        ->and(array_column($block['data']['itemListElement'], 'position'))->toBe([1, 2, 3, 4, 5])
        ->and($block['data']['itemListElement'][1]['item'])->toBe(route('explore.show', '800'))
        ->and($block['data']['itemListElement'][4])->not->toHaveKey('item');
});

test('a title with a closing script tag cannot break out of the JSON-LD', function () {
    $media = fullMedia();
    $media->update(['title' => '</script><script>alert(1)</script>']);
    $html = $this->actingAs(User::factory()->create())->get(route('stories.show', $media->fresh()->slug))->getContent();

    expect($html)->not->toContain('</script><script>alert(1)')
        ->and(ldNames(ldBlocks($html)[0]))->toContain('</script><script>alert(1)</script>');
});

test('shelf and series pages have their trails', function () {
    $this->seed(DeweySeeder::class);
    $series = Series::factory()->create(['title' => 'Dune Chronicles']);
    $this->actingAs(User::factory()->create());

    expect(ldNames(ldBlocks($this->get(route('explore.show', '813'))->getContent())[0]))->toBe(['Explore the Library', '800 Literature', '810 American Literature', '813 American Fiction'])
        ->and(ldNames(ldBlocks($this->get(route('series.show', $series->slug))->getContent())[0]))->toBe(['Home', 'Dune Chronicles']);
});

test('admin pages show Admin, the group and the page', function () {
    $this->actingAs(User::factory()->admin()->create());

    expect(ldNames(ldBlocks($this->get(route('admin.users'))->getContent())[0]))->toBe(['Admin', 'People', 'Users'])
        ->and(ldNames(ldBlocks($this->get(route('admin.index'))->getContent())[0]))->toBe(['Admin'])
        ->and(AdminTrail::for('not.admin'))->toBe([]);
});
```
(Expected admin labels follow `admin_navigation` as of the baseline re-check; adapt them to Phase 5's config in the same commit.)

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/pest tests/Feature/Components/BreadcrumbsTest.php tests/Feature/Seo/BreadcrumbTrailsTest.php`
Expected: FAIL (component and class missing).

- [ ] **Step 3: Implement**
  - `x-breadcrumbs`: move the `dewey-breadcrumb` markup here, generic over `items`: first item always shown, last item `aria-current` span, middle items `hidden items-center sm:flex`, one `sm:hidden` ellipsis `li` when a middle exists; `@props(['items', 'leaf' => null])`; renders nothing when `$items === []`. Then `<script type="application/ld+json" nonce="{{ Vite::cspNonce() }}">@json($schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)</script>` where `$schema` is `BreadcrumbList` with `ListItem` positions from 1, `item` = `url($url)` for crumbs with a url, none for url-less ones; `leaf` appends a final name-only item.
  - `dewey-breadcrumb`: build `[[__('Explore the Library'), route('explore.index')], ...ancestors as ["{code} {name}", route('explore.show', code)], ["{code} {name}", null]]` and render `<x-breadcrumbs :items :leaf="$leaf" {{ $attributes }} />`; add a `leaf` prop passed through. Story page passes `:leaf="$media->title"`.
  - Series: `<x-breadcrumbs :items="[[__('Home'), route('home')], [$series->title, null]]" />` above the eyebrow.
  - `AdminTrail::for`: find the nav item whose `active` patterns `Str::is` the route; none gives `[]`; the Overview item gives `[['Admin', null]]`; else `['Admin', route('admin.index')]`, `[group, route(first item of the group)]`, `[item label, route(item) or null when the current route is the item's own]`, plus `[$pageTitle, null]` when the route is deeper (for example `admin.media.edit`).
  - Admin layout: `<x-breadcrumbs :items="\App\Admin\AdminTrail::for(request()->route()?->getName(), $title ?? null)" class="pt-4" />` before the slot.

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/pest tests/Feature/Components tests/Feature/Seo tests/Feature/Discovery/StoryPageTest.php tests/Feature/Discovery/SeriesPageTest.php tests/Feature/Classification tests/Feature/Admin tests/Feature/CspTest.php`
Expected: PASS (the existing story breadcrumb contract unchanged).

- [ ] **Step 5: Commit**: `git commit -m "feat: breadcrumbs with BreadcrumbList data on story, series, shelf and admin pages"`

### Task 6.5: Internal links between story, series, shelf and similar stories

**Files:**
- Modify: `resources/views/pages/series/⚡show.blade.php` (header, under the description, around line 160), `resources/views/pages/stories/⚡show.blade.php` (remove the stale `$linkSeries = Route::has(...)` comment and guard)
- Test: `tests/Feature/Seo/InternalLinksTest.php`, following `tests/Feature/Discovery/StoryPageTest.php` (arrange `fullMedia()` plus the similar-stories fixture used there).

Audit (record in ledger): story to series, to shelf (breadcrumb and "Also shelved in") and to similar stories already link; series to volume links; shelf to story links. Missing today: series to shelf, shelf to series. Author pages wait on open question 6 (creators keep linking to search).

- [ ] **Step 1: Write the failing tests**

```php
test('a story page links to its series, its shelf and a similar story', function () {
    $media = fullMedia();          // series "Dune Chronicles", class 813
    $neighbour = Media::factory()->create(['title' => 'Dune Messiah Two']);
    // arrange a media_similarity row exactly as StoryPageTest's similar-stories test does (activeVersion + similar())
    $html = $this->actingAs(User::factory()->create())->get(route('stories.show', $media->slug))->assertOk()->getContent();

    foreach ([route('series.show', $media->series->first()->slug), route('explore.show', '813'), route('stories.show', $neighbour->slug)] as $href) {
        expect(htmlQuery($html, '//a[@href="'.$href.'"]')->length)->toBeGreaterThan(0);
    }
});

test('a series page links to the shelf of its first volume', function () {
    $media = fullMedia();
    $html = $this->actingAs(User::factory()->create())->get(route('series.show', $media->series->first()->slug))->getContent();

    expect(htmlQuery($html, '//a[@href="'.route('explore.show', '813').'"][contains(., "813 American Fiction")]')->length)->toBeGreaterThan(0);
});

test('a series whose first volume has no public class shows no shelf link', function () {
    $series = Series::factory()->create();
    Media::factory()->create()->series()->attach($series, ['position' => 1]);

    expect($this->actingAs(User::factory()->create())->get(route('series.show', $series->slug))->getContent())->not->toContain('Shelved in');
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/pest tests/Feature/Seo/InternalLinksTest.php`
Expected: the series-to-shelf test FAILS; the story test passes (documents the existing links).

- [ ] **Step 3: Implement**: in the series header, when `$first?->deweyClasses()->public()->primary()` resolves a class (reuse the story page's `publicPrimaryDewey` query shape), render `<p class="mt-2 text-meta text-ink-subtle">{{ __('Shelved in') }} <a href="{{ route('explore.show', $class->code) }}" wire:navigate class="focus-ring text-link hover:underline">{{ $class->code }} {{ $class->name }}</a></p>`. Load it once in a `#[Computed]` on the series page.

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/pest tests/Feature/Seo tests/Feature/Discovery/SeriesPageTest.php tests/Feature/Discovery/StoryPageTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**: `git commit -m "feat: series pages link to their shelf"`

### Task 6.6: Cover alt text, and WebP with srcset through the cover proxy

**Files:**
- Modify: `app/Models/Media.php` (after `coverBackdropUrl()`, line ~170), `app/Http/Controllers/CoverController.php`, `resources/views/components/cover-tile.blade.php` (the `<img>`, line 61), `resources/views/pages/stories/⚡show.blade.php` (the `x-book-3d` call, line 346: add `:alt="$media->coverAlt()"`), `resources/views/pages/profile/⚡show.blade.php` (line 513: `alt="{{ $entry->media->coverAlt() }}"`)
- Test: `tests/Feature/Components/CoverAltTextTest.php`, additions to `tests/Feature/Http/CoverProxyTest.php` (arrange like its backdrop test, line 106), `tests/Feature/Components/CoverTileTest.php`.

**Interfaces:** `Media::coverAlt(): string`: `Cover of :title by :author` when `creators` is loaded and non-empty (first by `pivot.position`), else `Cover of :title`; never queries. `Media::coverSrcset(): ?string`: `"{url} 160w, {url} 320w, {url} 480w"` with `route('covers.show', ['media' => $id, 'v' => ..., 'w' => 160|320|480], false)`, or null when the cover is hotlinked (same condition as `coverBackdropUrl()`). Controller: `?w=` in `[160, 320, 480]` serves the stored cover scaled to that width as WebP (quality 80, kept beside the original as `{path}-w{w}`, made once); other values ignored.

Alt audit (D2-10, record in ledger): `alt=""` stays where the title is visible in the same link or button (cover-tile, result-row, series-item, collection-row/card, story-summary, discover, history, collections show, finished, staff picks, hero upcoming cards) and on blurred backdrops, landing pictures and the series fan (decorative or duplicated by the heading). `Cover of {title} by {author}` goes where the image stands alone: the story page's 3D book and profile line 513 (today `alt="{{ title }}"`).

- [ ] **Step 1: Write the failing tests**

```php
test('a cover standing alone reads "Cover of {title} by {author}"; without creators loaded it reads "Cover of {title}"', function () {
    $media = fullMedia()->load('creators');

    expect($media->coverAlt())->toBe('Cover of Dune by Frank Herbert')
        ->and(Media::find($media->id)->coverAlt())->toBe('Cover of Dune');
});

test('the story page book carries that alt text', function () {
    $media = fullMedia();
    $media->update(['cover_url' => '/favicon.svg']);
    $html = $this->actingAs(User::factory()->create())->get(route('stories.show', $media->slug))->getContent();

    expect(htmlQuery($html, '//img[@x-ref="cover"][@alt="Cover of Dune by Frank Herbert"]')->length)->toBe(1);
});

test('a cover tile keeps alt empty because its title is in the same link', function () {
    $html = renderedComponent('<x-cover-tile :media="$m" />', ['m' => Media::factory()->create(['cover_url' => '/favicon.svg', 'title' => 'Dune'])]);

    expect(htmlQuery($html, '//a[contains(., "Dune")]//img[@alt=""]')->length)->toBe(1);
});

test('no image anywhere in the views lacks an alt attribute', function () {
    $missing = collect(File::allFiles(resource_path('views')))->filter(fn ($f) => preg_match('/<img\b(?![^>]*\balt=)[^>]*>/s', file_get_contents($f)))->map->getRelativePathname()->all();

    expect($missing)->toBe([]);
});
```
```php
// CoverProxyTest.php additions (Storage::fake('local'), Http::fake of a PNG host as in the backdrop test)
test('w=320 serves the cover as a 320px WebP kept beside it and made once', function () { /* assert Content-Type image/webp, getimagesizefromstring width <= 320, Storage assertExists($path.'-w320'), second request fetches nothing */ });
test('a width outside 160, 320 and 480 is ignored: the original is served and nothing is written', function () {
    // GET covers.show with ['w' => 999]; assertHeader('Content-Type', 'image/png'); Storage::disk('local')->assertMissing($path.'-w999');
});
test('a proxied cover has a three-width srcset and a hotlinked one has none', function () {
    // cache-policy source: str_contains(coverSrcset(), '160w') && '320w' && '480w'; hotlinked: toBeNull()
});
test('the cover tile gives a proxied cover srcset and sizes, and keeps width, height and lazy loading', function () { /* htmlQuery //img[@srcset][@sizes][@width="160"][@height="240"][@loading="lazy"] */ });
```
Arrange the proxied versus hotlinked cover as `tests/Feature/Http/CoverProxyTest.php:128-140` does (`CoverPolicy` on the `ExternalSource`).

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/pest tests/Feature/Components/CoverAltTextTest.php tests/Feature/Http/CoverProxyTest.php tests/Feature/Components/CoverTileTest.php`
Expected: FAIL (`coverAlt`, `coverSrcset` undefined; `?w=` ignored today).

- [ ] **Step 3: Implement**: generalise `CoverController::backdrop()` into `resized(Filesystem $disk, string $path, int $width, int $quality, string $suffix)` (same GD guards: `function_exists('imagewebp')`, `BACKDROP_MAX_SIDE`, `min($width, imagesx)` so it never upscales); `size=backdrop` calls it with 320/50/`-backdrop`; `w` in the whitelist calls it with quality 80 and `-w{w}`. Cover tile: `srcset="{{ $media->coverSrcset() }}"` and `sizes` (`(min-width: 640px) {{ $width }}px, {{ phone width }}px` per size: S 112, M 144, L 176) only when `coverSrcset()` is non-null; keep `width`, `height`, `loading="lazy"`.

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/pest tests/Feature/Components tests/Feature/Http tests/Feature/Social/ProfilePageTest.php tests/Feature/Discovery/StoryPageTest.php tests/Feature/LandingShowcaseTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**: `git commit -m "feat: cover alt text, WebP widths and srcset through the cover proxy"`

### Task 6.7: One readable message when a Livewire form fails on the server

**Files:**
- Create: `resources/js/request-errors.js`, `tests/Browser/FormErrorsTest.php`
- Modify: `resources/js/app.js` (import and call before `Livewire.start()`, line 28)
- Test files to follow: `tests/Browser/PreferencesPageTest.php` (`actingAs`, `visit(path, options)->wait(1)`).

**Interfaces:** `export default function registerRequestErrors(Livewire)`: `Livewire.hook('request', ({ fail }) => fail(({ status, preventDefault }) => ...))`. Status 419 is left alone (Livewire's own "page expired, refresh?" prompt); 403 "You are not allowed to do that."; 429 "Too many attempts. Wait a minute and try again."; 503 (network failure) "We could not reach Bookflix. Check your connection and try again."; anything else "Something went wrong on our side. Your changes were not saved. Try again in a moment." Each calls `preventDefault()` then `window.Flux.toast({ text, variant: 'danger' })`.

Form inventory (as of the baseline; re-derive per the baseline re-check). `S` = plain POST, `L` = Livewire action.

| # | Form | Where | Success state today |
|---|---|---|---|
| S1 | `login.store` | `pages/auth/login` | redirect home |
| S2 | `register.store` | `pages/auth/register` | redirect to verify notice |
| S3 | `password.email` | `pages/auth/forgot-password` | `x-auth-session-status` |
| S4 | `password.update` | `pages/auth/reset-password` | redirect to login with status |
| S5 | `password.confirm.store` | `pages/auth/confirm-password` | redirect back |
| S6 | `two-factor.login.store` | `pages/auth/two-factor-challenge` | redirect home |
| S7 | `verification.send` | `pages/auth/verify-email` | status line |
| S8 | `library.store` | `components/library-add` (no-JS add) | `status` flash |
| L1 | `save` review | `components/⚡story-reviews` | toast "Review posted." |
| L2 | `sendReport` | same | toast "Thanks. A moderator will look at it." |
| L3 | `logProgress` | `components/⚡library-controls` | toast "Progress saved" |
| L4 | `createCollection` | same | toast "was added to" |
| L5 | `send` | `pages/assistant` | message appears |
| L6 | `deactivateAccount` | `settings/⚡deactivate-account-modal` | redirect `/` |
| L7 | `updatePassword` | `settings/⚡security` | toast "Password updated." |
| L8 | `updateProfileInformation` | `settings/⚡profile` | toast "Profile updated." |
| L9 | `updateReadingProfile` | same | toast "Reading profile updated." |
| L10 | `save` | `settings/⚡preferences` | toast "Preferences saved." |
| L11 | `updatePrivacy` | `settings/⚡privacy` | toast "Privacy settings updated." |
| L12 | `updateAi` | same | toast "AI settings updated." |
| L13 | `updateMature` | same | toast (shown or hidden) |
| L14 | `save` | `collections/⚡show` | toast "Collection saved." |
| L15 | `create` | `collections/⚡index` | redirect to the new collection |
| L16 | `save` | `library/⚡upload` | `$added` confirmation |
| L17 | `save` | `admin/staff-picks` | toast ":name is saved." |
| L18 | `save` | `admin/classifications` | toast |
| L19 | `save` | `admin/media/⚡edit` | toast "Saved. Edited fields are locked against sync." |
| L20 | `save` | `admin/content` (verify) | toast ":title is verified." |

Not forms (no server call, nothing to test): the reader's page-jump input (`@submit.prevent`), the assistant history rename (Alpine). Logout posts nothing to read.

- [ ] **Step 1: Write the failing test**

```php
use function Livewire\on;

// One row per Livewire form: [route name, route params, optional trigger text that opens its dialog, wire:submit method]. Rows needing a fixture say so;
// arrange it as the page's own browser or feature test does.
dataset('livewire forms', [
    'preferences save' => ['preferences.edit', [], null, 'save'],
    'profile information' => ['profile.edit', [], null, 'updateProfileInformation'],
    'reading profile' => ['profile.edit', [], null, 'updateReadingProfile'],
    'privacy' => ['privacy.edit', [], null, 'updatePrivacy'],
    'ai settings' => ['privacy.edit', [], null, 'updateAi'],
    'mature' => ['privacy.edit', [], null, 'updateMature'],
    'password' => ['security.edit', [], null, 'updatePassword'],
    'deactivate' => ['security.edit', [], 'Deactivate account', 'deactivateAccount'],
    'new collection' => ['collections.index', [], null, 'create'],
    // ... one row per L-row in the inventory; fixture rows (story page review/report, library progress and new collection, collection show, upload, admin pages) use the same shape with the route params and the label of the button that opens the dialog (grep `flux:modal.trigger` / `Flux::modal(` in the file).
]);

test('a server error on a Livewire form shows a readable toast, never the raw error modal', function (string $route, array $params, ?string $open, string $method) {
    $this->actingAs(str_starts_with($route, 'admin.') ? User::factory()->admin()->create() : User::factory()->create())
        ->withSession(['auth.password_confirmed_at' => time()]);
    on('call', function ($component, $called) use ($method) { $called === $method && throw new RuntimeException('forced'); });

    $page = visit(route($route, $params, false), ['viewport' => ['width' => 1280, 'height' => 800]])->wait(1);
    $open && $page->click($open)->wait(1);
    $page->click('form[wire\\:submit="'.$method.'"] [type=submit]')->wait(1)
        ->assertSee('Something went wrong on our side. Your changes were not saved.')
        ->assertScript('document.getElementById("livewire-error") === null');
})->with('livewire forms');

test('a rate limit on a form says to wait, and an expired page keeps Livewire\'s own prompt', function () {
    // status 429 -> "Too many attempts. Wait a minute and try again."; status 419 -> no toast from our hook. Drive both by throwing
    // ThrottleRequestsException / TokenMismatchException from the same on('call') hook.
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npm run build && vendor/bin/pest tests/Browser/FormErrorsTest.php`
Expected: FAIL: Livewire's `#livewire-error` dialog appears and the toast text is missing.

- [ ] **Step 3: Implement** `request-errors.js` as specified in Interfaces and register it in `app.js` (`registerRequestErrors(Livewire)` before `Livewire.start()`). No per-form PHP change.

- [ ] **Step 4: Run to verify it passes**

Run: `npm run build && vendor/bin/pest tests/Browser/FormErrorsTest.php tests/Browser/PreferencesPageTest.php tests/Browser/LibraryFlowTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**: `git commit -m "feat: a readable message when a Livewire form fails on the server"`

### Task 6.8: Every form has a forced validation error, a forced server error and a visible success

**Files:**
- Create: `tests/Feature/Forms/PlainFormsTest.php`, `tests/Feature/Forms/LivewireFormsTest.php`
- Modify: any form the success column of Task 6.7's table shows as silent after verification (expected: none; fix in the owning view with a toast or status line)
- Existing files to grep first (do not duplicate a test that already forces the case; name it in the ledger instead): `tests/Feature/Auth/*`, `tests/Feature/Settings/*`, `tests/Feature/Social/StoryReviewsTest.php`, `tests/Feature/Social/ReportTest.php`, `tests/Feature/Library/{LibraryControlsTest,CollectionsTest,PreferencesTest}.php`, `tests/Feature/Admin/{StaffPicksTest,ClassificationReviewTest,AdminMediaEditTest}.php`, the upload test (grep `library.upload`).

- [ ] **Step 1: Write the failing tests**

```php
// PlainFormsTest.php: S1..S8
dataset('plain forms', [
    'login' => ['login.store', ['email' => '', 'password' => ''], 'email', 'The email field is required.'],
    'register' => ['register.store', ['name' => '', 'email' => 'not-an-email', 'password' => 'x', 'password_confirmation' => 'y'], 'email', 'The email field must be a valid email address.'],
    'forgot' => ['password.email', ['email' => ''], 'email', 'The email field is required.'],
    'reset' => ['password.update', ['token' => 't', 'email' => 'a@b.test', 'password' => 'x', 'password_confirmation' => 'y'], 'password', null],
    'confirm' => ['password.confirm.store', ['password' => 'wrong'], 'password', null],     // signed in
    'two-factor' => ['two-factor.login.store', ['code' => 'abc'], 'code', null],            // with the challenge session
    'library add' => ['library.store', [], 'media_id', 'The media id field is required.'],  // signed in
]);

test('an invalid submission redirects back with the field error shown inline', function (string $route, array $data, string $field, ?string $message) {
    // actor and session as the matching Auth test sets them up; $this->from(back)->post(route($route), $data)
    //   ->assertSessionHasErrors($field)->followRedirects() then assertSee($message ?? the first error from session('errors')).
})->with('plain forms');

test('a server error on a plain form ends on the readable 500 page', function (string $route, array $data) {
    config(['app.debug' => false]);
    Route::getRoutes()->getByName($route)->middleware(fn () => throw new RuntimeException('forced'));   // runs before the controller

    $this->post(route($route), $data)->assertStatus(500)->assertSee('Something went wrong on our side');
})->with([['login.store', []], ['register.store', []], ['password.email', []], ['password.update', []], ['library.store', []], ['password.confirm.store', []], ['two-factor.login.store', []], ['verification.send', []]]);

test('each plain form shows a success state', function () {
    // forgot-password with a real email: assertSessionHas('status') and the page shows it; verification.send: status 'verification-link-sent' line;
    // library.store: back with a status that the story page renders; register/login/reset/confirm/2FA: the redirect targets named in the inventory.
    // Where an Auth test already proves one, list its name in the ledger and keep this test to the gaps.
});
```
```php
// LivewireFormsTest.php: validation per L-row, Livewire::test() as the page's own feature test arranges it
dataset('invalid submissions', [
    'story report' => ['story-reviews', fn () => ['reason' => ''], 'sendReport', ['reason']],
    'log progress' => ['library-controls', fn () => ['percent' => '', 'pageNumber' => 0, 'minutes' => 9999], 'logProgress', ['percent', 'pageNumber', 'minutes']],
    'new collection (controls)' => ['library-controls', fn () => ['newCollection' => ''], 'createCollection', ['newCollection']],
    'new collection (index)' => ['pages::collections.index', fn () => ['name' => ''], 'create', ['name']],
    'collection edit' => ['pages::collections.show', fn () => ['name' => '', 'description' => str_repeat('x', 501)], 'save', ['name', 'description']],
    'upload' => ['pages::library.upload', fn () => [], 'save', ['story', 'file', 'own']],
    'media edit' => ['pages::admin.media.edit', fn () => ['title' => ''], 'save', ['title']],
    'mature' => ['pages::settings.privacy', fn () => ['showMature' => true, 'confirmAdult' => false], 'updateMature', ['confirmAdult']],
    'password' => ['pages::settings.security', fn () => ['current_password' => 'wrong', 'password' => 'x', 'password_confirmation' => 'y'], 'updatePassword', ['current_password', 'password']],
    'deactivate' => ['pages::settings.deactivate-account-modal', fn () => ['password' => 'wrong'], 'deactivateAccount', ['password']],
    // profile (rules from profileRules / readingProfileRules), preferences (rules from PreferenceChoices), assistant send (message over AssistantRunner::MAX_MESSAGE)
    // review save (SaveReview rules), staff picks and classifications (the action's thrown ValidationException or addError), content verify (license required):
    // one row each, keys read from the action's validate() call.
]);

test('an invalid submission shows its error on the field and saves nothing', function (string $component, Closure $set, string $method, array $keys) {
    // Livewire::actingAs($actor)->test($component, $mountParams)->set($set())->call($method)->assertHasErrors($keys)
    //   plus ->assertSee(<the message for $keys[0]>); assert the table the form writes to is unchanged.
})->with('invalid submissions');

test('switch forms with a boolean property cannot be given a non-boolean', function () {
    // updatePrivacy and updateAi: set('personalizationEnabled', 'x') is refused by the typed property or the boolean rule: assertHasErrors or an expected exception; record as "Not applicable: nothing to mistype" if the type system refuses first.
});
```
Server errors for L-rows are Task 6.7's browser dataset; success states for L-rows are the toasts listed in the inventory, asserted by the existing feature test (name it per row in the ledger) or added here as `->assertDispatched`/`Flux::toast` checks where absent.

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/pest tests/Feature/Forms`
Expected: FAIL where an error or success state is missing; a row that already passes is recorded in the ledger and its duplicate removed.

- [ ] **Step 3: Implement** only what a failing row shows missing: a field without `@error` or Flux `name=` binding, or a silent success (add `Flux::toast(variant: 'success', text: ...)` or a status line). Do not restyle forms; Phases 4 and 5 own that.

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/pest tests/Feature/Forms tests/Feature/Auth tests/Feature/Settings tests/Feature/Library tests/Feature/Social tests/Feature/Admin`
Expected: PASS.

- [ ] **Step 5: Commit**: `git commit -m "test: validation, server error and success states for every form"`

## Phase exit

- [ ] `composer test` (Pint, PHPStan level 7, Unit and Feature suites) green.
- [ ] `npm run build`, then `vendor/bin/pest tests/Browser/ErrorPagesTest.php tests/Browser/FormErrorsTest.php tests/Browser/StoryPageTest.php tests/Browser/SeriesPageTest.php tests/Browser/PreferencesPageTest.php tests/Browser/LibraryFlowTest.php` green (the last four prove the breadcrumb and head changes did not break the pages).
- [ ] Self-review the 404, 429 and 500 pages at 375×667 and 1280×800 with the browser pane (screenshots against `DESIGN.md`); read `public/og-image.png` once more.
- [ ] `/code-review` on the diff, whole-branch review on the most capable model, one fix wave.
- [ ] Ledger `mp2-phase-6-ledger.md`: baseline results, conflict scan (Task 6.1 and 6.2 share `partials/head.blade.php`; 6.4 and 6.5 share both `⚡show` pages and `layouts/admin.blade.php` with Phase 5; 6.6 and 6.1 share the story `⚡show` file), form table with the test that proves each state, deferred minors.
- [ ] PR body (never merge; D2-24 stacking: branch from the previous open phase, rebase onto `origin/main` before opening). Report table rows, one per brief item:

| Item | Expected status | Files |
|---|---|---|
| C1 custom 404 (search, home, shelves) | Fixed | `errors/404`, `errors/layout`, `ErrorPagesTest` |
| C1 403, 419, 429, 500, 503 pages | Fixed | `errors/*` |
| C1 thank-you pages | Not applicable (Phase 9 builds `publish.thanks`) | none |
| C1 breadcrumbs + BreadcrumbList (story, series, shelf, admin) | Fixed (settings: Not applicable, Phase 4 single page) | `components/breadcrumbs`, `AdminTrail`, layouts, pages |
| Owner decision: story pages public | Fixed | `routes/web.php`, `story-guest` limiter, story components, `PublicStoryPageTest` |
| C1 internal links | Fixed (author pages: Needs decision, open question 6; for guests, series and shelf links go to sign-in) | series page, `InternalLinksTest` |
| C1 privacy page | Phase 3 | none here |
| C2 unique titles | Fixed | `partials/head`, pages |
| C2 meta descriptions | Fixed | `MetaDescription`, `config/bookflix.php`, pages |
| C2 favicon set + manifest | Fixed (ico, svg, 180 existed) | `public/site.webmanifest`, `icon-192/512.png` |
| C2 robots.txt | Fixed | `public/robots.txt` |
| C2 og:image + Twitter card | Fixed (per-story images skipped, D2-11) | `public/og-image.png`, head |
| C2 alt text | Fixed (D2-10) | `Media::coverAlt`, story and profile views |
| C2 image compression, srcset, WebP | Fixed for proxied covers; proxying all covers and AVIF: Needs decision | `CoverController`, `cover-tile` |
| C3 copyright year | Already OK | `landing/footer.blade.php` |
| C4 form messages | Fixed | `request-errors.js`, `tests/Feature/Forms`, `FormErrorsTest` |
| C4 Vercel Analytics | Needs decision (no deployment target; options in P6-R12) | none |
