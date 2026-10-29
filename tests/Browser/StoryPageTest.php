<?php

use App\Catalog\Ingestion\Deduplicator;
use App\Enums\ReadingStatus;
use App\Models\Collection;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\Rating;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->story = fullMedia();

    foreach (range(1, 4) as $number) {
        Media::factory()->novel()->create(['title' => "Dune Echo {$number}", 'cover_url' => '/favicon.svg'])->genres()->attach($this->story->genres);
    }

    $this->desktop = ['viewport' => ['width' => 1280, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];

    $this->tabs = 'Array.from(document.querySelectorAll("[role=tab]")).filter((tab) => tab.getAttribute("aria-selected") === "true").map((tab) => tab.textContent.trim()).join("|")';
    $this->visiblePanel = 'Array.from(document.querySelectorAll("[role=tabpanel]")).filter((panel) => getComputedStyle(panel).display !== "none").map((panel) => panel.querySelector("h2").textContent.trim()).join("|")';
    $this->scrollDown = 'window.scrollTo(0, document.body.scrollHeight)';
});

test('the story page passes the shared checks on desktop and on a phone, shelf in view', function () {
    foreach ([$this->desktop, $this->phone] as $viewport) {
        $page = visit(route('stories.show', $this->story->slug), $viewport)->wait(1);
        $page->script($this->scrollDown);

        $page->wait(1)->assertSee('More Like This')->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    }
});

test('the library controls add the story, change its status, favorite it and take it out', function () {
    $page = visit(route('stories.show', $this->story->slug), $this->desktop)->wait(1);

    $page->click('main button:has-text("Add to Library")')->wait(1)
        ->assertSee('was added to your library')
        ->assertScript('document.querySelector("[data-test=library-status]").textContent.trim()', 'Want to read')
        ->click('[data-test=library-status]')->wait(0.5)
        ->click('ui-menu button:has-text("Completed")')->wait(1)
        ->assertScript('document.querySelector("[data-test=library-status]").textContent.trim()', 'Completed')
        ->click('[aria-label="Add to favorites"]')->wait(1)
        ->assertScript('document.querySelector("[aria-label=\"Remove from favorites\"]").getAttribute("aria-pressed")', 'true')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues();

    $entry = LibraryEntry::sole();
    expect($entry->status)->toBe(ReadingStatus::Completed)->and($entry->favorited_at)->not->toBeNull();

    $page->click('[data-test=library-status]')->wait(0.5)
        ->click('ui-menu button:has-text("Remove from library")')->wait(1)
        ->assertSee('Add to Library');

    expect(LibraryEntry::count())->toBe(0);
});

test('a reader rates the story from the controls, finds the score again after a reload, and clears it', function () {
    $checked = 'Array.from(document.querySelectorAll("[data-test=rating] [role=radio]")).filter((r) => r.getAttribute("aria-checked") === "true").map((r) => r.dataset.value).join("|")';
    providerMetrics($this->story, 'openlibrary', ['average_rating' => 4.5, 'ratings_count' => 120]);
    $page = visit(route('stories.show', $this->story->slug), $this->desktop)->wait(1);

    $page->assertSee('Rated 4.5 out of 5 from 120 ratings')
        ->assertSee('Not rated yet')
        ->click('[data-test=rating] [aria-label="7 out of 10"]')->wait(1)
        ->assertScript($checked, '7');

    expect(Rating::sole()->score)->toBe(7)->and(LibraryEntry::count())->toBe(0);

    $page->navigate(route('stories.show', $this->story->slug))->wait(1)
        ->assertScript($checked, '7')
        ->assertSee('Rated 4.5 out of 5 from 121 ratings')
        ->click('[data-test=rating] [aria-label="Clear your rating"]')->wait(1)
        ->assertScript($checked, '');

    expect(Rating::count())->toBe(0)->and($this->story->fresh()->ratings_count)->toBe(120)
        ->and((float) $this->story->fresh()->average_rating)->toBe(4.5);
});

test('the rating input is on its own row under the buttons, and passes the audit on a phone', function () {
    $page = visit(route('stories.show', $this->story->slug), $this->phone)->wait(1);

    $page->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->assertScript('(() => { const rating = document.querySelector("[data-test=rating]").getBoundingClientRect(); const status = document.querySelector("[data-test=collection-menu]").getBoundingClientRect(); return rating.top >= status.bottom - 1 && rating.right <= window.innerWidth && document.documentElement.scrollWidth <= window.innerWidth; })()', true);
});

