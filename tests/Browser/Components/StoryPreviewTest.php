<?php

use App\Models\Media;

beforeEach(function () {
    enableLibraryRoute();

    $this->media = Media::factory()->count(3)->novel()->sequence(
        ['title' => 'Frankenstein', 'slug' => 'frankenstein'],
        ['title' => 'Dracula', 'slug' => 'dracula'],
        ['title' => 'Pride and Prejudice', 'slug' => 'pride-and-prejudice'],
    )->create(['cover_url' => '/favicon.svg', 'description' => 'A young scientist builds a creature he cannot control.'])
        ->map(fn ($media) => $media->fresh(['mediaType', 'publicPrimaryDewey.deweyClass']));

    $this->path = componentPage(<<<'BLADE'
        <x-story-previews name="quick-view-test">
            <div class="w-40">
                <x-story-preview :media="$media" data-test="preview">
                    <x-cover-tile :media="$media" data-test="tile" />
                </x-story-preview>
            </div>
        </x-story-previews>
        BLADE, ['media' => $this->media[0]]);

    // Small JS probes, so each test reads like the behaviour it checks. The panel is `position: fixed`, so it has no offsetParent.
    $this->panel = 'document.querySelector("[data-story-card]")';
    $this->isOpen = 'getComputedStyle(document.querySelector("[data-story-card]")).display !== "none"';
    $this->expanded = 'document.querySelector("a[href*=stories]").getAttribute("aria-expanded")';
    $this->focusedTile = 'document.activeElement === document.querySelector("a[href*=stories]")';
    $this->away = 'a[href="/_test/elsewhere"]';

    // A pointer arriving at the trigger, as the mouse would deliver it, then frames until the panel is on screen. Resolves with [ms until shown, opacity then].
    $this->arrive = '(async () => {
        const panel = document.querySelector("[data-story-card]");
        const start = performance.now();
        document.querySelector("[data-story-preview]").firstElementChild.dispatchEvent(new PointerEvent("pointerenter", { pointerType: "mouse" }));
        for (let frame = 0; frame < 200 && getComputedStyle(panel).display === "none"; frame++) {
            await new Promise(requestAnimationFrame);
        }
        return [performance.now() - start, parseFloat(getComputedStyle(panel).opacity)];
    })()';
});

test('a story preview passes the shared checks and opens on hover and closes on leave, before and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        // Back from the other page the pointer rests where the link was, which is over the tile, so move it off first.
        $page->hover($this->away)->wait(0.6)
            ->assertScript($this->isOpen, false)
            ->assertScript($this->expanded, 'false')
            ->hover('@tile')->wait(0.7)
            ->assertScript($this->isOpen, true)
            ->assertScript($this->expanded, 'true')
            ->hover($this->away)->wait(0.6)
            ->assertScript($this->isOpen, false)
            ->assertScript($this->expanded, 'false');
    });
});

test('the preview waits 300ms before it opens', function () {
    $page = visit($this->path)->wait(1);

    $page->assertScript($this->arrive.'.then(([ms]) => ms >= 290 && ms < 600)', true);
});

test('touch never opens it: the quick view is the touch alternative', function () {
    $page = visit($this->path)->wait(1);

    $page->script('document.querySelector("[data-story-preview]").firstElementChild.dispatchEvent(new PointerEvent("pointerenter", { pointerType: "touch" }))');

    $page->wait(0.6)->assertScript($this->isOpen, false);
});

test('keyboard focus opens it after 300ms and the panel is in the tab order after the trigger', function () {
    $page = visit($this->path)->wait(1);

    $page->keys(':root', 'Tab')
        ->assertScript($this->focusedTile, true)
        ->assertScript($this->isOpen, false)
        ->wait(0.7)
        ->assertScript($this->isOpen, true)
        ->assertScript($this->expanded, 'true')
        ->keys(':focus', 'Tab')
        ->assertScript('document.activeElement.textContent.trim()', 'View story')
        ->keys(':focus', 'Tab')
        ->assertScript('document.activeElement.getAttribute("aria-label")', 'Add to Library')
        ->assertScript($this->isOpen, true);
});

