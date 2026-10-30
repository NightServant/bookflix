<?php

use App\Models\Media;
use App\Models\User;

beforeEach(function () {
    $this->media = Media::factory()->count(2)->novel()->sequence(
        ['title' => 'Frankenstein', 'slug' => 'frankenstein'],
        ['title' => 'Dracula', 'slug' => 'dracula'],
    )->create(['cover_url' => '/favicon.svg', 'description' => 'A young scientist builds a creature he cannot control.'])
        ->map(fn ($media) => $media->fresh(['mediaType', 'publicPrimaryDewey.deweyClass']));

    // The quick view buttons show on coarse pointers only, so the phone is a touch device.
    $this->path = componentPage(<<<'BLADE'
        <x-story-previews name="quick-view-test">
            <ul class="flex gap-4">
                @foreach ($media as $item)
                    <li wire:key="q-{{ $item->id }}"><x-story-preview :media="$item"><x-cover-tile :media="$item" /></x-story-preview></li>
                @endforeach
            </ul>
        </x-story-previews>
        BLADE, ['media' => $this->media]);

    $this->phone = ['viewport' => ['width' => 375, 'height' => 812], 'hasTouch' => true, 'isMobile' => true];
    $this->trigger = '[data-story-preview] button';
    $this->first = 'document.querySelector("dialog")';
    $this->isOpen = 'document.querySelector("dialog").open';
    $this->focusInside = 'document.querySelector("dialog").contains(document.activeElement)';
});

test('the quick view passes the shared checks on a phone, opens, and closes on Escape with focus back on the trigger, before and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        // Back from the other page the pointer rests where the link was, which is over the first tile and would open its hover card, so dismiss that and move the pointer off first (its card would cover the link on a phone).
        $page->keys(':root', 'Escape')->hover('a[href="/_test/elsewhere"]')->wait(0.6)
            ->assertScript($this->isOpen, false)
            ->click('Quick view of Frankenstein')->wait(0.6)
            ->assertScript($this->isOpen, true)
            ->keys(':focus', 'Escape')->wait(0.4)
            ->assertScript($this->isOpen, false)
            ->assertScript('document.activeElement.textContent.replace(/\s+/g, " ").trim()', 'Quick view of Frankenstein');
    }, $this->phone);
});

test('each trigger is a visible 44px button that names its story', function () {
    $page = visit($this->path, $this->phone)->wait(1);

    $page->assertScript('Array.from(document.querySelectorAll("'.$this->trigger.'")).map((b) => b.textContent.replace(/\s+/g, " ").trim()).join("|")', 'Quick view of Frankenstein|Quick view of Dracula')
        ->assertScript('Array.from(document.querySelectorAll("'.$this->trigger.'")).every((b) => b.getBoundingClientRect().height >= 44 && b.getBoundingClientRect().width >= 44)', true)
        ->assertScript('Array.from(document.querySelectorAll("'.$this->trigger.'")).every((b) => b.firstElementChild.nextElementSibling.firstChild.textContent.trim() === "Quick view")', true);
});

test('the open dialog is named by the title, holds the summary, traps focus and has no accessibility issues', function () {
    $page = visit($this->path, $this->phone)->wait(1);

    $page->click('Quick view of Frankenstein')->wait(0.6)
        ->assertScript($this->isOpen, true)
        ->assertScript($this->focusInside, true)
        ->assertScript('document.activeElement.getAttribute("aria-label")', 'Close quick view')
        ->assertScript($this->first.'.getAttribute("aria-labelledby") === '.$this->first.'.querySelector("h2").id', true)
        ->assertScript($this->first.'.querySelector("h2").textContent.trim()', 'Frankenstein')
        ->assertScript($this->first.'.querySelector("img").getAttribute("src")', '/favicon.svg')
        ->assertScript($this->first.'.textContent.includes("A young scientist builds a creature he cannot control.")', true)
        ->assertScript($this->first.'.querySelector("a[data-flux-button]").getAttribute("href")', route('stories.show', 'frankenstein'))
        ->assertScript('Array.from('.$this->first.'.querySelectorAll("a, button")).every((el) => el.getBoundingClientRect().height >= 44)', true)
        ->assertScript($this->first.'.getBoundingClientRect().right <= innerWidth && '.$this->first.'.getBoundingClientRect().left >= 0', true)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues();

    foreach (range(1, 6) as $ignored) {
        $page->keys(':focus', 'Tab')->assertScript($this->focusInside, true);
    }

    foreach (range(1, 6) as $ignored) {
        $page->keys(':focus', 'Shift+Tab')->assertScript($this->focusInside, true);
    }
});

