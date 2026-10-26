<?php

use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

beforeEach(function () {
    View::addLocation(base_path('tests/Fixtures/views'));
    Route::middleware('web')->get('/_test/foundation', fn () => view('test-foundation'));
});

test('the landing page links through to the login page', function () {
    visit(route('landing'))
        ->click('Sign in')
        ->assertPathIs('/login')
        ->assertNoJavaScriptErrors();
});

test('alpine ui and motion are registered on the page', function () {
    visit('/_test/foundation')
        ->assertScript('typeof window.Alpine !== "undefined"', true)
        ->assertScript('Alpine.version', '3.17.5')
        ->assertScript('document.getElementById("ui-tab").getAttribute("type")', 'button')
        ->assertNoJavaScriptErrors();
});

test('motion reveal ends with its children visible', function () {
    visit('/_test/foundation')
        ->wait(1)
        ->assertScript('getComputedStyle(document.querySelector("#reveal li")).opacity', '1')
        ->assertNoJavaScriptErrors();
});

test('motion reveal leaves the final state alone under reduced motion', function () {
    visit('/_test/foundation', ['reducedMotion' => 'reduce'])
        ->assertScript('document.querySelector("#reveal li").style.opacity', '')
        ->assertScript('getComputedStyle(document.querySelector("#reveal li")).opacity', '1');
});

test('flux scripts still load alongside the bundled livewire', function () {
    $this->actingAs(UserFactory::new()->create());

    visit(route('home'))
        ->assertScript('typeof window.Flux !== "undefined"', true)
        ->assertNoJavaScriptErrors();
});
