<?php

beforeEach(function () {
    $this->path = componentPage('<x-stepper label="Setup steps" :steps="[\'Types\', \'Genres\', \'Stories\']" :current="2" action="goTo" />');
    $this->names = 'Array.from(document.querySelectorAll("nav[aria-label=\"Setup steps\"] li")).map((li) => li.textContent.replace(/\s+/g, " ").trim()).join("|")';
});

test('the stepper passes the shared acceptance checks, before and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript($this->names, 'Go back to step 1 of 3: Types 01 Types|Step 2 of 3: Genres 02 Genres|Step 3 of 3: Stories 03 Stories')
            ->assertScript('document.querySelectorAll("li[aria-current=step]").length', 1)
            ->assertScript('document.querySelector("li[aria-current=step]").textContent.includes("Genres")', true);
    });
});

test('the steps are numbered in tabular figures over their names, the current one in white and the rest muted', function () {
    visit($this->path)->wait(1)
        ->assertScript('Array.from(document.querySelectorAll("[data-stepper-number]")).map((n) => n.textContent.trim()).join("|")', '01|02|03')
        ->assertScript('getComputedStyle(document.querySelector("[data-stepper-number]")).fontVariantNumeric.includes("tabular-nums")', true)
        ->assertScript('getComputedStyle(document.querySelector("[data-stepper-number]")).textTransform', 'uppercase')
        ->assertScript('getComputedStyle(document.querySelector("li[aria-current=step] > div")).borderTopColor !== getComputedStyle(document.querySelector("li:last-child > div")).borderTopColor', true);
});

test('the step that can be gone back to is a 44px button the keyboard reaches with a visible ring, and later steps are not in the tab order', function () {
    $page = visit($this->path)->wait(1);

    $page->assertScript('document.querySelector("nav[aria-label=\"Setup steps\"] button").getBoundingClientRect().height >= 44', true)
        ->assertScript('document.querySelectorAll("nav[aria-label=\"Setup steps\"] button").length', 1)
        ->keys(':root', 'Tab')
        ->assertScript('document.activeElement.tagName', 'BUTTON')
        ->assertScript('getComputedStyle(document.activeElement).boxShadow !== "none"', true);
});

test('with reduced motion the stepper changes colour without a transition', function () {
    visit($this->path, ['reducedMotion' => 'reduce'])->wait(1)
        ->assertScript('getComputedStyle(document.querySelector("nav[aria-label=\"Setup steps\"] li > div")).transitionDuration', '0s')
        ->assertScript('document.getAnimations().length', 0);

    visit($this->path)->wait(1)
        ->assertScript('getComputedStyle(document.querySelector("nav[aria-label=\"Setup steps\"] li > div")).transitionDuration', '0.15s');
});
