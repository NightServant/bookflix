<?php

use App\Models\ContentSource;
use App\Models\DuplicateCandidate;
use App\Models\ExternalReview;
use App\Models\Media;
use App\Models\ReadingProgress;
use App\Models\ReadingSession;
use App\Models\Report;
use App\Models\Review;
use App\Models\Series;
use App\Models\User;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
 * Every internal link a visitor can see works for that visitor: each page of a role is opened as that role, every same-site link on it is opened
 * as the same role, and each in-page anchor must exist in the page it points to. A link is fixed or removed at its source, never skipped here.
 */

/** Paths no crawl opens: sign-out, signed or streamed files, covers, exports and Livewire's own endpoint. */
const LINKS_SKIPPED = ['/logout', 'signature=', '/reader/stream', '/covers/', '/exports/', '/livewire'];

/**
 * Data for every shelf, rail and footer of a signed-in visitor to render its links.
 *
 * @return array{story: Media, series: Series, owner: User}
 */
function linksSeed(): array
{
    $catalog = homeCatalog();
    $story = fullMedia();
    $series = $story->series()->firstOrFail();
    $owner = User::factory()->create();

    collectionOf($owner, [$story, $catalog->first()], ['name' => 'Linked Shelf', 'is_editorial' => true]);
    ContentSource::factory()->for($story)->publicDomain()->verified()->ready()->create();

    foreach ($catalog->take(4) as $item) {
        libraryEntry($owner, $item, 'reading', favorite: true);
    }

    // Something read, so History and Continue Reading have rows to link from.
    foreach ($catalog->take(3) as $item) {
        ReadingProgress::factory()->create(['user_id' => $owner->id, 'media_id' => $item->id]);
        ReadingSession::factory()->create(['user_id' => $owner->id, 'media_id' => $item->id]);
    }

    return ['story' => $story, 'series' => $series, 'owner' => $owner];
}

/** Rows for every admin list to link from: reports (a review and an outside review), a duplicate pair, a source awaiting verification, a staff pick, a classification and readers. */
function linksSeedAdmin(): void
{
    $catalog = homeCatalog();
    $story = $catalog->first();

    Report::factory()->of(Review::factory()->create(['media_id' => $story->id]))->create();
    Report::factory()->of(ExternalReview::factory()->create(['media_id' => $story->id]))->create();
    DuplicateCandidate::factory()->create();
    ContentSource::factory()->for($catalog->get(1))->publicDomain()->ready()->create();
    collectionOf(User::factory()->admin()->create(), [$story], ['name' => 'Admin Pick', 'is_editorial' => true]);
    classified($catalog->get(12), '813', status: 'pending');
    User::factory()->count(3)->create();
}

/**
 * @return list<string> the parameter-less GET routes (full route list) whose middleware satisfies $keep
 */
function linksRoutePaths(Closure $keep): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route) => in_array('GET', $route->methods(), true) && $route->parameterNames() === [] && $keep($route))
        ->map(fn (RouteDefinition $route) => '/'.ltrim($route->uri(), '/'))
        ->reject(fn (string $path) => collect(LINKS_SKIPPED)->contains(fn (string $skip) => str_contains($path, $skip)))
        ->unique()->values()->all();
}

/**
 * @param  array{story: Media, series: Series, owner: User}|null  $seed  what a reader's pages are built from (see linksSeed)
 * @return list<string> the pages a visitor of this role starts from
 */
function linksPagesFor(string $role, ?array $seed = null): array
{
    if ($role === 'guest') {
        // The landing page shows its Top 10 band (and its anchor) only when stories have covers.
        popularStories(12);

        return ['/', route('login', absolute: false), route('register', absolute: false), route('password.request', absolute: false)];
    }

    if ($role === 'admin') {
        return linksRoutePaths(fn (RouteDefinition $route) => str_starts_with((string) $route->getName(), 'admin.'));
    }

    $seed ??= linksSeed();

    return [
        ...linksRoutePaths(fn (RouteDefinition $route) => in_array('reader', $route->gatherMiddleware(), true)),
        route('stories.show', $seed['story']->slug, false),
        route('series.show', $seed['series']->slug, false),
        route('explore.show', '813', false),
    ];
}

/**
 * @return array{paths: list<string>, fragments: list<string>} the same-site links of a page: paths (with query) and in-page anchors (`#id` or `/path#id`)
 */