test('the library controls pass the audit on a phone too, and their menu items are 44px targets', function () {
    $collection = Collection::factory()->for(auth()->user())->create(['name' => 'Rainy Day']);
    $open = 'Array.from(document.querySelectorAll("[data-library-controls] ui-menu:popover-open [data-flux-menu-item], [data-library-controls] ui-menu:popover-open [data-flux-menu-checkbox]"))';
    $page = visit(route('stories.show', $this->story->slug), $this->phone)->wait(1);

    $page->click('main button:has-text("Add to Library")')->wait(1)
        ->click('[data-test=library-status]')->wait(0.5)
        ->assertScript("{$open}.length", 6)
        ->assertScript("{$open}.every((item) => item.getBoundingClientRect().height >= 44)", true)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->keys('[data-test=library-status]', 'Escape')->wait(0.3)
        ->click('[data-test=collection-menu]')->wait(0.5)
        ->assertScript("{$open}.length", 2) // the collection and "New collection"
        ->assertScript("{$open}.every((item) => item.getBoundingClientRect().height >= 44)", true)
        ->assertNoAccessibilityIssues();

    expect($collection->items()->count())->toBe(0);
});

test('the tabs switch between overview, series and related', function () {
    $page = visit(route('stories.show', $this->story->slug), $this->desktop)->wait(1);

    $page->assertScript($this->tabs, 'Overview')
        ->assertScript($this->visiblePanel, 'Genres')
        ->click('[role=tab]:has-text("Series")')->wait(0.5)
        ->assertScript($this->tabs, 'Series')
        ->assertScript($this->visiblePanel, 'Dune Chronicles')
        ->assertSee('Dune Messiah')
        ->click('[role=tab]:has-text("Related")')->wait(0.5)
        ->assertScript($this->visiblePanel, 'Related Stories')
        ->assertSee('Dune: The Movie')
        ->assertNoJavaScriptErrors();
});

test('the series links walk to the next story and back, and the content warnings open', function () {
    $page = visit(route('stories.show', $this->story->slug), $this->desktop)->wait(1);

    $page->click('nav[aria-label="Series navigation"] a')->wait(1)
        ->assertPathBeginsWith('/stories/dune-messiah')
        ->assertSee('Previous in series')
        ->assertDontSee('Next in series')
        ->click('nav[aria-label="Series navigation"] a')->wait(1)
        ->assertPathIs('/stories/'.$this->story->slug)
        ->assertNoJavaScriptErrors();

    $page->assertDontSee('Violence')
        ->click('summary:has-text("Content Warnings")')->wait(0.6)
        ->assertSee('Violence');
});

test('a result clicked on the search page opens the story at its plain url', function () {
    visit(route('search', ['q' => 'dune echo']), $this->desktop)->wait(1)
        ->click('Dune Echo 1')->wait(1)
        ->assertPathBeginsWith('/stories/dune-echo-1')
        ->assertQueryStringMissing('sq')
        ->assertSee('Dune Echo 1')
        ->assertNoJavaScriptErrors();
});

test('an old merged slug lands on the winner', function () {
    [$winner, $loser] = [Media::factory()->create(['title' => 'Winner']), Media::factory()->create(['title' => 'Loser'])];
    app(Deduplicator::class)->merge($winner, $loser);

    visit(route('stories.show', $loser->slug), $this->desktop)->wait(1)
        ->assertPathIs('/stories/'.$winner->slug)
        ->assertSee('Winner');
});

test('the similar shelf has a hover preview per story (one shared card) and keeps the quick view for touch devices', function () {
    $page = visit(route('stories.show', $this->story->slug), $this->desktop)->wait(1);
    $page->script($this->scrollDown);

    $page->wait(1)
        ->assertScript('document.querySelectorAll("[data-story-preview]").length >= 5', true)
        ->assertScript('document.querySelectorAll("[data-story-card]").length', 1)
        ->assertScript('Array.from(document.querySelectorAll("button")).filter((button) => button.textContent.includes("Quick view") && button.offsetParent !== null).length', 0);
});

test('the breadcrumb stays on one line on a phone and shows every crumb on a desktop', function () {
    $trail = 'Array.from(document.querySelectorAll("nav[aria-label=Breadcrumb] li")).filter((li) => li.offsetParent !== null).map((li) => li.textContent.trim().replace(/\\s+/g, " "))';

    visit(route('stories.show', $this->story->slug), $this->phone)->wait(1)
        ->assertScript('(() => { const tops = new Set(Array.from(document.querySelectorAll("nav[aria-label=Breadcrumb] li")).filter((li) => li.offsetParent !== null).map((li) => Math.round((li.getBoundingClientRect().top + li.getBoundingClientRect().bottom) / 2))); return tops.size; })()', 1)
        ->assertScript($trail.'.length', 3)
        ->assertScript($trail.'[1]', '…');

    visit(route('stories.show', $this->story->slug), $this->desktop)->wait(1)
        ->assertScript($trail.'.length', 4);
});
