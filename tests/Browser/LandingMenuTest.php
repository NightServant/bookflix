<?php

/*
 * The landing page's phone menu: a drawer below 768px that holds the in-page links and both ways in. It traps focus while open, closes on Escape, on a
 * link and on the backdrop, never leaves the page locked or inert, and lands a section below the fixed header.
 */

beforeEach(function () {
    popularStories(12);

    $this->phone = ['viewport' => ['width' => 375, 'height' => 667]];
    $this->landing = route('landing', absolute: false);
    $this->button = '[data-test="landing-menu-button"]';

    $this->visible = fn (string $selector): string => '(() => { const el = document.querySelector('.json_encode($selector).'); return !! el && getComputedStyle(el).display !== "none" && getComputedStyle(el).visibility !== "hidden" && el.getBoundingClientRect().width > 0; })()';
    $this->activeText = 'document.activeElement.textContent.trim().replace(/\s+/g, " ")';
});

test('the phone menu opens, keeps focus inside, and closes on Escape', function () {
    $page = visit($this->landing, $this->phone)->wait(1);

    $page->assertScript($this->visible->__invoke($this->button), true)
        ->assertScript('document.querySelector(\'[data-test="landing-menu-button"]\').getAttribute("aria-expanded")', 'false')
        ->assertScript('document.querySelector(\'[data-test="landing-menu-button"]\').getAttribute("aria-controls")', 'landing-menu')
        ->assertScript($this->visible->__invoke('#landing-menu'), false)
        ->click($this->button)->wait(0.6)
        ->assertScript($this->visible->__invoke('#landing-menu'), true)
        ->assertScript('document.querySelector(\'[data-test="landing-menu-button"]\').getAttribute("aria-expanded")', 'true')
        ->assertScript($this->activeText, 'Top 10');

    // Tab through the items (Why Bookflix, FAQ, Sign in, Get started); one more wraps to the first control, the close button: focus never leaves the drawer.
    foreach (['Why Bookflix', 'FAQ', 'Sign in', 'Get started'] as $label) {
        $page->keys(':focus', 'Tab')->assertScript($this->activeText, $label);
    }

    $page->keys(':focus', 'Tab')
        ->assertScript('document.activeElement.dataset.test', 'landing-menu-close')
        ->assertScript('document.getElementById("landing-menu").contains(document.activeElement)', true)
        ->keys(':focus', 'Shift+Tab')
        ->assertScript($this->activeText, 'Get started')
        ->keys(':focus', 'Escape')->wait(0.6)
        ->assertScript($this->visible->__invoke('#landing-menu'), false)
        ->assertScript('document.activeElement.dataset.test', 'landing-menu-button')
        ->assertScript('document.querySelector(\'[data-test="landing-menu-button"]\').getAttribute("aria-expanded")', 'false');
});

test('a menu link closes the drawer, unlocks scrolling and lands its section below the header', function () {
    $page = visit($this->landing, $this->phone)->wait(1);

    $page->click($this->button)->wait(0.6)
        ->click('#landing-menu a[href="#faq"]')->wait(1.5)
        ->assertScript($this->visible->__invoke('#landing-menu'), false)
        ->assertScript('getComputedStyle(document.documentElement).overflow !== "hidden" && getComputedStyle(document.body).overflow !== "hidden"', true)
        ->assertScript('document.querySelectorAll("[inert], [aria-hidden=true][data-landing-inert]").length', 0)
        ->assertScript('Array.from(document.body.children).some((el) => el.hasAttribute("inert") || el.getAttribute("aria-hidden") === "true" && el.tagName === "MAIN")', false)
        ->assertScript('window.scrollY > 0', true)
        ->assertScript('document.getElementById("faq").getBoundingClientRect().top >= document.querySelector("header").getBoundingClientRect().height - 1', true);
});

test('the menu button and Get started sit fully inside the window at phone widths', function (int $width, int $height) {
    $page = visit($this->landing, ['viewport' => ['width' => $width, 'height' => $height]])->wait(1);

    foreach (['[data-test="landing-menu-button"]', 'header a[href$="/register"]'] as $selector) {
        $page->assertScript('(() => { const box = document.querySelector('.json_encode($selector).').getBoundingClientRect(); return box.width > 0 && box.left >= 0 && box.right <= window.innerWidth; })()', true);
    }

    // Sign in is not in the bar below 640px: it is in the menu.
    $page->assertScript($this->visible->__invoke('header a[href$="/login"]'), false);
})->with([[320, 640], [375, 667], [414, 896]]);

test('the page cannot scroll behind the open drawer', function () {
    $page = visit($this->landing, $this->phone)->wait(1);

    $page->assertScript('getComputedStyle(document.documentElement).overflow !== "hidden" && getComputedStyle(document.body).overflow !== "hidden"', true)
        ->click($this->button)->wait(0.6)
        ->assertScript('getComputedStyle(document.documentElement).overflow === "hidden" || getComputedStyle(document.body).overflow === "hidden"', true);
});

test('the backdrop closes the drawer', function () {
    $page = visit($this->landing, $this->phone)->wait(1);

    $page->click($this->button)->wait(0.6)
        ->assertScript($this->visible->__invoke('#landing-menu'), true)
        ->assertScript('(() => { const hit = document.elementFromPoint(20, 300); hit.click(); return hit.dataset.test; })()', 'landing-menu-backdrop')
        ->wait(0.6)
        ->assertScript($this->visible->__invoke('#landing-menu'), false)
        ->assertScript('document.activeElement.dataset.test', 'landing-menu-button');
});

test('there is no menu button from 768px up, and the in-page links show instead', function () {
    $page = visit($this->landing, ['viewport' => ['width' => 768, 'height' => 1024]])->wait(1);

    $page->assertScript($this->visible->__invoke($this->button), false)
        ->assertScript($this->visible->__invoke('header nav a[href="#faq"]'), true);
});
