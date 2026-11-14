<?php

use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(MediaTypeSeeder::class);

    $this->actingAs(User::factory()->create());

    $fantasy = Genre::factory()->create(['name' => 'Fantasy', 'slug' => 'fantasy']);
    $horror = Genre::factory()->create(['name' => 'Horror', 'slug' => 'horror']);

    foreach (['Dune Chronicle One' => $fantasy, 'Dune Chronicle Two' => $horror] as $title => $genre) {
        $media = Media::factory()->manga()->create(['title' => $title, 'search_text' => strtolower($title)]);
        $media->genres()->attach($genre);
    }

    $this->desktop = ['viewport' => ['width' => 1280, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];

    $this->panel = 'search input[role=combobox]';
    $this->count = 'document.querySelector("[data-test=result-count]").textContent.trim()';
    $this->titles = 'Array.from(document.querySelectorAll("[data-view] li a span.line-clamp-2")).map((title) => title.textContent.trim()).join("|")';
    $this->chips = 'Array.from(document.querySelectorAll("ul[aria-label=\'Active filters\'] ul > li > span:first-child")).map((span) => span.textContent.trim()).join("|")';
});

test('the search panel keeps its combobox wiring when livewire re-renders the page', function () {
    $input = 'main search input[role=combobox]';

    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->assertScript('document.querySelector("'.$input.'").getAttribute("aria-controls") !== null', true)
        ->click('aside [data-filter=mediaTypes][value=manga]')->wait(1.5)
        ->assertScript('document.querySelector("[data-test=result-count]").textContent.trim()', '2 stories')
        ->assertScript('document.querySelector("'.$input.'").getAttribute("aria-controls") !== null', true)
        ->assertScript('document.getElementById(document.querySelector("'.$input.'").getAttribute("aria-controls")) !== null', true);
});

test('the page passes the shared checks with results, with none, and on a phone', function () {
    visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1)->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    visit(route('search', ['q' => 'dune', 'view' => 'list']), $this->desktop)->wait(1)->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    visit(route('search', ['q' => 'zzzxxyy']), $this->desktop)->wait(1)->assertSee('No stories found')->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    visit(route('search'), $this->desktop)->wait(1)->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    visit(route('search', ['q' => 'dune']), $this->phone)->wait(1)->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
});

test('typing in the search panel lists matches as you type, and the text is in the url', function () {
    $page = visit(route('search'), $this->desktop)->wait(1);

    $page->keys($this->panel, ['d', 'u', 'n', 'e'])->wait(1.5)
        ->assertScript($this->count, '2 stories')
        ->assertScript($this->titles, 'Dune Chronicle One|Dune Chronicle Two')
        ->assertQueryStringHas('q', 'dune')
        ->clear($this->panel)->wait(1.5)
        ->assertScript($this->count, '')
        ->assertQueryStringMissing('q');
});

test('the view toggle switches grid and list and the choice is in the url', function () {
    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->assertScript('document.querySelectorAll("[data-view=grid]").length', 1)
        ->click('List')->wait(1.5)
        ->assertScript('document.querySelectorAll("[data-view=list] article").length', 2)
        ->assertQueryStringHas('view', 'list')
        ->click('Grid')->wait(1.5)
        ->assertScript('document.querySelectorAll("[data-view=grid]").length', 1)
        ->assertQueryStringMissing('view');
});

test('a filter shows as a removable chip and narrows the results', function () {
    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->click('aside [data-filter=mediaTypes][value=comic-book]')->wait(1.5)
        ->assertScript($this->chips, 'Comic Book')
        ->assertScript($this->count, 'No stories')
        ->click('[aria-label="Remove filter Comic Book"]')->wait(1.5)
        ->assertScript($this->chips, '')
        ->assertScript($this->count, '2 stories');
});

test('on a phone the filter sheet applies nothing until Apply filters, and closes with it', function () {
    $isOpen = 'document.querySelector("dialog").open';
    $page = visit(route('search', ['q' => 'dune']), $this->phone)->wait(1);

    $page->click('[data-flux-modal-trigger] button')->wait(0.8)
        ->assertScript($isOpen, true)
        ->click('dialog [data-filter=mediaTypes][value=comic-book]')->wait(1.5)
        ->assertScript($isOpen, true)
        ->assertScript('document.querySelector("dialog [data-filter=mediaTypes][value=comic-book]").checked', true)
        ->assertScript($this->chips, '')
        ->assertNoAccessibilityIssues()
        ->click('dialog button:has-text("Apply filters")')->wait(1.5)
        ->assertScript($isOpen, false)
        ->assertScript($this->chips, 'Comic Book')
        ->assertNoJavaScriptErrors();
});

test('the chips for what the search understood work from the keyboard and pass the shared checks', function () {
    Media::query()->update(['search_text' => DB::raw("search_text || ' fantasy manga'")]);
    $applied = "ul[aria-label='From your search'] button";
    $suggested = "ul[aria-label='Narrow by'] button";
    $names = fn (string $selector): string => 'Array.from(document.querySelectorAll("'.$selector.'")).map((button) => button.getAttribute("aria-label")).join("|")';
    $page = visit(route('search', ['q' => 'fantasy manga dune']), $this->desktop)->wait(1);

    $page->assertScript($names($applied), 'Remove Manga from your search|Remove Fantasy from your search')
        ->assertScript($this->count, '1 story')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->script('document.querySelector("button[aria-label=\'Remove Fantasy from your search\']").focus()');

    $page->keys("button[aria-label='Remove Fantasy from your search']", ['Enter'])->wait(1.5)
        ->assertScript($names($applied), 'Remove Manga from your search')
        ->assertScript($names($suggested), 'Filter by Fantasy')
        ->assertScript($this->count, '2 stories')
        ->assertNoAccessibilityIssues()
        ->keys($suggested, ['Enter'])->wait(1.5)
        ->assertScript($this->chips, 'Fantasy')
        ->assertScript($this->count, '1 story')
        ->assertNoJavaScriptErrors();
});

test('the chips wrap on a phone and pass the shared checks', function () {
    Media::query()->update(['search_text' => DB::raw("search_text || ' fantasy manga'")]);
    $page = visit(route('search', ['q' => 'fantasy manga dune']), ['viewport' => ['width' => 390, 'height' => 844]])->wait(1);

    $page->assertScript('document.querySelectorAll("ul[aria-label=\'From your search\'] button").length', 2)
        ->assertScript('Array.from(document.querySelectorAll("ul[aria-label=\'From your search\'] button")).every((button) => button.getBoundingClientRect().height + 8 >= 44)', true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->click('button[aria-label="Remove Manga from your search"]')->wait(1.5)
        ->assertScript('document.querySelectorAll("ul[aria-label=\'Narrow by\'] button").length', 1)
        ->assertNoAccessibilityIssues();
});
