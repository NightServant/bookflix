<?php

use App\Models\Genre;
use App\Models\MediaType;
use App\Models\Theme;
use App\Models\User;
use Database\Seeders\MediaTypeSeeder;

/*
 * Settings, Preferences in a real browser: the page with the shared checks, choosing and saving, and the way back to onboarding.
 */

beforeEach(function () {
    (new MediaTypeSeeder)->run();

    $fantasy = Genre::factory()->create(['name' => 'Fantasy', 'slug' => 'fantasy']);
    Genre::factory()->childOf($fantasy)->create(['name' => 'Isekai', 'slug' => 'isekai']);
    Theme::factory()->create(['name' => 'Revenge', 'slug' => 'revenge']);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 390, 'height' => 844]];
    $this->path = route('preferences.edit', absolute: false);
});

test('the preferences page passes the shared checks on desktop and on a phone and never scrolls sideways', function (string $size) {
    visit($this->path, $this->{$size})->wait(1)
        ->assertSee('Preferences')->assertSee('Take the setup again')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
})->with(['desktop', 'phone']);

test('a reader chooses formats, a genre and a theme, saves them, and finds them ticked after a reload', function () {
    $manga = MediaType::where('slug', 'manga')->value('id');

    $page = visit($this->path, $this->desktop)->wait(1);

    $page->click('[data-test=types] label:has(input[value=manga])')
        ->click('[data-test=genres] label:has(input[value=fantasy])')
        ->click('[data-test=themes] label:has(input[value=revenge])')
        ->click('[data-test=update-preferences-button]')->wait(1.5)
        ->assertSee('Preferences saved')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();

    expect($this->user->preferences()->count())->toBe(3)
        ->and($this->user->preferences()->where('preferable_type', 'media_type')->sole()->preferable_id)->toBe($manga);

    $page->navigate($this->path)->wait(1)
        ->assertScript('document.querySelector("input[value=manga]").checked', true)
        ->assertScript('document.querySelector("input[value=fantasy]").checked', true)
        ->assertScript('document.querySelector("input[value=revenge]").checked', true)
        ->assertScript('document.querySelector("input[value=novel]").checked', false);
});

test('the link back to onboarding opens it', function () {
    visit($this->path, $this->desktop)->wait(1)
        ->click('[data-test=retake-onboarding]')->wait(1.5)
        ->assertPathIs('/onboarding')
        ->assertSee('What do you like to read?');
});
