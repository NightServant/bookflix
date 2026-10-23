<?php

use App\Models\User;

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
})->with(['profile.edit', 'security.edit', 'privacy.edit']);

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
