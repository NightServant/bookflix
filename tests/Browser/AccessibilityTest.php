<?php

use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;

/*
 * The accessibility audit (axe, via assertNoAccessibilityIssues) of the pages and states the per-page tests don't visit:
 * Explore, the empty and sparse states, and the palette open over a real page. The rest of the pages are audited in their
 * own tests (Home, Search, Story, Series, Discover) and the journey in SmokeTest ends on an audit too.
 * The audit waits out the entrance animation first, because axe reads contrast mid-fade otherwise.
 */

beforeEach(function () {
    $this->viewports = [
        'desktop' => ['viewport' => ['width' => 1440, 'height' => 900]],
        'phone' => ['viewport' => ['width' => 375, 'height' => 812]],
    ];
});

test('explore and its class pages pass the audit, with stories and without', function (string $viewport) {
    $this->actingAs(User::factory()->create());
    (new DeweySeeder)->run();

    foreach (['813', '510'] as $code) {
        classified(Media::factory()->novel()->count(3)->create(['cover_url' => '/favicon.svg']), $code);
    }

    foreach (['/explore', '/explore/800', '/explore/813', '/explore/510', '/explore/020'] as $path) {
        $page = visit($path, $this->viewports[$viewport])->wait(1);
        scrolledThrough($page);

        $page->assertSee($path === '/explore' ? 'Browse by Subject' : 'Dewey-inspired')->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    }
})->with(['desktop', 'phone']);

test('home passes the audit with an empty catalog, for a reader and for an admin', function (string $viewport, bool $admin) {
    $this->actingAs($admin ? User::factory()->admin()->create() : User::factory()->create());

    visit('/home', $this->viewports[$viewport])->wait(1)
        ->assertSee('The catalog is empty')->assertSee($admin ? 'Sync providers' : 'Set up your reading profile')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
})->with(['desktop', 'phone'])->with([[false], [true]]);

test('a story with nothing but a title passes the audit', function (string $viewport) {
    $this->actingAs(User::factory()->create());
    $story = Media::factory()->novel()->create(['title' => 'Bare Story', 'description' => null, 'cover_url' => null, 'publication_year' => null]);

    visit("/stories/{$story->slug}", $this->viewports[$viewport])->wait(1)
        ->assertSee('Bare Story')->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
})->with(['desktop', 'phone']);

test('the palette open over a real page, with its results listed, passes the audit', function () {
    $this->actingAs(User::factory()->create());
    Media::factory()->count(3)->sequence(fn ($sequence) => ['title' => 'Palette Tale '.($sequence->index + 1), 'cover_url' => '/favicon.svg'])->create()->each->refreshSearchText();

    $page = visit('/discover', $this->viewports['desktop'])->wait(1);

    $page->keys('header [aria-haspopup=dialog]', 'Meta+k')->wait(0.8)
        ->keys('[data-palette-panel] [role=combobox]', str_split('palette'))->wait(1.5)
        ->assertScript('document.querySelectorAll("[data-palette-panel] [role=option]").length', 3)
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
});