function linksInternal(string $html): array
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    $host = parse_url((string) config('app.url'), PHP_URL_HOST);
    $paths = [];
    $fragments = [];

    foreach ($dom->getElementsByTagName('a') as $anchor) {
        $href = trim($anchor->getAttribute('href'));

        if ($href === '' || preg_match('/^(mailto|tel|javascript|data):/i', $href)) {
            continue;
        }

        $parts = parse_url($href);

        if ($parts === false || isset($parts['scheme']) && ! in_array($parts['host'] ?? null, [$host, 'localhost'], true) || str_starts_with($href, '//')) {
            continue;
        }

        $path = ($parts['path'] ?? '') === '' ? '' : $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');

        if (collect(LINKS_SKIPPED)->contains(fn (string $skip) => str_contains($href, $skip)) || preg_match('#/reader/[^/]+/page#', $href)) {
            continue;
        }

        if (isset($parts['fragment'])) {
            $fragments[] = $path.'#'.$parts['fragment'];
        }

        if ($path !== '') {
            $paths[] = $path;
        }
    }

    return ['paths' => array_values(array_unique($paths)), 'fragments' => array_values(array_unique($fragments))];
}

/** Whether the HTML carries an element with this id. */
function linksHasId(string $html, string $id): bool
{
    return preg_match('/\sid=(["\'])'.preg_quote($id, '/').'\1/', $html) === 1;
}

/**
 * Opens a path as the current visitor, following up to three redirects.
 *
 * @return array{status: int, final: string, body: string|null}
 */
function linksOpen(string $path): array
{
    $current = $path;

    for ($hop = 0; $hop <= 3; $hop++) {
        $response = test()->get($current);

        if (! $response->isRedirection()) {
            return ['status' => $response->status(), 'final' => $current, 'body' => $response->status() < 400 ? (string) $response->getContent() : null];
        }

        $location = (string) $response->headers->get('Location');
        $current = (parse_url($location, PHP_URL_PATH) ?: '/').(($query = parse_url($location, PHP_URL_QUERY)) ? '?'.$query : '');
    }

    return ['status' => 310, 'final' => $current, 'body' => null];
}

/**
 * Whether a link that landed on $final is a dead end for this role: a page that sends the visitor to sign in or verify, or (for an admin) back to
 * the dashboard when the link was not to an admin page. A landing on the password confirmation screen is a legitimate step, not a bounce.
 */
function linksBounced(string $requested, string $final, string $role): bool
{
    $finalPath = parse_url($final, PHP_URL_PATH);
    $requestedPath = parse_url($requested, PHP_URL_PATH);

    if ($finalPath === route('password.confirm', absolute: false) || $finalPath === $requestedPath) {
        return false;
    }

    $signIn = route('login', absolute: false);

    // A guest shown a link that only works signed in is sent to sign in.
    if ($role === 'guest') {
        return $finalPath === $signIn;
    }

    // A signed-in visitor shown a guest-only link (sign in, sign up, reset) is sent to their home.
    if (in_array($requestedPath, [$signIn, route('register', absolute: false), route('password.request', absolute: false)], true)) {
        return true;
    }

    if (in_array($finalPath, [$signIn, route('verification.notice', absolute: false)], true)) {
        return true;
    }

    // An admin sent back to the dashboard from a link that is not an admin page (`/admin` or `/admin/...`, not `/administer`).
    $adminPage = $requestedPath === '/admin' || str_starts_with((string) $requestedPath, '/admin/');

    return $role === 'admin' && $finalPath === route('admin.index', absolute: false) && ! $adminPage;
}

/**
 * @param  list<string>  $pages
 */
