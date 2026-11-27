<?php

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
 * No route is open by accident: every route (the full list, vendor routes included) must carry an authentication middleware unless it is on the
 * explicit allow-list below, each with the reason it is public. A new route without `auth` fails this test, so opening one is a decision made here, in review.
 */

/**
 * Routes that are public on purpose: [`name` or `uri`, what the pattern (fnmatch) is matched against, the pattern, why]. A `name` entry matches only a route's
 * name and a `uri` entry only its URI, so an unnamed route that merely looks like a listed name (or the reverse) is still flagged.
 *
 * @return list<array{string, string, string}>
 */
function authCoveragePublic(): array
{
    return [
        ['name', 'landing', 'the public landing page'],
        ['uri', 'up', 'the framework health check'],
        ['name', 'well-known.passkeys', 'the passkey discovery document; public by specification'],
        ['name', 'covers.show', 'the cover proxy: allow-listed public images, rate limited, nothing user-specific'],
        ['name', 'login', 'guest sign-in form'],
        ['name', 'login.store', 'sign-in; throttled by the `login` limiter'],
        ['name', 'register', 'guest sign-up form'],
        ['name', 'register.store', 'sign-up; throttled by `auth-writes`'],
        ['name', 'password.request', 'guest password reset request form'],
        ['name', 'password.email', 'password reset link request; throttled by `auth-writes`'],
        ['name', 'password.reset', 'guest reset form (the token is the credential)'],
        ['name', 'password.update', 'password reset submission; throttled by `auth-writes`'],
        ['name', 'two-factor.login', 'the two-factor challenge, reached mid sign-in'],
        ['name', 'two-factor.login.store', 'the two-factor answer; throttled by `two-factor`'],
        ['name', 'passkey.login', 'passkey sign-in; throttled by `passkeys`'],
        ['name', 'passkey.login-options', 'passkey sign-in challenge; throttled by `passkeys`'],
        ['name', 'default-livewire.update', "Livewire's update endpoint: the page's own middleware (auth, can, verified, reader...) is replayed on every update"],
        ['name', 'livewire.upload-file', "Livewire's temporary upload: throttled; the component that receives it authorizes the action"],
        ['name', 'livewire.preview-file', "Livewire's signed temporary preview of an upload"],
        ['uri', 'livewire-*', "Livewire's own JavaScript and CSS assets"],
        ['uri', 'flux/*', "Flux's own JavaScript and CSS assets"],
        ['name', '__flux.flag', "Flux's flag images"],
        ['name', 'boost.browser-logs', "Boost's development-only browser log sink (registered only outside production)"],
    ];
}

/** Whether an allow-list entry matches a route: names against names, URIs against URIs. */
function authCoverageMatches(array $entry, RouteDefinition $route): bool
{
    [$kind, $pattern] = $entry;

    return $kind === 'name'
        ? $route->getName() !== null && fnmatch($pattern, $route->getName())
        : fnmatch($pattern, ltrim($route->uri(), '/'));
}

/** Whether a middleware list carries an authentication middleware. */
function authCoverageHasAuth(array $middleware): bool
{
    foreach ($middleware as $item) {
        $name = is_string($item) ? $item : '';

        if (in_array(explode(':', $name)[0], ['auth', 'Illuminate\Auth\Middleware\Authenticate', 'Laravel\Horizon\Http\Middleware\Authenticate'], true)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string> the routes that have neither an authentication middleware nor a place on the allow-list
 */
function authCoverageGaps(): array
{
    $public = authCoveragePublic();
    $gaps = [];

    /** @var RouteDefinition $route */
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (authCoverageHasAuth($route->gatherMiddleware())) {
            continue;
        }

        $listed = collect($public)->contains(fn (array $entry) => authCoverageMatches($entry, $route));

        if (! $listed) {
            $gaps[] = implode('|', $route->methods()).' /'.ltrim($route->uri(), '/').' ('.($route->getName() ?? 'unnamed').')';
        }
    }

    return $gaps;
}

test('every route is behind authentication or is on the public allow-list', function () {
    expect(authCoverageGaps())->toBe([]);
});

test('the allow-list holds no entry that matches nothing', function () {
    $routes = collect(Route::getRoutes()->getRoutes());

    foreach (authCoveragePublic() as $entry) {
        [, $pattern, $reason] = $entry;
        $matches = $routes->contains(fn (RouteDefinition $route) => authCoverageMatches($entry, $route));

        // Boost's route exists only where Boost is installed (development).
        expect($matches || $pattern === 'boost.browser-logs')->toBeTrue("`{$pattern}` ({$reason}) matches no route");
    }
});

test('an unnamed route that only looks like a listed name is flagged', function () {
    Route::get('/landing', fn () => 'open');
    Route::get('/well-known.passkeys', fn () => 'open');

    expect(authCoverageGaps())->toHaveCount(2);
});

test('a new route without authentication is flagged', function () {
    Route::get('/forgotten-open-route', fn () => 'open')->name('forgotten.open');
    Route::middleware('auth')->get('/forgotten-closed-route', fn () => 'closed')->name('forgotten.closed');

    $gaps = authCoverageGaps();

    expect($gaps)->toHaveCount(1)->and($gaps[0])->toContain('/forgotten-open-route');
});
