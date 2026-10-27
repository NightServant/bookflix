<?php

use App\Discovery\Search\SearchTextBuilder;
use App\Models\Media;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

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

test('typing lists matching stories and Enter on the chosen one opens its story page', function () {
    $page = visit($this->path)->wait(1);

    $page->click($this->trigger)->wait(0.6)
        ->keys($this->input, ['d', 'u', 'n', 'e'])->wait(1.2)
        ->assertScript('Array.from(document.querySelectorAll("[role=dialog] [role=option]")).length', 2)
        ->assertScript($this->results, 'Dune Chronicle One|Dune Chronicle Two')
        ->assertNoAccessibilityIssues()
        ->keys($this->input, ['ArrowDown', 'Enter'])->wait(1.5)
        ->assertPathBeginsWith('/stories/dune-chronicle-')
        ->assertScript('document.querySelector("h1").textContent.trim().startsWith("Dune Chronicle")', true)
        ->assertNoJavaScriptErrors();
});

test('clicking a result opens its story page', function () {
    $page = visit($this->path)->wait(1);

    $page->click($this->trigger)->wait(0.6)
        ->keys($this->input, ['d', 'u', 'n', 'e'])->wait(1.2)
        ->click('[role=dialog] [role=option]:has-text("Dune Chronicle Two")')->wait(1.5)
        ->assertPathBeginsWith('/stories/dune-chronicle-two')
        ->assertScript('document.querySelector("h1").textContent.trim()', 'Dune Chronicle Two')
        ->assertNoJavaScriptErrors();
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

test('a modified click on a result is left to the browser: not prevented, not picked, the palette stays open', function () {
    $page = visit($this->path)->wait(1);

    $page->click($this->trigger)->wait(0.6)
        ->keys($this->input, ['d', 'u', 'n', 'e'])->wait(1.2)
        // The click is dispatched once and its outcome remembered (a script assertion may run its expression again). The link's href
        // is parked on a hash first, so the browser's own default for a modified click cannot send this tab away: the page's own handlers are what is under test.
        ->assertScript('window.metaClickPrevented ??= (() => {
            const option = document.querySelector("[role=dialog] [role=option]");
            option.setAttribute("href", "#");
            const event = new MouseEvent("click", { bubbles: true, cancelable: true, metaKey: true });
            option.dispatchEvent(event);

            return event.defaultPrevented;
        })()', false)
        ->wait(1)
        ->assertScript($this->dialogOpen, true)
        ->assertScript('location.pathname', '/_test/component');
});
