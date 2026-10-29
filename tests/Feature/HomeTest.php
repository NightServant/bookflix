<?php

use App\Models\User;
use Livewire\Livewire;

test('authenticated users are sent from the landing page to home', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('landing'))
        ->assertRedirect(route('home'));
});

test('guests are redirected to login from home', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('authenticated users can visit home', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('home'))->assertOk();

    foreach (primaryNavs($response->getContent()) as $nav) {
        expect($nav)->toContain('href="'.route('home').'"', 'href="'.route('profile.edit').'"');
    }
});

test('home empty state links to the reading profile', function () {
    Livewire::test('pages::home.index')
        ->assertSeeHtml('href="'.route('profile.edit').'"');
});
