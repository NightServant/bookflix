<?php

beforeEach(function () {
    $this->path = componentPage(<<<'BLADE'
        <div x-data="{ q: '' }">
            <x-search-panel placeholder="Titles, authors, subjects" :suggestions="['Dune', 'Dune Messiah', 'Berserk', 'Attack on Titan']" x-model="q" />
            <output data-test="q" x-text="q"></output>
        </div>
        BLADE);

    $this->input = '[role=combobox]';
    $this->model = 'document.querySelector("[data-test=q]").textContent';
    $this->expanded = 'document.querySelector("[role=combobox]").getAttribute("aria-expanded")';
    $this->suggestions = 'Array.from(document.querySelectorAll("[role=option]")).filter((o) => o.offsetParent !== null).map((o) => o.textContent.trim()).join("|")';
});

test('the search panel passes the shared checks and works after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript('document.querySelector("search label").textContent.trim()', 'What would you like to find?')
            ->assertScript('document.querySelector("[role=combobox]").getAttribute("placeholder")', 'Titles, authors, subjects')
            ->keys($this->input, ['d', 'u', 'n'])
            ->assertScript($this->model, 'dun');
    });
});

test('the typed text is the search, Enter keeps it, and the suggestions narrow as you type', function () {
    $page = visit($this->path)->wait(1);

    $page->keys($this->input, ['d', 'u', 'n'])->wait(0.3)
        ->assertScript($this->expanded, 'true')
        ->assertScript($this->suggestions, 'Dune|Dune Messiah')
        ->keys($this->input, 'Enter')->wait(0.2)
        ->assertScript($this->model, 'dun')
        ->assertScript('document.querySelector("[role=combobox]").value', 'dun')
        ->assertScript($this->expanded, 'false');
});

test('arrow keys choose a suggestion and Enter fills the field with it', function () {
    $page = visit($this->path)->wait(1);

    $page->keys($this->input, ['d', 'u', 'n'])->wait(0.3)
        ->keys($this->input, 'ArrowDown')
        ->assertScript('document.querySelector("[role=combobox]").getAttribute("aria-activedescendant") === Array.from(document.querySelectorAll("[role=option]"))[0].id', true)
        ->keys($this->input, ['ArrowDown', 'ArrowUp', 'Enter'])->wait(0.2)
        ->assertScript($this->model, 'Dune')
        ->assertScript('document.querySelector("[role=combobox]").value', 'Dune')
        ->assertScript($this->expanded, 'false');
});

test('clicking a suggestion fills the field, and Escape closes the list without clearing the text', function () {
    $page = visit($this->path)->wait(1);

    $page->keys($this->input, ['b', 'e'])->wait(0.3)
        ->keys($this->input, 'Escape')
        ->assertScript($this->expanded, 'false')
        ->assertScript($this->model, 'be')
        ->assertScript('document.activeElement === document.querySelector("[role=combobox]")', true)
        ->keys($this->input, 'ArrowDown')->wait(0.3)
        ->click('[role=option]')
        ->assertScript($this->model, 'Berserk');
});

test('the label focuses the field and the way into Discover is a 44px secondary link', function () {
    $page = visit($this->path)->wait(1);

    $page->click('What would you like to find?')
        ->assertScript('document.activeElement === document.querySelector("[role=combobox]")', true)
        ->assertScript('document.querySelector("search a").getAttribute("href")', url('/discover'))
        ->assertScript('document.querySelector("search a").textContent.trim()', 'Browse Discover')
        ->assertScript('document.querySelector("search a").getBoundingClientRect().height >= 44', true)
        ->assertScript('getComputedStyle(document.querySelector("search a")).backgroundColor !== "rgb(229, 9, 20)"', true);
});

test('the search panel works the same with reduced motion', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->keys($this->input, ['d', 'u', 'n'])->wait(0.3)
            ->keys($this->input, ['ArrowDown', 'Enter'])->wait(0.2)
            ->assertScript($this->model, 'Dune');
    }, ['reducedMotion' => 'reduce']);
});

test('with no suggestions to show the listbox takes no room: no empty bordered box', function () {
    $height = 'document.querySelector("[role=listbox]").getBoundingClientRect().height';

    $page = visit($this->path)->wait(1);

    $page->keys($this->input, ['d', 'u', 'n'])->wait(0.3)
        ->assertScript($height.' > 0', true)
        ->keys($this->input, ['z', 'z'])->wait(0.3)
        ->assertScript($height, 0);

    // A panel that has no suggestions at all (the page's own markup replaces the one above).
    $empty = visit(componentPage('<x-search-panel placeholder="Titles" :suggestions="[]" />'))->wait(1);

    $empty->click($this->input)->wait(0.3)
        ->assertScript($height, 0)
        ->keys($this->input, ['d', 'u'])->wait(0.3)
        ->assertScript($height, 0);
});
