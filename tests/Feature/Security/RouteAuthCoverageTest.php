<?php

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
 * No route is open by accident: every route (the full list, vendor routes included) must carry an authentication middleware unless it is on the
 * explicit allow-list below, each with the reason it is public. A new route without `auth` fails this test, so opening one is a decision made here, in review.
 */

/**
 * Routes that are public on purpose: [name or uri pattern (fnmatch), why].
 *
 * @return list<array{string, string}>
 */
function authCoveragePublic(): array
{
    return [
        ['landing', 'the public landing page'],
        ['up', 'the framework health check'],
        ['well-known.passkeys', 'the passkey discovery document; public by specification'],
        ['covers.show', 'the cover proxy: allow-listed public images, rate limited, nothing user-specific'],
        ['login', 'guest sign-in form'],
        ['login.store', 'sign-in; throttled by the `login` limiter'],
        ['register', 'guest sign-up form'],
        ['register.store', 'sign-up; throttled by `auth-writes`'],
        ['password.request', 'guest password reset request form'],
        ['password.email', 'password reset link request; throttled by `auth-writes`'],
        ['password.reset', 'guest reset form (the token is the credential)'],
        ['password.update', 'password reset submission; throttled by `auth-writes`'],
        ['two-factor.login', 'the two-factor challenge, reached mid sign-in'],
        ['two-factor.login.store', 'the two-factor answer; throttled by `two-factor`'],
        ['passkey.login', 'passkey sign-in; throttled by `passkeys`'],
        ['passkey.login-options', 'passkey sign-in challenge; throttled by `passkeys`'],
        ['default-livewire.update', "Livewire's update endpoint: the page's own middleware (auth, can, verified, reader...) is replayed on every update"],
        ['livewire.upload-file', "Livewire's temporary upload: throttled; the component that receives it authorizes the action"],
        ['livewire.preview-file', "Livewire's signed temporary preview of an upload"],
        ['livewire-*', "Livewire's own JavaScript and CSS assets"],
        ['flux/*', "Flux's own JavaScript and CSS assets"],
        ['__flux.flag', "Flux's flag images"],
        ['boost.browser-logs', "Boost's development-only browser log sink (registered only outside production)"],
    ];
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

        $listed = collect($public)->contains(fn (array $entry) => fnmatch($entry[0], (string) $route->getName()) || fnmatch($entry[0], ltrim($route->uri(), '/')));

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

    foreach (authCoveragePublic() as [$pattern, $reason]) {
        $matches = $routes->contains(fn (RouteDefinition $route) => fnmatch($pattern, (string) $route->getName()) || fnmatch($pattern, ltrim($route->uri(), '/')));

        // Boost's route exists only where Boost is installed (development).
        expect($matches || $pattern === 'boost.browser-logs')->toBeTrue("`{$pattern}` ({$reason}) matches no route");
    }
});

test('a new route without authentication is flagged', function () {
    Route::get('/forgotten-open-route', fn () => 'open')->name('forgotten.open');
    Route::middleware('auth')->get('/forgotten-closed-route', fn () => 'closed')->name('forgotten.closed');

    $gaps = authCoverageGaps();

    expect($gaps)->toHaveCount(1)->and($gaps[0])->toContain('/forgotten-open-route');
});