test('there is one dialog for every tile, and each trigger fills it with its own story', function () {
    $page = visit($this->path, $this->phone)->wait(1);

    $page->assertScript('document.querySelectorAll("dialog").length', 1)
        ->assertScript('document.querySelectorAll("[data-story-summary]").length', 0)
        ->click('Quick view of Dracula')->wait(0.6)
        ->assertScript($this->isOpen, true)
        ->assertScript($this->first.'.querySelector("h2").textContent.trim()', 'Dracula')
        ->assertScript($this->first.'.querySelector("a[data-flux-button]").getAttribute("href")', route('stories.show', 'dracula'))
        ->keys(':focus', 'Escape')->wait(0.4)
        ->assertScript($this->isOpen, false)
        ->click('Quick view of Frankenstein')->wait(0.6)
        ->assertScript($this->first.'.querySelector("h2").textContent.trim()', 'Frankenstein')
        ->assertScript($this->first.'.getAttribute("aria-labelledby") === '.$this->first.'.querySelector("h2").id', true)
        ->assertScript('document.querySelectorAll("[data-story-summary]").length', 1)
        ->assertScript('document.querySelectorAll("dialog").length', 1);
});

test('the buttons are for touch only: a mouse sees none', function () {
    $page = visit($this->path, ['viewport' => ['width' => 1280, 'height' => 800]])->wait(1);

    $page->assertScript('Array.from(document.querySelectorAll("'.$this->trigger.'")).filter((b) => b.offsetParent !== null).length', 0);
});

test('the visible 44px close button closes it and gives the focus back', function () {
    $page = visit($this->path, $this->phone)->wait(1);

    $page->click('Quick view of Frankenstein')->wait(0.6)
        ->click('dialog[open] [aria-label="Close quick view"]')->wait(0.4)
        ->assertScript($this->isOpen, false)
        ->assertScript('document.activeElement.textContent.replace(/\\s+/g, " ").trim()', 'Quick view of Frankenstein');
});

test('Add to Library announces the media', function () {
    $this->actingAs(User::factory()->create());
    $page = visit($this->path, $this->phone)->wait(1);

    $page->script('window.addEventListener("library-add", (e) => (window.__added = e.detail.id))');

    $page->click('Quick view of Frankenstein')->wait(0.6)
        ->click('dialog[open] [aria-label="Add to Library"]')
        ->assertScript('window.__added', $this->media[0]->id);
});

test('the quick view opens without transitions when motion is reduced', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->keys(':root', 'Escape')->hover('a[href="/_test/elsewhere"]')->wait(0.6)
            ->click('Quick view of Frankenstein')->wait(0.3)
            ->assertScript($this->isOpen, true)
            ->assertScript('getComputedStyle('.$this->first.').transitionProperty', 'none')
            ->assertScript('getComputedStyle('.$this->first.').opacity', '1')
            ->keys(':focus', 'Escape')->wait(0.2)
            ->assertScript($this->isOpen, false);
    }, [...$this->phone, 'reducedMotion' => 'reduce']);
});

test('keyboard focus on a quick view button does not open the hover card', function () {
    $page = visit($this->path, $this->phone)->wait(1);

    // Tab to the tile's link, dismiss its card, then Tab on to the button.
    $page->keys(':root', 'Tab')->wait(0.5)
        ->keys(':focus', 'Escape')->wait(0.2)
        ->keys(':focus', 'Tab')->wait(0.7)
        ->assertScript('document.activeElement.textContent.replace(/\\s+/g, " ").trim()', 'Quick view of Frankenstein')
        ->assertScript('getComputedStyle(document.querySelector("[data-story-card]")).display', 'none');
});
