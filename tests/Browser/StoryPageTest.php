<?php

use App\Catalog\Ingestion\Deduplicator;
use App\Models\Media;
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