test('tabbing out of the panel carries on to the next control and closes it', function () {
    $page = visit($this->path)->wait(1);

    $page->keys(':root', 'Tab')->wait(0.7)
        ->keys(':focus', ['Tab', 'Tab', 'Tab'])->wait(0.3)
        ->assertScript('document.activeElement.textContent.trim()', 'Go elsewhere')
        ->assertScript($this->isOpen, false);
});

test('Shift+Tab from the first control in the panel goes back to the trigger', function () {
    $page = visit($this->path)->wait(1);

    $page->keys(':root', 'Tab')->wait(0.7)
        ->keys(':focus', 'Tab')
        ->keys(':focus', 'Shift+Tab')
        ->assertScript($this->focusedTile, true)
        ->assertScript($this->isOpen, true);
});

test('Escape in the panel closes it and returns focus to the trigger without reopening it', function () {
    $page = visit($this->path)->wait(1);

    $page->keys(':root', 'Tab')->wait(0.7)
        ->keys(':focus', 'Tab')
        ->assertScript('document.activeElement.textContent.trim()', 'View story')
        ->keys(':focus', 'Escape')->wait(0.2)
        ->assertScript($this->isOpen, false)
        ->assertScript($this->focusedTile, true)
        // The focus coming back to the trigger is not a fresh arrival.
        ->wait(0.7)
        ->assertScript($this->isOpen, false);
});

test('Escape on the trigger closes it and keeps the focus there', function () {
    $page = visit($this->path)->wait(1);

    $page->keys(':root', 'Tab')->wait(0.7)
        ->assertScript($this->isOpen, true)
        ->keys(':focus', 'Escape')->wait(0.2)
        ->assertScript($this->isOpen, false)
        ->assertScript($this->focusedTile, true);
});

test('Escape closes a preview opened by hover, with the focus elsewhere', function () {
    $page = visit($this->path)->wait(1);

    $page->hover('@tile')->wait(0.7)
        ->assertScript($this->isOpen, true)
        ->keys(':root', 'Escape')->wait(0.2)
        ->assertScript($this->isOpen, false);
});

test('losing focus closes it', function () {
    $page = visit($this->path)->wait(1);

    $page->keys(':root', 'Tab')->wait(0.7)
        ->assertScript($this->isOpen, true);

    $page->script('document.activeElement.blur()');

    $page->wait(0.3)->assertScript($this->isOpen, false);
});

test('a focus event that is not keyboard focus, as a mouse click leaves, does not open it', function () {
    $page = visit($this->path)->wait(1);

    $page->script('document.querySelector("a[href*=stories]").dispatchEvent(new FocusEvent("focusin", { bubbles: true }))');

    $page->wait(0.7)->assertScript($this->isOpen, false);
});

test('the open panel has the cover, title, facts, blurb and two ctas, sits under the trigger and has no accessibility issues', function () {
    $page = visit($this->path)->wait(1);

    $page->hover('@tile')->wait(0.8)
        ->assertScript($this->panel.'.getAttribute("aria-label")', 'Preview of Frankenstein')
        ->assertScript($this->panel.'.querySelector("h2").textContent.trim()', 'Frankenstein')
        ->assertScript($this->panel.'.querySelector("img").getAttribute("src")', '/favicon.svg')
        ->assertScript($this->panel.'.querySelector("ul").textContent.replace(/\s+/g, " ").trim().startsWith("Novel")', true)
        ->assertScript($this->panel.'.textContent.includes("A young scientist builds a creature he cannot control.")', true)
        ->assertScript($this->panel.'.querySelector("a[data-flux-button]").getAttribute("href")', route('stories.show', 'frankenstein'))
        ->assertScript('getComputedStyle('.$this->panel.').position', 'fixed')
        ->assertScript('(() => { const t = document.querySelector("[data-test=tile]").getBoundingClientRect(); const p = '.$this->panel.'.getBoundingClientRect(); return p.top >= t.bottom && Math.abs(p.left - t.left) < 8 && p.right <= innerWidth && p.bottom <= innerHeight; })()', true)
        ->assertScript('Array.from('.$this->panel.'.querySelectorAll("a, button")).every((el) => el.getBoundingClientRect().height >= 44)', true)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues();
});