function linksAssertWork(array $pages, string $role): void
{
    expect($pages)->not->toBeEmpty();

    $problems = [];
    $opened = [];
    $visit = function (string $path, ?string $from = null) use (&$opened, &$problems, $role): ?string {
        if (! array_key_exists($path, $opened)) {
            $opened[$path] = linksOpen($path);

            if ($opened[$path]['status'] >= 400) {
                $problems[] = "{$path} ends at {$opened[$path]['final']} with {$opened[$path]['status']}";
            } elseif (linksBounced($path, $opened[$path]['final'], $role)) {
                $problems[] = "{$path} bounces to {$opened[$path]['final']} for a {$role}";
            }
        }

        return $opened[$path]['body'];
    };

    $found = [];

    foreach ($pages as $page) {
        $html = $visit($page);

        if ($html === null) {
            continue;
        }

        $links = linksInternal($html);

        foreach ($links['paths'] as $path) {
            $found[$path][] = $page;
        }

        foreach ($links['fragments'] as $fragment) {
            [$path, $id] = explode('#', $fragment, 2);
            $target = $path === '' ? $html : $visit($path);

            if ($id === '' || ($target !== null && ! linksHasId($target, $id))) {
                $problems[] = "{$page} links to {$fragment}, which has no such anchor";
            }
        }
    }

    foreach (array_keys($found) as $path) {
        $visit((string) $path);
    }

    expect($problems)->toBe([]);
}

/** @return list<string> the hrefs of every logo link of a page, by its test hook */
function linksLogoHrefs(string $html): array
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    $hrefs = [];

    foreach ((new DOMXPath($dom))->query('//a[@data-test="logo"]') ?: [] as $anchor) {
        $hrefs[] = $anchor->getAttribute('href');
    }

    return $hrefs;
}

test('every internal link a guest can see opens for a guest', function () {
    linksAssertWork(linksPagesFor('guest'), 'guest');
});

test('on a catalogue without covers the landing page links to no section that is not there', function () {
    linksAssertWork(['/'], 'guest');

    // Not in the bar, the phone menu or the footer.
    $this->get('/')->assertDontSee('href="#top-10"', false);
});

test('with covers the landing header, its phone menu and the footer link to the Top 10 band', function () {
    popularStories(12);

    $html = $this->get('/')->getContent();

    // The bar's nav link, the phone menu's link and the footer's.
    expect(substr_count($html, 'href="#top-10"'))->toBe(3)->and(linksHasId($html, 'top-10'))->toBeTrue();
});

test('every internal link a reader can see opens for that reader', function () {
    // The seeded owner is the reader: their library, history and Continue Reading have rows.
    $seed = linksSeed();
    $pages = linksPagesFor('reader', $seed);
    $this->actingAs($seed['owner']);

    linksAssertWork($pages, 'reader');
});

test('every internal link an admin can see opens for that admin', function () {
    // The models page asks the ML service for its health on every render.
    Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
    linksSeedAdmin();
    $this->actingAs(User::factory()->admin()->create());

    linksAssertWork(linksPagesFor('admin'), 'admin');
});

test('every logo goes home for each kind of visitor', function () {
    $logos = fn (string $url): array => linksLogoHrefs($this->get($url)->getContent());

    expect($logos(route('landing')))->not->toBeEmpty()->each->toBe(route('landing'));
    // The auth pages carry the mobile logo and the desktop showcase logo.
    expect($logos(route('login')))->toHaveCount(2)->each->toBe(route('landing'));

    $this->actingAs(User::factory()->create());
    expect($logos(route('home')))->not->toBeEmpty()->each->toBe(route('home'));

    // The sidebar logo and the top-bar logo.
    $this->actingAs(User::factory()->admin()->create());
    expect($logos(route('admin.index')))->toHaveCount(2)->each->toBe(route('admin.index'));
});

test('the bounce rule catches what a role must never be shown', function (string $requested, string $final, string $role, bool $bounced) {
    expect(linksBounced($requested, $final, $role))->toBe($bounced);
})->with([
    'a guest sent to sign in' => ['/library', '/login', 'guest', true],
    'a guest on the sign-in page itself' => ['/login', '/login', 'guest', false],
    'a guest page that opens' => ['/forgot-password', '/forgot-password', 'guest', false],
    'a reader shown the sign-in link' => ['/login', '/home', 'reader', true],
    'an admin shown the sign-up link' => ['/register', '/admin', 'admin', true],
    'a reader sent to sign in' => ['/library', '/login', 'reader', true],
    'a reader page that opens' => ['/library', '/library', 'reader', false],
    'a reader link that bounces an admin' => ['/library', '/admin', 'admin', true],
    'an admin page' => ['/admin/users', '/admin/users', 'admin', false],
    'a path that only starts like /admin' => ['/administer', '/admin', 'admin', true],
    'an admin page with a query' => ['/admin/classifications?status=all', '/admin', 'admin', false],
    'the password confirmation step' => ['/settings/security', '/user/confirm-password', 'reader', false],
]);
