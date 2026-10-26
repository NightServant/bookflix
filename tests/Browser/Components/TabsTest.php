<?php

beforeEach(function () {
    $this->path = componentPage(<<<'BLADE'
        <x-tabs :tabs="['about' => 'About', 'editions' => 'Editions', 'reviews' => 'Reviews']" label="Story details">
            <x-slot:about><p>About panel</p></x-slot:about>
            <x-slot:editions><p>Editions panel</p></x-slot:editions>
            <x-slot:reviews><p>Reviews panel</p></x-slot:reviews>
        </x-tabs>
        BLADE);

    $this->selected = 'Array.from(document.querySelectorAll("[role=tab]")).filter((t) => t.getAttribute("aria-selected") === "true").map((t) => t.textContent.trim()).join("|")';
    $this->visiblePanels = 'Array.from(document.querySelectorAll("[role=tabpanel]")).filter((p) => p.offsetParent !== null).map((p) => p.textContent.trim()).join("|")';
    $this->focused = 'document.activeElement.textContent.trim()';
});

test('tabs follow the APG pattern: arrows move and select, Home and End jump, only the selected tab is in the tab order, before and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript($this->selected, 'About')
            ->assertScript($this->visiblePanels, 'About panel')
            ->assertScript('Array.from(document.querySelectorAll("[role=tab]")).map((t) => t.tabIndex).join(",")', '0,-1,-1')
            ->keys('[role=tab][aria-selected=true]', 'ArrowRight')
            ->assertScript($this->selected, 'Editions')
            ->assertScript($this->focused, 'Editions')
            ->assertScript($this->visiblePanels, 'Editions panel')
            ->keys('[role=tab][aria-selected=true]', 'End')
            ->assertScript($this->focused, 'Reviews')
            ->assertScript($this->selected, 'Reviews')
            ->keys('[role=tab][aria-selected=true]', 'ArrowRight')
            ->assertScript($this->selected, 'About')
            ->keys('[role=tab][aria-selected=true]', 'ArrowLeft')
            ->assertScript($this->selected, 'Reviews')
            ->keys('[role=tab][aria-selected=true]', 'Home')
            ->assertScript($this->selected, 'About');
    });
});

test('tab from the selected tab moves into its panel', function () {
    $page = visit($this->path)->wait(1);

    $page->keys('[role=tab][aria-selected=true]', 'Tab')
        ->assertScript('document.activeElement.getAttribute("role")', 'tabpanel')
        ->assertScript('/[1-9]\d*px/.test(getComputedStyle(document.activeElement).boxShadow)', true);
});

test('clicking a tab selects it and shows only its panel', function () {
    $page = visit($this->path)->wait(1);

    $page->click('Reviews')
        ->assertScript($this->selected, 'Reviews')
        ->assertScript($this->visiblePanels, 'Reviews panel')
        ->assertScript('document.querySelector("[role=tab][aria-selected=true]").getBoundingClientRect().height >= 44', true);
});

test('tabs work the same with reduced motion', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->keys('[role=tab][aria-selected=true]', 'ArrowRight')
            ->assertScript($this->selected, 'Editions')
            ->assertScript($this->visiblePanels, 'Editions panel');
    }, ['reducedMotion' => 'reduce']);
});
