<?php

use Livewire\Livewire;
use Tests\Fixtures\Livewire\RatingInputFixture;

beforeEach(function () {
    Livewire::component('rating-input-fixture', RatingInputFixture::class);

    $this->path = componentPage('<livewire:rating-input-fixture />');

    $this->checked = 'Array.from(document.querySelectorAll("[role=radio]")).filter((r) => r.getAttribute("aria-checked") === "true").map((r) => r.dataset.value).join("|")';
    $this->focused = 'document.activeElement.dataset.value ?? document.activeElement.textContent.trim()';
    $this->tabStops = 'Array.from(document.querySelectorAll("[role=radio]")).filter((r) => r.tabIndex === 0).map((r) => r.dataset.value).join("|")';
    $this->model = 'document.querySelector("[data-test=model]").textContent';
    $this->saves = 'document.querySelector("[data-test=saves]").textContent';
    // How much of each star is filled, as percents: 100|100|50|0|0 is three and a half stars.
    $this->fills = 'Array.from(document.querySelectorAll("[data-test=input] [role=none]")).map((cell) => { const clip = cell.querySelector("span span span"); const star = clip.parentElement.getBoundingClientRect(); return Math.round(clip.getBoundingClientRect().width / star.width * 100); }).join("|")';
});

test('the input passes the shared checks and is a labelled radio group of ten, before and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript('document.querySelector("[role=radiogroup]").getAttribute("aria-label")', 'Your rating')
            ->assertScript('document.querySelectorAll("[role=radio]").length', 10)
            ->assertScript('Array.from(document.querySelectorAll("[role=radio]")).map((r) => r.getAttribute("aria-label")).join("|")', '1 out of 10|2 out of 10|3 out of 10|4 out of 10|5 out of 10|6 out of 10|7 out of 10|8 out of 10|9 out of 10|10 out of 10')
            ->assertScript($this->checked, '')
            ->assertScript($this->tabStops, '1');
    });
});

test('a click on the left half of a star is the half score and on the right half the whole one, saved once', function () {
    $page = visit($this->path)->wait(1);

    $page->click('[aria-label="7 out of 10"]')->wait(0.8)
        ->assertScript($this->checked, '7')
        ->assertScript($this->fills, '100|100|100|50|0')
        ->assertScript($this->model, '7')
        ->assertScript($this->saves, '1')
        ->assertScript('document.querySelector("[data-test=input]").textContent.replace(/\s+/g, " ").includes("7/10")', true)
        ->click('[aria-label="10 out of 10"]')->wait(0.8)
        ->assertScript($this->checked, '10')
        ->assertScript($this->fills, '100|100|100|100|100')
        ->assertScript($this->model, '10')
        ->assertScript($this->saves, '2');
});

test('arrow keys move focus and select, wrapping around, Home and End jump, and only one radio is in the tab order', function () {
    $page = visit($this->path)->wait(1);

    $page->keys(':root', 'Tab')
        ->assertScript($this->focused, '1')
        ->assertScript($this->checked, '')
        ->keys(':focus', 'ArrowRight')->assertScript($this->focused, '2')->assertScript($this->checked, '2')->assertScript($this->tabStops, '2')
        ->keys(':focus', 'ArrowDown')->assertScript($this->focused, '3')->assertScript($this->checked, '3')
        ->keys(':focus', 'ArrowLeft')->assertScript($this->focused, '2')
        ->keys(':focus', 'ArrowUp')->assertScript($this->focused, '1')->assertScript($this->checked, '1')
        ->keys(':focus', 'ArrowUp')->assertScript($this->focused, '10')->assertScript($this->checked, '10')
        ->keys(':focus', 'ArrowRight')->assertScript($this->focused, '1')
        ->keys(':focus', 'End')->assertScript($this->focused, '10')->assertScript($this->checked, '10')
        ->keys(':focus', 'Home')->assertScript($this->focused, '1')->assertScript($this->checked, '1')
        ->assertScript($this->fills, '50|0|0|0|0');
});

test('walking along the stars asks for one save, after a pause, and leaving the group sends it at once', function () {
    $page = visit($this->path)->wait(1);

    $page->keys(':root', 'Tab')
        ->keys(':focus', 'ArrowRight')->keys(':focus', 'ArrowRight')->keys(':focus', 'ArrowRight')->keys(':focus', 'ArrowRight')
        ->assertScript($this->checked, '5')
        ->assertScript($this->saves, '0')
        ->wait(1.5)
        ->assertScript($this->saves, '1')
        ->assertScript($this->model, '5')
        ->keys(':focus', 'ArrowRight')
        ->assertScript($this->saves, '1')
        ->keys(':focus', 'Tab')->wait(0.8)
        ->assertScript($this->saves, '2')
        ->assertScript($this->model, '6');
});

