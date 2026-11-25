<?php

use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    (new DeweySeeder)->run();

    $action = Genre::factory()->create(['name' => 'Action', 'slug' => 'action']);

    foreach (range(1, 3) as $number) {
        $media = Media::factory()->manga()->create(['title' => "Panel Tale {$number}", 'cover_url' => '/favicon.svg']);
        $media->genres()->attach($action);
        classified($media, '813');
    }

    Media::factory()->novel()->create(['title' => 'Prose Tale']);
});

test('discover passes the shared checks on desktop and on a phone, and an old filter link lands on search', function () {
    foreach ([['width' => 1280, 'height' => 900], ['width' => 375, 'height' => 812]] as $viewport) {
        visit(route('discover'), ['viewport' => $viewport])->wait(1)
            ->assertSee('Browse by Format')->assertSee('3 stories')->assertDontSee('Top Genres')->assertDontSee('Find Stories')
            ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();

        visit(route('discover', ['type' => 'manga', 'genre' => 'action']), ['viewport' => $viewport])->wait(1)
            ->assertPathIs('/search')->assertSee('Panel Tale 1')->assertSee('Top Genres')->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    }
});

test('choosing a format tile opens the search page with that format applied', function () {
    visit(route('discover'), ['viewport' => ['width' => 1280, 'height' => 700]])->wait(1)
        ->assertDontSee('Panel Tale 1')
        ->click('[data-format-tile]:has-text("Manga")')->wait(1.5)
        ->assertPathIs('/search')
        ->assertSee('Panel Tale 1')->assertDontSee('Prose Tale')
        ->assertScript('decodeURIComponent(location.search).includes("filters[mediaTypes][0]=manga")', true)
        ->assertNoJavaScriptErrors();
});

test('Enter in the search panel opens the search with the typed text', function () {
    visit(route('discover'))->wait(1)
        ->click('main search [role=combobox]')->type('main search [role=combobox]', 'berserk')->keys('main search [role=combobox]', 'Enter')->wait(1)
        ->assertPathIs('/search')
        ->assertQueryStringHas('q', 'berserk')
        ->assertNoJavaScriptErrors();
});

test('a single letter does not leave the page', function () {
    visit(route('discover'))->wait(1)
        ->click('main search [role=combobox]')->type('main search [role=combobox]', 'b')->keys('main search [role=combobox]', 'Enter')->wait(0.5)
        ->assertPathIs('/discover');
});
