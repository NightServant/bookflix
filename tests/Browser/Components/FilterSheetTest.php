<?php

beforeEach(function () {
    $this->path = componentPage(<<<'BLADE'
        <x-filter-sheet>
            <label class="flex min-h-11 items-center gap-2"><input type="checkbox" class="focus-ring size-5"> Manga</label>
            <label class="flex min-h-11 items-center gap-2"><input type="checkbox" class="focus-ring size-5"> Novels</label>
        </x-filter-sheet>
        BLADE);

    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];
    $this->trigger = '[data-flux-modal-trigger] a, [data-flux-modal-trigger] button';
    $this->isOpen = 'document.querySelector("dialog").open';
    $this->focusInside = 'document.querySelector("dialog").contains(document.activeElement)';
});

test('the filter sheet passes the shared checks on a phone and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript($this->isOpen, false)
            ->assertScript('document.querySelector("[data-flux-modal-trigger] button").getBoundingClientRect().height >= 44', true);
    }, $this->phone);
});

test('the trigger is gone at desktop width', function () {
    $page = visit($this->path, ['viewport' => ['width' => 1280, 'height' => 800]])->wait(1);

    $page->assertScript('document.querySelector("[data-flux-modal-trigger]").offsetParent', null);
});

test('opening the sheet traps focus, names the dialog, and has no accessibility issues', function () {
    $page = visit($this->path, $this->phone)->wait(1);

    $page->click('Filters')->wait(0.6)
        ->assertScript($this->isOpen, true)
        ->assertScript($this->focusInside, true)
        ->assertScript('document.querySelector("dialog").getAttribute("aria-labelledby") === document.querySelector("dialog h2").id', true)
        ->assertScript('document.querySelector("dialog h2").textContent.trim()', 'Filters')
        ->assertScript('getComputedStyle(document.querySelector("dialog")).position', 'fixed')
        ->assertScript('document.querySelector("dialog").getBoundingClientRect().right', 375)
        ->assertNoAccessibilityIssues();

    foreach (range(1, 6) as $ignored) {
        $page->keys(':focus', 'Tab')->assertScript($this->focusInside, true);
    }
});

test('the controls inside the sheet can be used from the keyboard', function () {
    $page = visit($this->path, $this->phone)->wait(1);

    $page->click('Filters')->wait(0.6)
        ->keys('dialog label:first-of-type input', 'Space')
        ->assertScript('document.querySelector("dialog input").checked', true);
});

test('Escape closes the sheet and focus returns to the trigger', function () {
    $page = visit($this->path, $this->phone)->wait(1);

    $page->click('Filters')->wait(0.6)
        ->keys('dialog [aria-label="Close filters"]', 'Escape')->wait(0.4)
        ->assertScript($this->isOpen, false)
        ->assertScript('document.activeElement.closest("[data-flux-modal-trigger]") !== null', true);
});

test('the visible 44px close button and show results both close it', function () {
    $page = visit($this->path, $this->phone)->wait(1);

    $page->click('Filters')->wait(0.6)
        ->assertScript('document.querySelector("dialog [aria-label=\"Close filters\"]").getBoundingClientRect().height >= 44', true)
        ->click('[aria-label="Close filters"]')->wait(0.4)
        ->assertScript($this->isOpen, false)
        ->click('Filters')->wait(0.6)
        ->click('Show results')->wait(0.4)
        ->assertScript($this->isOpen, false);
});

test('growing the window to desktop width closes an open sheet', function () {
    $page = visit($this->path, $this->phone)->wait(1);

    $page->click('Filters')->wait(0.6)
        ->assertScript($this->isOpen, true)
        ->resize(1280, 800)->wait(0.6)
        ->assertScript($this->isOpen, false);
});

test('the sheet opens without transitions when motion is reduced', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->click('Filters')->wait(0.3)
            ->assertScript($this->isOpen, true)
            ->assertScript('getComputedStyle(document.querySelector("dialog")).transitionProperty', 'none')
            ->assertScript('getComputedStyle(document.querySelector("dialog")).opacity', '1')
            ->click('Show results')->wait(0.2)
            ->assertScript($this->isOpen, false);
    }, [...$this->phone, 'reducedMotion' => 'reduce']);
});

test('Escape in a filter combobox closes its list first, then the sheet, with focus back on the trigger', function () {
    $path = componentPage(<<<'BLADE'
        <x-filter-sheet>
            <div x-data="{ genres: [] }">
                <x-combobox label="Genres" multiple x-model="genres" :options="['fantasy' => 'Fantasy', 'mystery' => 'Mystery', 'sci-fi' => 'Science fiction']" />
            </div>
        </x-filter-sheet>
        BLADE);

    $input = 'dialog [role=combobox]';
    $expanded = 'document.querySelector("dialog [role=combobox]").getAttribute("aria-expanded")';

    $page = visit($path, $this->phone)->wait(1);

    $page->click('Filters')->wait(0.6)
        ->keys($input, ['m', 'y'])
        ->assertScript($expanded, 'true')
        ->keys($input, 'Escape')->wait(0.3)
        ->assertScript($expanded, 'false')
        ->assertScript($this->isOpen, true)
        ->keys($input, 'Escape')->wait(0.5)
        ->assertScript($this->isOpen, false)
        ->assertScript('document.activeElement.closest("[data-flux-modal-trigger]") !== null', true);
});
