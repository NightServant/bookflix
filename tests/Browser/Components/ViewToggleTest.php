<?php

use Livewire\Livewire;
use Tests\Fixtures\Livewire\ViewToggleFixture;

beforeEach(function () {
    Livewire::component('view-toggle-fixture', ViewToggleFixture::class);

    $this->path = componentPage('<livewire:view-toggle-fixture />');

    $this->checked = 'Array.from(document.querySelectorAll("[role=radio]")).filter((r) => r.getAttribute("aria-checked") === "true").map((r) => r.textContent.trim()).join("|")';
    $this->focused = 'document.activeElement.textContent.trim()';
    $this->model = 'document.querySelector("[data-test=view]").textContent';
    $this->param = 'new URLSearchParams(location.search).get("view")';
    $this->tabStops = 'Array.from(document.querySelectorAll("[role=radio]")).map((r) => r.tabIndex).join(",")';
});

test('the toggle passes the shared checks and follows the radio group pattern, before and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript('document.querySelector("[role=radiogroup]").getAttribute("aria-label")', 'Layout')
            ->assertScript('document.querySelectorAll("[role=radio]").length', 2)
            ->assertScript('Array.from(document.querySelectorAll("[role=radio]")).map((r) => r.textContent.trim()).join("|")', 'Grid|List')
            ->assertScript($this->checked, 'Grid')
            ->assertScript($this->tabStops, '0,-1')
            ->click('List')->wait(0.6)
            ->assertScript($this->checked, 'List')
            ->assertScript($this->tabStops, '-1,0')
            ->click('Grid')->wait(0.6)
            ->assertScript($this->checked, 'Grid');
    });
});

test('arrow keys move focus and select, wrapping around, and only the checked radio is in the tab order', function () {
    $page = visit($this->path)->wait(1);

    $page->keys(':root', 'Tab')
        ->assertScript($this->focused, 'Grid')
        ->keys(':focus', 'ArrowRight')->wait(0.5)
        ->assertScript($this->focused, 'List')
        ->assertScript($this->checked, 'List')
        ->keys(':focus', 'ArrowRight')->wait(0.5)
        ->assertScript($this->focused, 'Grid')
        ->assertScript($this->checked, 'Grid')
        ->keys(':focus', 'ArrowLeft')->wait(0.5)
        ->assertScript($this->focused, 'List')
        ->assertScript($this->checked, 'List')
        ->keys(':focus', 'ArrowDown')->wait(0.5)
        ->assertScript($this->checked, 'Grid')
        ->keys(':focus', 'ArrowUp')->wait(0.5)
        ->assertScript($this->checked, 'List')
        ->keys(':focus', 'Tab')
        ->assertScript($this->focused, 'Set list');
});

test('the choice is reflected in the url and in the livewire parent', function () {
    $page = visit($this->path)->wait(1);

    $page->assertScript($this->model, 'grid')
        ->click('List')->wait(0.8)
        ->assertScript($this->model, 'list')
        ->assertScript($this->param, 'list')
        ->click('Grid')->wait(0.8)
        ->assertScript($this->model, 'grid')
        ->assertScript('[null, "grid"].includes('.$this->param.')', true);
});

test('a url that already holds the choice opens on it', function () {
    $page = visit($this->path.'?view=list')->wait(1);

    $page->assertScript($this->checked, 'List')
        ->assertScript($this->model, 'list')
        ->assertScript($this->tabStops, '-1,0')
        ->keys(':root', 'Tab')
        ->assertScript($this->focused, 'List');
});

test('a value set by livewire moves the selection', function () {
    $page = visit($this->path)->wait(1);

    $page->click('Set list')->wait(0.8)
        ->assertScript($this->checked, 'List')
        ->assertScript($this->param, 'list');
});

test('options are 44px targets, the choice is clear without color alone, and the focus ring shows on the keyboard', function () {
    $page = visit($this->path)->wait(1);

    $page->assertScript('Array.from(document.querySelectorAll("[role=radio]")).every((r) => { const box = r.getBoundingClientRect(); return box.height >= 44 && box.width >= 44; })', true)
        ->assertScript('(() => { const [grid, list] = document.querySelectorAll("[role=radio]"); return getComputedStyle(grid).backgroundColor !== getComputedStyle(list).backgroundColor; })()', true)
        ->keys(':root', 'Tab')
        ->assertScript('/[1-9]\d*px/.test(getComputedStyle(document.activeElement).boxShadow)', true)
        ->assertNoAccessibilityIssues();
});

test('the toggle works the same with reduced motion', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->click('List')->wait(0.6)
            ->assertScript($this->checked, 'List')
            ->assertScript($this->param, 'list')
            ->click('Grid')->wait(0.6)
            ->assertScript($this->checked, 'Grid');
    }, ['reducedMotion' => 'reduce']);
});
