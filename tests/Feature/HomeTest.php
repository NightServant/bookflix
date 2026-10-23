<?php

use App\Models\User;
use Livewire\Livewire;

test('guests see the landing page', function () {
    $this->get(route('landing'))->assertOk()->assertSee('Bookflix');
});

test('authenticated users are sent from the landing page to home', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('landing'))
        ->assertRedirect(route('home'));
});

test('guests are redirected to login from home', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('authenticated users can visit home', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertSeeInOrder(['Home', 'Profile']);
});

test('home empty state links to the reading profile', function () {
    Livewire::test('pages::home.index')
        ->assertSeeHtml('href="'.route('profile.edit').'"');
});
