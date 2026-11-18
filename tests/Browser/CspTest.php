<?php

use App\Models\Media;
use App\Models\User;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/*
 * The Content Security Policy in a real browser: the pages still work under it (a violation is a console error), Livewire and Alpine run, and an
 * inline script that carries no nonce does not.
 */

beforeEach(function () {
    $this->seed(MediaTypeSeeder::class);
    Media::factory()->manga()->create(['title' => 'Dune Chronicle One', 'search_text' => 'dune chronicle one']);
    Route::middleware(['web', 'auth'])->get('/_test/csp', fn () => Blade::render(<<<'HTML'
        <x-layouts::app title="CSP">
            <div x-data="{ open: false }">
                <button id="toggle" type="button" @click="open = ! open">Toggle</button>
                <p id="panel" x-show="open" x-cloak>Open</p>
            </div>
            <script>window.__inlineRan = true</script>
        </x-layouts::app>
        HTML));
    $this->desktop = ['viewport' => ['width' => 1280, 'height' => 900]];
});

test('home and search run clean under the policy for a signed-in reader', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('home'))->assertOk();

    visit(route('home'), $this->desktop)->wait(1)->assertNoJavaScriptErrors()->assertNoConsoleLogs();
    visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1)->assertNoJavaScriptErrors()->assertNoConsoleLogs();
});

test('an admin page runs clean under the policy', function () {
    $this->actingAs(User::factory()->admin()->create());

    visit('/admin', $this->desktop)->wait(1)->assertNoJavaScriptErrors()->assertNoConsoleLogs();
});

test('a livewire interaction still works', function () {
    $this->actingAs(User::factory()->create());

    visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1)
        ->assertScript('document.querySelectorAll("[data-view=grid]").length', 1)
        ->click('List')->wait(1.5)
        ->assertScript('document.querySelectorAll("[data-view=list]").length', 1)
        ->assertQueryStringHas('view', 'list')
        ->assertNoJavaScriptErrors();
});

test('an alpine toggle still works, and an inline script without a nonce does not run', function () {
    $this->actingAs(User::factory()->create());

    visit('/_test/csp', $this->desktop)->wait(1)
        ->assertScript('typeof window.Alpine !== "undefined"', true)
        ->assertScript('document.getElementById("panel").checkVisibility()', false)
        ->click('#toggle')->wait(0.5)
        ->assertScript('document.getElementById("panel").checkVisibility()', true)
        ->assertScript('window.__inlineRan === true', false);
});
