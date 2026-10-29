<?php

use App\Models\User;
use Database\Seeders\DeweySeeder;
use Illuminate\Support\Facades\Route;

test('the profile item is current on every settings page', function (string $routeName) {
    $response = $this->actingAs(User::factory()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route($routeName))
        ->assertOk();

    $navs = primaryNavs($response->getContent());
    $profile = preg_quote(route('profile.edit'), '#');
    $home = preg_quote(route('home'), '#');

    expect($navs['header'])
        ->toMatch('#<a href="'.$profile.'"[^>]*data-current="data-current"#')
        ->not->toMatch('#<a href="'.$home.'"[^>]*data-current="data-current"#')
        ->and($navs['bottom'])
        ->toMatch('#<a\s+href="'.$profile.'"[^>]*aria-current="page"#')
        ->not->toMatch('#<a\s+href="'.$home.'"[^>]*aria-current="page"#');
})->with(['profile.edit', 'security.edit', 'privacy.edit', 'preferences.edit']);

test('the discover item is current on every explore page', function (string $routeName, array $parameters) {
    $this->seed(DeweySeeder::class);

    $response = $this->actingAs(User::factory()->create())->get(route($routeName, $parameters))->assertOk();

    $navs = primaryNavs($response->getContent());
    $discover = preg_quote(route('discover'), '#');
    $home = preg_quote(route('home'), '#');

    expect($navs['header'])
        ->toMatch('#<a href="'.$discover.'"[^>]*data-current="data-current"#')
        ->not->toMatch('#<a href="'.$home.'"[^>]*data-current="data-current"#')
        ->and($navs['bottom'])
        ->toMatch('#<a\s+href="'.$discover.'"[^>]*aria-current="page"#')
        ->not->toMatch('#<a\s+href="'.$home.'"[^>]*aria-current="page"#');
})->with([
    'index' => ['explore.index', []],
    'show' => ['explore.show', ['813']],
]);

test('admins see the admin link in the user menu', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('home'))
        ->assertSee('href="'.route('admin.index').'"', false);
});

test('non admins do not see the admin link in the user menu', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertDontSee('href="'.route('admin.index').'"', false);
});

test('pages sit in the shared gutter container, and home runs its bands edge to edge', function () {
    $this->actingAs(User::factory()->create());

    $settings = $this->get(route('profile.edit'))->getContent();
    $home = $this->get(route('home'))->getContent();

    expect(htmlQuery($settings, '//main/div[contains(@class,"px-gutter")]')->length)->toBe(1)
        ->and(htmlQuery($home, '//main/div[contains(@class,"px-gutter")]')->length)->toBe(0)
        ->and(htmlQuery($home, '//main[not(contains(@class,"px-gutter"))]')->length)->toBe(1)
        ->and(htmlQuery($settings, '//main[not(contains(@class,"px-gutter"))]')->length)->toBe(1);
});

test('search is in the bottom nav only: the desktop header has the palette trigger instead', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('home'))->assertOk();

    $navs = primaryNavs($response->getContent());
    $search = preg_quote(route('search'), '#');

    expect($navs['header'])->not->toMatch('#<a href="'.$search.'"#')
        ->and($navs['bottom'])->toMatch('#<a\s+href="'.$search.'"#');
});

test('the primary navigation is home, discover, search, library and profile on a phone, without search on a desktop', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('home'))->assertOk();

    $navs = primaryNavs($response->getContent());
    $labels = fn (string $nav) => collect(htmlQuery($nav, '//a'))->map(fn (DOMElement $link): string => trim($link->textContent))->all();

    expect($labels($navs['bottom']))->toBe(['Home', 'Discover', 'Search', 'Library', 'Profile'])
        ->and($labels($navs['header']))->toBe(['Home', 'Discover', 'Library', 'Profile']);
});

test('the library item is current on the library page and only there', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('library.index'))->assertOk();

    $navs = primaryNavs($response->getContent());
    $library = preg_quote(route('library.index'), '#');
    $home = preg_quote(route('home'), '#');

    expect($navs['header'])
        ->toMatch('#<a href="'.$library.'"[^>]*data-current="data-current"#')
        ->not->toMatch('#<a href="'.$home.'"[^>]*data-current="data-current"#')
        ->and($navs['bottom'])
        ->toMatch('#<a\s+href="'.$library.'"[^>]*aria-current="page"#')
        ->not->toMatch('#<a\s+href="'.$home.'"[^>]*aria-current="page"#');
});

test('the library item stays current on the pages that sit under it', function (string $name) {
    Route::middleware('web')->get('/_test/'.$name, fn () => view('layouts.app', ['slot' => 'x']))->name($name);
    app('router')->getRoutes()->refreshNameLookups();

    $navs = primaryNavs($this->actingAs(User::factory()->create())->get('/_test/'.$name)->getContent());

    expect($navs['bottom'])->toMatch('#<a\s+href="'.preg_quote(route('library.index'), '#').'"[^>]*aria-current="page"#');
})->with(['collections.index', 'collections.show', 'history.index']);