test('add to library announces the media', function () {
    $page = visit($this->path)->wait(1);

    $page->script('window.addEventListener("library-add", (e) => (window.__added = e.detail.id))');

    $page->hover('@tile')->wait(0.8)
        ->click('[aria-label="Add to Library"]')
        ->assertScript('window.__added', $this->media[0]->id);
});

test('moving the pointer from the trigger onto the panel keeps it open', function () {
    $page = visit($this->path)->wait(1);

    $page->hover('@tile')->wait(0.7)
        ->hover('[role=dialog] h2')->wait(0.6)
        ->assertScript($this->isOpen, true)
        ->hover($this->away)->wait(0.6)
        ->assertScript($this->isOpen, false);
});

test('inside a scrolling shelf the panel is not clipped by the track', function () {
    $path = componentPage(<<<'BLADE'
        <x-shelf title="Gothic Classics" previews="quick-view-gothic">
            @foreach ($media as $item)
                <li wire:key="m-{{ $item->id }}">
                    <x-story-preview :media="$item"><x-cover-tile :media="$item" data-test="tile-{{ $loop->index }}" /></x-story-preview>
                </li>
            @endforeach
        </x-shelf>
        BLADE, ['media' => $this->media]);

    $page = visit($path)->wait(1.2);

    $page->hover('@tile-1')->wait(0.8)
        ->assertScript('(() => {
            const panel = document.querySelector("[data-story-card]");
            const box = panel.getBoundingClientRect();
            const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);

            return panel.querySelector("h2").textContent.trim() === "Dracula" && getComputedStyle(panel).display !== "none" && panel.contains(hit) && box.bottom > document.querySelector("ul").getBoundingClientRect().bottom;
        })()', true);
});

test('every tile of a shelf shares one card, filled from the tile in use, and only that tile is expanded', function () {
    $path = componentPage(<<<'BLADE'
        <x-shelf title="Gothic Classics" previews="quick-view-gothic">
            @foreach ($media as $item)
                <li wire:key="m-{{ $item->id }}">
                    <x-story-preview :media="$item"><x-cover-tile :media="$item" data-test="tile-{{ $loop->index }}" /></x-story-preview>
                </li>
            @endforeach
        </x-shelf>
        BLADE, ['media' => $this->media]);
    $title = 'document.querySelector("[data-story-card] h2")?.textContent.trim()';
    $expanded = 'Array.from(document.querySelectorAll("[data-story-preview] a")).map((a) => a.getAttribute("aria-expanded")).join()';

    $page = visit($path)->wait(1.2);

    $page->assertScript('document.querySelectorAll("[data-story-card]").length', 1)
        ->assertScript('document.querySelectorAll("dialog").length', 1)
        ->assertScript('document.querySelectorAll("[data-story-summary]").length', 0)
        ->assertScript($expanded, 'false,false,false')
        ->hover('@tile-0')->wait(0.8)
        ->assertScript($title, 'Frankenstein')
        ->assertScript($expanded, 'true,false,false')
        ->assertScript('document.querySelector("[data-story-card]").getAttribute("aria-label")', 'Preview of Frankenstein')
        ->hover('@tile-2')->wait(0.9)
        ->assertScript($title, 'Pride and Prejudice')
        ->assertScript($expanded, 'false,false,true')
        ->assertScript('document.querySelector("[data-story-card]").getAttribute("aria-label")', 'Preview of Pride and Prejudice')
        ->assertScript('(() => { const t = document.querySelector("[data-test=tile-2]").getBoundingClientRect(); const p = document.querySelector("[data-story-card]").getBoundingClientRect(); return p.top >= t.bottom && Math.abs(p.left - t.left) < 8; })()', true)
        ->assertScript('document.querySelectorAll("[data-story-summary]").length', 1)
        ->assertScript('document.querySelectorAll("[data-story-card]").length', 1);
});

