<?php

use App\Models\ContentSource;
use App\Models\Media;
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
 * @return array{story: Media, series: Series}
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

    return ['story' => $story, 'series' => $series];
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
 * @return list<string> the pages a visitor of this role starts from
 */
function linksPagesFor(string $role): array
{
    if ($role === 'guest') {
        // The landing page shows its Top 10 band (and its anchor) only when stories have covers.
        popularStories(12);

        return ['/', route('login', absolute: false), route('register', absolute: false), route('password.request', absolute: false)];
    }

    if ($role === 'admin') {
        return linksRoutePaths(fn (RouteDefinition $route) => str_starts_with((string) $route->getName(), 'admin.'));
    }

    $seed = linksSeed();

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
 * @param  list<string>  $pages
 */
function linksAssertWork(array $pages): void
{
    expect($pages)->not->toBeEmpty();

    $problems = [];
    $bodies = [];
    $visit = function (string $path) use (&$bodies, &$problems): ?string {
        if (! array_key_exists($path, $bodies)) {
            $response = test()->get($path);
            $bodies[$path] = $response->status() < 400 ? (string) $response->getContent() : null;

            if ($response->status() >= 400) {
                $problems[] = "{$path} answers {$response->status()}";
            }
        }

        return $bodies[$path];
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

/** The href of the logo link of a page, by its test hook. */
function linksLogoHref(string $html): ?string
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    foreach ((new DOMXPath($dom))->query('//a[@data-test="logo"]') ?: [] as $anchor) {
        return $anchor->getAttribute('href');
    }

    return null;
}

test('every internal link a guest can see opens for a guest', function () {
    linksAssertWork(linksPagesFor('guest'));
});

test('every internal link a reader can see opens for that reader', function () {
    $pages = linksPagesFor('reader');
    $this->actingAs(User::factory()->create());

    linksAssertWork($pages);
});

test('every internal link an admin can see opens for that admin', function () {
    // The models page asks the ML service for its health on every render.
    Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
    $this->actingAs(User::factory()->admin()->create());

    linksAssertWork(linksPagesFor('admin'));
});

test('the logo goes home for each kind of visitor', function () {
    expect(linksLogoHref($this->get(route('landing'))->getContent()))->toBe(route('landing'));
    expect(linksLogoHref($this->get(route('login'))->getContent()))->toBe(route('landing'));

    $this->actingAs(User::factory()->create());
    expect(linksLogoHref($this->get(route('home'))->getContent()))->toBe(route('home'));

    $this->actingAs(User::factory()->admin()->create());
    expect(linksLogoHref($this->get(route('admin.index'))->getContent()))->toBe(route('admin.index'));
});
