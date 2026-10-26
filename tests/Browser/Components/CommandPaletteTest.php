<?php

use App\Discovery\Search\SearchTextBuilder;
use App\Models\Media;

beforeEach(function () {
    foreach (['Dune Chronicle One', 'Dune Chronicle Two', 'Berserk'] as $title) {
        $media = Media::factory()->create(['title' => $title]);
        $media->update(['search_text' => app(SearchTextBuilder::class)->build($media)]);
    }

    $this->path = componentPage('<x-command-palette />');

    $this->trigger = '[aria-haspopup=dialog]';
    $this->input = '[role=dialog] [role=combobox]';
    $this->dialogOpen = 'getComputedStyle(document.querySelector("[role=dialog]")).display !== "none"';
    $this->results = 'Array.from(document.querySelectorAll("[role=dialog] [role=option]")).map((o) => o.querySelector("span").textContent.trim()).join("|")';
    $this->onTrigger = 'document.activeElement === document.querySelector("[aria-haspopup=dialog]")';
    // The story page does not exist yet (Phase 4.3), so record where a pick would go and cancel the navigation.
    $this->stubNavigate = 'window.__navigated = null; document.addEventListener("livewire:navigate", (event) => { event.preventDefault(); window.__navigated = event.detail.url.href; })';
});

test('the palette passes the shared checks closed, and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript($this->dialogOpen, false)
            ->assertScript('document.querySelector("[aria-haspopup=dialog]").getBoundingClientRect().height >= 44', true);
    });
});

test('the trigger opens a modal dialog with focus in the search field, which has no accessibility issues', function () {
    $page = visit($this->path)->wait(1);

    $page->click($this->trigger)->wait(0.6)
        ->assertScript($this->dialogOpen, true)
        ->assertScript('document.querySelector("[role=dialog]").getAttribute("aria-modal")', 'true')
        ->assertScript('document.querySelector("[role=dialog]").getAttribute("aria-labelledby") === document.querySelector("[role=dialog] h2").id', true)
        ->assertScript('document.activeElement === document.querySelector("[role=dialog] [role=combobox]")', true)
        ->assertNoAccessibilityIssues();
});

test('command-k and control-k open it from anywhere', function () {
    $page = visit($this->path)->wait(1);

    $page->keys($this->trigger, 'Control+k')->wait(0.6)
        ->assertScript($this->dialogOpen, true)
        ->keys($this->input, 'Escape')->wait(0.3)
        ->assertScript($this->dialogOpen, false)
        ->keys($this->trigger, 'Meta+k')->wait(0.6)
        ->assertScript($this->dialogOpen, true);
});

test('typing lists matching stories and Enter on the first opens it', function () {
    $page = visit($this->path)->wait(1);
    $page->script($this->stubNavigate);

    $page->click($this->trigger)->wait(0.6)
        ->keys($this->input, ['d', 'u', 'n', 'e'])->wait(1.2)
        ->assertScript('Array.from(document.querySelectorAll("[role=dialog] [role=option]")).length', 2)
        ->assertScript($this->results, 'Dune Chronicle One|Dune Chronicle Two')
        ->assertNoAccessibilityIssues()
        ->keys($this->input, ['ArrowDown', 'Enter'])->wait(0.4)
        ->assertScript('window.__navigated.includes("/stories/dune-chronicle-")', true)
        ->assertScript($this->dialogOpen, false);
});

test('Escape first closes the results, then the dialog, and focus goes back to the trigger', function () {
    $page = visit($this->path)->wait(1);

    $page->click($this->trigger)->wait(0.6)
        ->keys($this->input, ['b', 'e', 'r'])->wait(1.2)
        ->assertScript('document.querySelector("[role=dialog] [role=combobox]").getAttribute("aria-expanded")', 'true')
        ->keys($this->input, 'Escape')->wait(0.3)
        ->assertScript('document.querySelector("[role=dialog] [role=combobox]").getAttribute("aria-expanded")', 'false')
        ->assertScript($this->dialogOpen, true)
        ->keys($this->input, 'Escape')->wait(0.6)
        ->assertScript($this->dialogOpen, false)
        ->assertScript($this->onTrigger, true);
});

test('the visible close button closes it and returns focus to the trigger', function () {
    $page = visit($this->path)->wait(1);

    $page->click($this->trigger)->wait(0.6)
        ->assertScript('document.querySelector("[aria-label=\"Close search\"]").getBoundingClientRect().height >= 44', true)
        ->click('[aria-label="Close search"]')->wait(0.6)
        ->assertScript($this->dialogOpen, false)
        ->assertScript($this->onTrigger, true);
});

test('focus is trapped inside the dialog', function () {
    $page = visit($this->path)->wait(1);

    $page->click($this->trigger)->wait(0.6)
        ->keys($this->input, 'Tab')
        ->assertScript('document.activeElement.getAttribute("aria-label")', 'Close search')
        ->keys('[aria-label="Close search"]', 'Tab')
        ->assertScript('document.activeElement === document.querySelector("[role=dialog] [role=combobox]")', true)
        ->assertScript('document.querySelector("[aria-haspopup=dialog]").closest("[aria-hidden=true]") !== null', true);
});

test('the palette opens and searches with reduced motion', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->click($this->trigger)->wait(0.3)
            ->assertScript($this->dialogOpen, true)
            ->assertScript('getComputedStyle(document.querySelector("[data-palette-panel]")).opacity', '1')
            ->keys($this->input, ['b', 'e', 'r'])->wait(1.2)
            ->assertScript($this->results, 'Berserk')
            ->keys($this->input, ['Escape', 'Escape'])->wait(0.4)
            ->assertScript($this->dialogOpen, false);
    }, ['reducedMotion' => 'reduce']);
});
