<?php

use App\Enums\ReadingStatus;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\Rating;
use App\Models\User;
use Database\Seeders\DeweySeeder;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    (new DeweySeeder)->run();

    $rows = [
        ['Berserk', 'manga', ReadingStatus::Reading, true],
        ['Dune', 'novel', ReadingStatus::Completed, false],
        ['Frankenstein', 'novel', ReadingStatus::WantToRead, true],
        ['Monster', 'manga', ReadingStatus::Paused, false],
        ['Dracula', 'novel', ReadingStatus::Abandoned, false],
        ['Vagabond', 'manga', ReadingStatus::Reading, false],
    ];

    foreach ($rows as $index => [$title, $type, $status, $favorite]) {
        $media = Media::factory()->ofType($type)->create(['title' => $title, 'cover_url' => '/favicon.svg']);
        classified($media, $type === 'novel' ? '813' : '741.52');

        LibraryEntry::factory()->for($this->user)->for($media)->status($status)->when($favorite, fn ($factory) => $factory->favorite())->create(['created_at' => now()->subDays($index)]);
    }

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];

    // Small JS probes, so each test reads like the behaviour it checks.
    $this->titles = 'Array.from(document.querySelectorAll("main [data-view] li a[href*=stories]:not([aria-hidden])")).map((a) => a.textContent.trim()).filter(Boolean).join("|")';
    $this->param = fn (string $name) => 'new URLSearchParams(location.search).get("'.$name.'")';
});

test('the library passes the shared checks in every layout, on a desktop and on a phone', function (string $view) {
    foreach ([$this->desktop, $this->phone] as $viewport) {
        $page = visit(route('library.index', ['view' => $view]), $viewport)->wait(1);
        scrolledThrough($page);

        $page->assertSee('Library')->assertSee('Berserk')->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    }
})->with(['grid', 'list', 'shelves']);

test('nothing spills sideways, and the first thing starts on the header gutter', function () {
    foreach ([$this->desktop, $this->phone] as $viewport) {
        visit(route('library.index'), $viewport + ['reducedMotion' => 'reduce'])->wait(1)
            ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
            ->assertScript('Math.abs(document.querySelector("main h1").getBoundingClientRect().left - document.querySelector("header a").getBoundingClientRect().left) < 1.5', true);
    }
});

test('a status tab narrows the library, and its chip takes the filter off again', function () {
    $page = visit(route('library.index'), $this->desktop)->wait(1);

    $page->click('nav[aria-label=Status] button:has-text("Reading")')->wait(0.8)
        ->assertScript($this->titles, 'Berserk|Vagabond')
        ->assertScript(($this->param)('status'), 'reading')
        ->assertSee('2 stories')
        ->assertNoAccessibilityIssues()
        ->click('[aria-label="Remove filter: Reading"]')->wait(0.8)
        ->assertSee('6 stories')
        ->assertScript(($this->param)('status'), null);
});

test('the format select and the favorites toggle narrow it together, and clear all empties the chips', function () {
    $page = visit(route('library.index'), $this->desktop)->wait(1);

    $page->select('#library-type', 'manga')->wait(0.8)
        ->assertSee('3 stories')
        ->click('button:has-text("Favorites")')->wait(0.8)
        ->assertScript($this->titles, 'Berserk')
        ->assertScript('document.querySelector("button[aria-pressed=true]:has(svg)") !== null', true)
        ->click('ul[aria-label="Active filters"] button:has-text("Clear all")')->wait(0.8)
        ->assertSee('6 stories')
        ->assertScript('document.querySelectorAll("ul[aria-label=\"Active filters\"]").length', 0);
});

test('the layout switch changes the view, keeps it in the url, and works from the keyboard', function () {
    $page = visit(route('library.index'), $this->desktop)->wait(1);

    $page->click('Shelves')->wait(1)
        ->assertScript(($this->param)('view'), 'shelves')
        ->assertSee('Literature')->assertSee('Arts & Play')
        ->click('List')->wait(1)
        ->assertScript(($this->param)('view'), 'list')
        ->assertScript('document.querySelectorAll("[data-view=list] article").length', 6)
        ->keys('[role=radio][aria-checked=true]', 'ArrowLeft')->wait(0.8)
        ->assertScript(($this->param)('view'), null)
        ->assertScript('document.querySelectorAll("[data-view=grid] li").length', 6);
});

test('a link with the view in it opens on that view', function () {
    visit(route('library.index', ['view' => 'list', 'sort' => 'title']), $this->desktop)->wait(1)
        ->assertScript('Array.from(document.querySelectorAll("[role=radio]")).filter((r) => r.getAttribute("aria-checked") === "true").map((r) => r.textContent.trim()).join("|")', 'List')
        ->assertScript('document.querySelector("#library-sort").value', 'title')
        ->assertScript($this->titles, 'Berserk|Dracula|Dune|Frankenstein|Monster|Vagabond');
});

test('sorting by your rating lists the highest score first, shows the scores, and keeps the choice in the url', function () {
    foreach (['Vagabond' => 9, 'Dune' => 5, 'Berserk' => 5] as $title => $score) {
        Rating::factory()->create(['user_id' => $this->user->id, 'media_id' => Media::where('title', $title)->value('id'), 'score' => $score]);
    }

    $page = visit(route('library.index'), $this->desktop)->wait(1);

    $page->select('#library-sort', 'rating')->wait(1)
        ->assertScript(($this->param)('sort'), 'rating')
        ->assertSee('9/10')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->click('List')->wait(1)
        ->assertScript($this->titles, 'Vagabond|Berserk|Dune|Dracula|Frankenstein|Monster')
        ->assertSee('9 out of 10')
        ->assertNoAccessibilityIssues();
});

test('an empty library and a filter that matches nothing both explain themselves and pass the audit', function (array $viewport) {
    $page = visit(route('library.index', ['status' => 'paused', 'type' => 'novel']), $viewport)->wait(1);
    $page->assertSee('No stories match')->assertNoJavaScriptErrors()->assertNoAccessibilityIssues()
        ->click('Clear filters')->wait(0.8)
        ->assertSee('6 stories');

    LibraryEntry::query()->delete();

    visit(route('library.index'), $viewport)->wait(1)
        ->assertSee('Your library is empty')->assertSee('Browse Discover')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
})->with(['desktop' => fn () => ['viewport' => ['width' => 1440, 'height' => 900]], 'phone' => fn () => ['viewport' => ['width' => 375, 'height' => 812]]]);

test('the format and sort selects, the favorites toggle and the layout options are 44px targets', function () {
    $page = visit(route('library.index'), $this->phone)->wait(1);

    $page->assertScript('Array.from(document.querySelectorAll("main select, main [role=radio], main button[aria-pressed]:not(nav button)")).every((el) => el.getBoundingClientRect().height >= 44)', true)
        // The status pills and chips are 36px, and reach 44px through a pseudo-element, as <x-chip> does.
        ->assertScript('Array.from(document.querySelectorAll("main nav[aria-label=Status] button")).every((el) => el.getBoundingClientRect().height === 36 && getComputedStyle(el, "::after").top === "-4px")', true);
});
