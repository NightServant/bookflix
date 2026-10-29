<?php

use Livewire\Livewire;
use Tests\Fixtures\Livewire\RangeFixture;

beforeEach(function () {
    Livewire::component('range-fixture', RangeFixture::class);

    $this->path = componentPage('<livewire:range-fixture />');

    $this->lower = '.noUi-handle-lower';
    $this->upper = '.noUi-handle-upper';
    $this->now = fn (string $handle) => "document.querySelector('{$handle}').getAttribute('aria-valuenow')";
    $this->years = 'document.querySelector("[data-test=years]").textContent';
    $this->handleCount = 'document.querySelectorAll(".noUi-handle").length';
});

test('a range filter has two labelled slider handles with values, before and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript($this->handleCount, 2)
            ->assertScript('document.querySelector(".noUi-handle-lower").getAttribute("role")', 'slider')
            ->assertScript('document.querySelector(".noUi-handle-lower").getAttribute("aria-label")', 'Publication year, minimum')
            ->assertScript('document.querySelector(".noUi-handle-upper").getAttribute("aria-label")', 'Publication year, maximum')
            ->assertScript(($this->now)($this->lower), '1950')
            ->assertScript(($this->now)($this->upper), '2000')
            ->assertScript('document.querySelector(".noUi-handle-lower").getAttribute("aria-valuemin")', '1900')
            ->assertScript('document.querySelector(".noUi-handle-lower").getAttribute("aria-valuemax")', '2000')
            ->assertScript('document.querySelector(".noUi-handle-upper").getAttribute("aria-valuemin")', '1950')
            ->assertScript('document.querySelector(".noUi-handle-upper").getAttribute("aria-valuemax")', '2025')
            ->assertScript('document.querySelector("output").textContent.trim()', '1950 – 2000');
    });
});

test('the arrow, page and end keys move a handle and the values reach the livewire property', function () {
    $page = visit($this->path)->wait(1);

    $page->keys($this->lower, 'ArrowRight')->wait(1)
        ->assertScript(($this->now)($this->lower), '1951')
        ->assertScript($this->years, '1951-2000')
        ->keys($this->upper, ['ArrowLeft', 'ArrowLeft'])->wait(1)
        ->assertScript($this->years, '1951-1998')
        ->keys($this->lower, 'End')->wait(1)
        ->assertScript(($this->now)($this->lower), '1998')
        ->keys($this->upper, 'Home')->wait(1)
        ->assertScript(($this->now)($this->upper), '1998')
        ->assertScript($this->years, '1998-1998');
});

test('the slider survives a livewire re-render without doubling its handles', function () {
    $page = visit($this->path)->wait(1);

    $page->click('Refresh')->wait(1)
        ->assertScript($this->handleCount, 2)
        ->keys($this->lower, 'ArrowRight')->wait(1)
        ->assertScript($this->years, '1951-2000')
        ->click('Refresh')->wait(1)
        ->assertScript($this->handleCount, 2)
        ->assertScript(($this->now)($this->lower), '1951');
});

test('a value set by livewire moves the handles', function () {
    $page = visit($this->path)->wait(1);

    $page->click('Narrow')->wait(1)
        ->assertScript(($this->now)($this->lower), '1960')
        ->assertScript(($this->now)($this->upper), '1990')
        ->assertScript('document.querySelector("output").textContent.trim()', '1960 – 1990');
});

test('handles are 44px targets drawn as round thumbs with a focus ring', function () {
    $page = visit($this->path)->wait(1);

    $page->assertScript('(() => { const r = document.querySelector(".noUi-handle-lower").getBoundingClientRect(); return r.width >= 44 && r.height >= 44; })()', true)
        ->assertScript('getComputedStyle(document.querySelector(".noUi-handle-lower"), "::before").borderRadius.startsWith("9999") || parseFloat(getComputedStyle(document.querySelector(".noUi-handle-lower"), "::before").borderRadius) > 100', true)
        ->keys($this->lower, 'Tab')
        ->keys($this->lower, 'ArrowRight')
        ->assertScript('/[1-9]\d*px/.test(getComputedStyle(document.querySelector(".noUi-handle-lower"), "::before").boxShadow)', true)
        ->assertScript('getComputedStyle(document.querySelector(".noUi-connect")).backgroundColor', 'rgb(255, 255, 255)');
});

test('a range filter works the same with reduced motion', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->keys($this->lower, 'ArrowRight')->wait(1)
            ->assertScript($this->years, '1951-2000')
            ->assertScript('document.querySelectorAll(".noUi-state-tap").length', 0)
            ->assertScript($this->handleCount, 2);
    }, ['reducedMotion' => 'reduce']);
});