test('the rating can be cleared with Delete, Backspace or the Clear button, and the button only shows with a score', function () {
    $page = visit($this->path)->wait(1);
    $clear = 'getComputedStyle(document.querySelector("[aria-label=\"Clear your rating\"]")).display';

    $page->assertScript($clear, 'none')
        ->click('[aria-label="4 out of 10"]')->wait(0.8)
        ->assertScript($clear, 'flex')
        ->keys(':focus', 'Delete')->wait(0.8)
        ->assertScript($this->checked, '')
        ->assertScript($this->fills, '0|0|0|0|0')
        ->assertScript($this->model, '')
        ->assertScript($clear, 'none')
        ->click('[aria-label="9 out of 10"]')->wait(0.8)
        ->keys(':focus', 'Backspace')->wait(0.8)
        ->assertScript($this->checked, '')
        ->click('[aria-label="3 out of 10"]')->wait(0.8)
        ->click('[aria-label="Clear your rating"]')->wait(0.8)
        ->assertScript($this->checked, '')
        ->assertScript($this->model, '')
        ->assertScript($this->focused, '1')
        ->assertScript($this->saves, '6');
});

test('a score set by livewire moves the stars', function () {
    $page = visit($this->path)->wait(1);

    $page->click('Set eight')->wait(0.8)
        ->assertScript($this->checked, '8')
        ->assertScript($this->fills, '100|100|100|100|0')
        ->assertScript($this->tabStops, '8');
});

test('the pointer previews a score without choosing it', function () {
    $page = visit($this->path)->wait(1);

    $page->click('[aria-label="3 out of 10"]')->wait(0.8)
        ->hover('[aria-label="9 out of 10"]')
        ->assertScript($this->fills, '100|100|100|100|50')
        ->assertScript($this->checked, '3')
        ->hover('After the stars')
        ->assertScript($this->fills, '100|50|0|0|0');
});

test('a choice is announced in a polite live region', function () {
    $page = visit($this->path)->wait(1);

    $page->assertScript('document.querySelector("[role=status]").textContent.trim()', '')
        ->click('[aria-label="6 out of 10"]')->wait(0.8)
        ->assertScript('document.querySelector("[role=status]").textContent.trim()', 'Rated 6 out of 10')
        ->click('[aria-label="Clear your rating"]')->wait(0.8)
        ->assertScript('document.querySelector("[role=status]").textContent.trim()', 'Rating cleared');
});

test('the halves are 24px targets, the stars 44px tall, and the keyboard focus shows a ring on the star', function () {
    $page = visit($this->path)->wait(1);

    $page->assertScript('Array.from(document.querySelectorAll("[role=radio]")).every((r) => { const box = r.getBoundingClientRect(); return box.height >= 44 && box.width >= 24; })', true)
        ->assertScript('Array.from(document.querySelectorAll("[data-test=input] [role=none]")).every((cell) => { const box = cell.getBoundingClientRect(); return box.height >= 44 && box.width >= 48; })', true)
        ->keys(':root', 'Tab')
        ->assertScript('/[1-9]\d*px/.test(getComputedStyle(document.activeElement.parentElement).boxShadow)', true)
        ->assertNoAccessibilityIssues();
});

test('the empty and the set stars are told apart without color alone, and the clear button is a 44px target', function () {
    $page = visit($this->path)->wait(1);

    $page->click('[aria-label="5 out of 10"]')->wait(0.8)
        ->assertScript('(() => { const [fill, base] = [document.querySelector("[data-test=input] [role=none] span span span svg"), document.querySelector("[data-test=input] [role=none] span span > svg")]; return getComputedStyle(fill).color !== getComputedStyle(base).color; })()', true)
        ->assertScript('document.querySelector("[aria-label=\"Clear your rating\"]").getBoundingClientRect().height >= 44', true)
        ->assertNoAccessibilityIssues();
});

test('nothing animates, with or without reduced motion', function () {
    foreach ([[], ['reducedMotion' => 'reduce']] as $options) {
        $page = visit($this->path, $options)->wait(1);

        $page->click('[aria-label="5 out of 10"]')->wait(0.5)
            ->assertScript('Array.from(document.querySelectorAll("[data-test=input] *")).every((el) => { const style = getComputedStyle(el); return style.transitionDuration.split(",").every((d) => parseFloat(d) === 0) && style.animationName === "none"; })', true);
    }
});

test('the input works the same with reduced motion', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->click('[aria-label="2 out of 10"]')->wait(0.8)
            ->assertScript($this->checked, '2')
            ->assertScript($this->model, '2');
    }, ['reducedMotion' => 'reduce']);
});
