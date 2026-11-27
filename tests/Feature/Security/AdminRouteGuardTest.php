<?php

use App\Models\Media;
use App\Models\User;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/** @var Closure(): list<RouteDefinition> $adminRoutes */
$adminRoutes = fn (): array => collect(Route::getRoutes()->getRoutes())
    ->filter(fn (RouteDefinition $route) => str_starts_with((string) $route->getName(), 'admin.') || $route->uri() === 'admin' || str_starts_with($route->uri(), 'admin/'))
    ->values()->all();

test('every admin route requires a signed-in, verified admin', function () use ($adminRoutes) {
    expect($adminRoutes())->not->toBeEmpty();

    foreach ($adminRoutes() as $route) {
        expect($route->gatherMiddleware())->toContain('auth', 'verified', 'can:access-admin');
    }
});

test('guests are sent to sign in and readers are refused on every admin page', function () use ($adminRoutes) {
    $pages = collect($adminRoutes())->filter(fn (RouteDefinition $route) => in_array('GET', $route->methods()) && $route->parameterNames() === []);
    expect($pages)->not->toBeEmpty();

    // Pages with a parameter: a record, and an optional one left out.
    $urls = $pages->map(fn (RouteDefinition $route) => '/'.ltrim($route->uri(), '/'))
        ->push(route('admin.media.edit', Media::factory()->create(), false), route('admin.models.compare', [], false))
        ->unique()->values();

    foreach ($urls as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }

    $this->actingAs(User::factory()->create());
    foreach ($urls as $url) {
        $this->get($url)->assertForbidden();
    }
});

test('nothing outside the admin routes sits under the admin prefix or name', function () use ($adminRoutes) {
    $names = collect($adminRoutes())->map(fn (RouteDefinition $route) => (string) $route->getName())->all();

    expect($names)->each->toStartWith('admin.');
});
