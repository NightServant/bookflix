<?php

use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\MediaTypeSeeder;

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
    $this->chips = 'Array.from(document.querySelectorAll("[aria-label=\'Active filters\'] li > span")).map((span) => span.textContent.trim()).join("|")';
});

test('a combobox keeps aria-controls when livewire re-renders the page', function () {
    $genres = 'document.querySelector("aside [data-test=filter-genres] input[role=combobox]")';

    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->assertScript($genres.'.getAttribute("aria-controls") !== null', true)
        ->keys('aside [data-test=filter-genres] input[role=combobox]', ['f', 'a', 'n'])->wait(0.5)
        ->keys('aside [data-test=filter-genres] input[role=combobox]', 'Enter')->wait(1.5)
        ->assertScript('document.querySelector("[data-test=result-count]").textContent.trim()', '1 story')
        ->assertScript($genres.'.getAttribute("aria-controls") !== null', true)
        ->assertScript('document.getElementById('.$genres.'.getAttribute("aria-controls")) !== null', true);
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

    $page->click('aside ui-checkbox[value=comic-book]')->wait(1.5)
        ->assertScript($this->chips, 'Comic Book')
        ->assertScript($this->count, 'No stories')
        ->click('[aria-label="Remove filter Comic Book"]')->wait(1.5)
        ->assertScript($this->chips, '')
        ->assertScript($this->count, '2 stories');
});

test('on a phone the filter sheet applies a filter without closing, and Show results closes it', function () {
    $isOpen = 'document.querySelector("dialog").open';
    $page = visit(route('search', ['q' => 'dune']), $this->phone)->wait(1);

    $page->click('[data-flux-modal-trigger] button')->wait(0.8)
        ->assertScript($isOpen, true)
        ->click('dialog ui-checkbox[value=comic-book]')->wait(1.5)
        ->assertScript($isOpen, true)
        ->assertScript('document.querySelector("dialog ui-checkbox[value=comic-book]").getAttribute("aria-checked")', 'true')
        ->assertNoAccessibilityIssues()
        ->click('dialog button:has-text("Show results")')->wait(0.6)
        ->assertScript($isOpen, false)
        ->assertScript($this->chips, 'Comic Book')
        ->assertNoJavaScriptErrors();
});
