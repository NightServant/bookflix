<?php

use App\Models\User;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/** @var Closure(): list<RouteDefinition> $adminRoutes */
$adminRoutes = fn (): array => collect(Route::getRoutes()->getRoutes())
    ->filter(fn (RouteDefinition $route) => str_starts_with((string) $route->getName(), 'admin.'))
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

    foreach ($pages as $route) {
        $this->get('/'.ltrim($route->uri(), '/'))->assertRedirect(route('login'));
    }

    $this->actingAs(User::factory()->create());
    foreach ($pages as $route) {
        $this->get('/'.ltrim($route->uri(), '/'))->assertForbidden();
    }
});