test('keyboard focus moving to the next tile moves the card to it', function () {
    $path = componentPage(<<<'BLADE'
        <x-shelf title="Gothic Classics" previews="quick-view-gothic">
            @foreach ($media as $item)
                <li wire:key="m-{{ $item->id }}">
                    <x-story-preview :media="$item"><x-cover-tile :media="$item" /></x-story-preview>
                </li>
            @endforeach
        </x-shelf>
        BLADE, ['media' => $this->media]);
    $title = 'document.querySelector("[data-story-card] h2")?.textContent.trim()';

    $page = visit($path)->wait(1.2);

    // The tiles are the only tab stops on the page.
    $page->keys(':root', 'Tab')->wait(0.7)
        ->assertScript($title, 'Frankenstein')
        ->keys(':focus', 'Escape')->wait(0.2)
        ->keys(':focus', 'Tab')->wait(0.7)
        ->assertScript('document.activeElement.textContent.includes("Dracula")', true)
        ->assertScript($title, 'Dracula')
        ->assertScript('getComputedStyle(document.querySelector("[data-story-card]")).display !== "none"', true);
});

test('with motion the panel springs open from a transparent state and settles with no inline motion styles', function () {
    $page = visit($this->path)->wait(1);

    $page->assertScript($this->arrive.'.then(([, opacity]) => opacity < 1)', true)
        ->wait(1)
        ->assertScript('getComputedStyle('.$this->panel.').opacity', '1')
        ->assertScript($this->panel.'.style.transform === "" && '.$this->panel.'.style.opacity === ""', true);
});

test('with reduced motion the panel appears at once, with no motion styles left on it', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript($this->arrive.'.then(([, opacity]) => opacity === 1)', true)
            ->assertScript($this->panel.'.style.transform === "" && '.$this->panel.'.style.opacity === ""', true)
            ->keys(':root', 'Escape')->wait(0.2)
            ->assertScript($this->isOpen, false)
            ->hover($this->away);
    }, ['reducedMotion' => 'reduce']);
});

test('inside a modal, Escape on a focused tile closes its preview first and the next Escape closes the modal', function () {
    $path = componentPage(<<<'BLADE'
        <flux:modal.trigger name="m"><button type="button">Open modal</button></flux:modal.trigger>
        <flux:modal name="m">
            <x-story-previews name="quick-view-in-modal">
                <div class="w-40">
                    <x-story-preview :media="$media"><x-cover-tile :media="$media" /></x-story-preview>
                </div>
            </x-story-previews>
        </flux:modal>
        BLADE, ['media' => $this->media[0]]);

    $modalOpen = 'document.querySelector("dialog").open';
    $previewOpen = 'getComputedStyle(document.querySelector("[data-story-card]")).display !== "none"';

    $page = visit($path)->wait(1);

    $page->click('Open modal')->wait(0.6)
        ->assertScript($modalOpen, true)
        ->keys('dialog [data-story-preview] a', 'Tab')
        ->keys(':focus', 'Shift+Tab')->wait(0.7)
        ->assertScript('document.activeElement === document.querySelector("dialog [data-story-preview] a")', true)
        ->assertScript($previewOpen, true)
        ->keys(':focus', 'Escape')->wait(0.3)
        ->assertScript($previewOpen, false)
        ->assertScript($modalOpen, true)
        ->keys(':focus', 'Escape')->wait(0.5)
        ->assertScript($modalOpen, false);
});
