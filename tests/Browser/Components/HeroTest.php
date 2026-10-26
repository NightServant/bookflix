<?php

use App\Models\Media;

beforeEach(function () {
    $this->media = Media::factory()->count(3)->sequence(fn ($s) => ['title' => 'Slide Title '.($s->index + 1), 'cover_url' => '/favicon.svg'])->create()
        ->map(fn ($media) => $media->fresh(['mediaType', 'publicPrimaryDewey.deweyClass']));

    $this->hero = fn (string $attributes = '') => componentPage("<x-hero :slides=\"\$slides\" eyebrow=\"Editors' picks\" {$attributes} />", ['slides' => $this->media]);

    $this->visibleTitle = 'Array.from(document.querySelectorAll("h1")).filter((h) => h.offsetParent !== null).map((h) => h.textContent.trim()).join("|")';
    $this->counter = 'document.querySelector("main section p[aria-hidden]").textContent.replace(/\s+/g, " ").trim()';
    $this->next = '[aria-label="Next slide"]';
    $this->prev = '[aria-label="Previous slide"]';
});

test('the carousel follows the APG pattern: buttons, arrow keys, wrapping, before and after wire:navigate', function () {
    assertComponentAcceptance(($this->hero)(), function ($page) {
        $page->assertScript($this->counter, '01 / 03')
            ->assertScript($this->visibleTitle, 'Slide Title 1')
            ->assertScript('document.querySelector("main section [aria-live]").getAttribute("aria-live")', 'polite')
            ->keys($this->next, 'Enter')
            ->assertScript($this->counter, '02 / 03')
            ->assertScript($this->visibleTitle, 'Slide Title 2')
            ->keys($this->next, 'ArrowRight')
            ->assertScript($this->counter, '03 / 03')
            ->keys($this->next, 'Space')
            ->assertScript($this->counter, '01 / 03')
            ->keys($this->prev, 'ArrowLeft')
            ->assertScript($this->counter, '03 / 03')
            ->assertScript($this->visibleTitle, 'Slide Title 3');
    });
});

test('the first control in tab order is the play and pause button', function () {
    visit(($this->hero)())->wait(1)
        ->assertScript('document.querySelector("main section button").getAttribute("aria-label")', 'Play slideshow');
});

test('autoplay advances, pause stops it, and rotation announces nothing while it runs', function () {
    visit(($this->hero)('autoplay :interval="500"'))->wait(1)
        ->assertScript('document.querySelector("main section [aria-live]").getAttribute("aria-live")', 'off')
        ->assertScript($this->counter.' !== "01 / 03"', true)
        ->click('[aria-label="Pause slideshow"]')
        ->assertScript('document.querySelector("main section [aria-live]").getAttribute("aria-live")', 'polite');
});

test('a paused hero stays on its slide', function () {
    $page = visit(($this->hero)('autoplay :interval="400"'))->wait(0.1);
    $page->click('[aria-label="Pause slideshow"]');
    $page->script('window.__at = '.$this->counter);
    $page->wait(1.2)->assertScript($this->counter.' === window.__at', true);
});

test('reduced motion never starts the rotation and swaps slides without a fade', function () {
    assertComponentAcceptance(($this->hero)('autoplay :interval="400"'), function ($page) {
        $page->wait(1.2)
            ->assertScript($this->counter, '01 / 03')
            ->assertScript('document.querySelector("[aria-label=\"Play slideshow\"]") !== null', true)
            ->keys($this->next, 'Enter')
            ->assertScript('getComputedStyle(document.querySelectorAll("main section article")[1]).opacity', '1')
            ->assertScript('document.querySelectorAll("main section article")[1].style.opacity', '')
            ->keys($this->prev, 'Enter');
    }, ['reducedMotion' => 'reduce']);
});

test('with motion allowed the incoming slide fades in and ends fully opaque', function () {
    $page = visit(($this->hero)())->wait(1);
    $page->assertScript('(async () => { document.querySelector("[aria-label=\"Next slide\"]").click(); await new Promise(requestAnimationFrame); await new Promise(requestAnimationFrame); return parseFloat(getComputedStyle(document.querySelectorAll("main section article")[1]).opacity) < 1; })()', true);
    $page->wait(0.8)->assertScript('getComputedStyle(document.querySelectorAll("main section article")[1]).opacity', '1');
});

test('on a phone the controls are 44px, nothing overflows sideways and axe is clean', function () {
    visit(($this->hero)(), ['viewport' => ['width' => 375, 'height' => 812]])->wait(1)
        ->assertScript('Array.from(document.querySelectorAll("main section button")).every((b) => { const r = b.getBoundingClientRect(); return r.width >= 44 && r.height >= 44; })', true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoAccessibilityIssues();
});
