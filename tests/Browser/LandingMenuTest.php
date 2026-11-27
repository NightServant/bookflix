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
    // What the trap may leave behind: hidden-from-assistive-tech marks and inert, counted before opening and again after closing, so decorative marks the page
    // already carries do not matter but a leftover one does.
    $this->ready = 'document.readyState === "complete" && !! window.Livewire && document.querySelector(\'[data-test="landing-menu-button"]\').hasAttribute("aria-expanded")';
    $this->opened = 'getComputedStyle(document.getElementById("landing-menu")).display !== "none" && document.getElementById("landing-menu").contains(document.activeElement)';
    $this->closed = 'getComputedStyle(document.getElementById("landing-menu")).display === "none"';
    $this->marks = 'document.querySelectorAll("[aria-hidden=true], [inert]").length';
    $this->unlocked = 'getComputedStyle(document.documentElement).overflow !== "hidden" && getComputedStyle(document.body).overflow !== "hidden"';
    $this->activeText = 'document.activeElement.textContent.trim().replace(/\s+/g, " ")';
});

test('the phone menu opens, keeps focus inside, and closes on Escape', function () {
    $page = visit($this->landing, $this->phone);
    $page->assertScript(browserUntil($this->ready), true);
    $before = $page->script($this->marks);

    $page->assertScript($this->visible->__invoke($this->button), true)
        ->assertScript('document.querySelector(\'[data-test="landing-menu-button"]\').getAttribute("aria-expanded")', 'false')
        ->assertScript('document.querySelector(\'[data-test="landing-menu-button"]\').getAttribute("aria-controls")', 'landing-menu')
        ->assertScript($this->visible->__invoke('#landing-menu'), false)
        ->click($this->button)->assertScript(browserUntil($this->opened), true)
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
        ->keys(':focus', 'Escape')->assertScript(browserUntil($this->closed), true)
        ->assertScript($this->visible->__invoke('#landing-menu'), false)
        ->assertScript('document.activeElement.dataset.test', 'landing-menu-button')
        ->assertScript('document.querySelector(\'[data-test="landing-menu-button"]\').getAttribute("aria-expanded")', 'false')
        ->assertScript($this->marks, $before)
        ->assertScript($this->unlocked, true);
});

test('a menu link closes the drawer, unlocks scrolling and lands its section just below the header', function () {
    // Reduced motion: the layout jumps to the section at once (`motion-reduce:scroll-auto`), so where it lands is where it stays.
    $page = visit($this->landing, $this->phone + ['reducedMotion' => 'reduce']);
    $page->assertScript(browserUntil($this->ready), true);
    $before = $page->script($this->marks);

    $page->click($this->button)->assertScript(browserUntil($this->opened), true)
        ->click('#landing-menu a[href="#faq"]')->assertScript(browserUntil($this->closed.' && window.scrollY > 0'), true)
        ->assertScript($this->visible->__invoke('#landing-menu'), false)
        ->assertScript('getComputedStyle(document.documentElement).overflow !== "hidden" && getComputedStyle(document.body).overflow !== "hidden"', true)
        ->assertScript($this->marks, $before)
        ->assertScript('window.scrollY > 0', true)
        ->assertScript('Math.abs(document.getElementById("faq").getBoundingClientRect().top - document.querySelector("header").getBoundingClientRect().height) <= 2', true);
});

test('the menu button and Get started sit fully inside the window at phone widths', function (int $width, int $height) {
    $page = visit($this->landing, ['viewport' => ['width' => $width, 'height' => $height]]);
    $page->assertScript(browserUntil($this->ready), true);

    foreach (['[data-test="landing-menu-button"]', 'header a[href$="/register"]'] as $selector) {
        $page->assertScript('(() => { const box = document.querySelector('.json_encode($selector).').getBoundingClientRect(); return box.width > 0 && box.left >= 0 && box.right <= window.innerWidth; })()', true);
    }

    // Sign in is not in the bar below 640px: it is in the menu.
    $page->assertScript($this->visible->__invoke('header a[href$="/login"]'), false);
})->with([[320, 640], [375, 667], [414, 896]]);

test('the page cannot scroll behind the open drawer', function () {
    $page = visit($this->landing, $this->phone);
    $page->assertScript(browserUntil($this->ready), true);

    $page->assertScript('getComputedStyle(document.documentElement).overflow !== "hidden" && getComputedStyle(document.body).overflow !== "hidden"', true)
        ->click($this->button)->assertScript(browserUntil($this->opened), true)
        ->assertScript('getComputedStyle(document.documentElement).overflow === "hidden" || getComputedStyle(document.body).overflow === "hidden"', true);
});

test('the backdrop closes the drawer', function () {
    $page = visit($this->landing, $this->phone);
    $page->assertScript(browserUntil($this->ready), true);
    $before = $page->script($this->marks);

    $page->click($this->button)->assertScript(browserUntil($this->opened), true)
        ->assertScript($this->visible->__invoke('#landing-menu'), true)
        ->assertScript('(() => { const hit = document.elementFromPoint(20, 300); hit.click(); return hit.dataset.test; })()', 'landing-menu-backdrop')
        ->assertScript(browserUntil($this->closed), true)
        ->assertScript($this->visible->__invoke('#landing-menu'), false)
        ->assertScript('document.activeElement.dataset.test', 'landing-menu-button')
        ->assertScript($this->marks, $before)
        ->assertScript($this->unlocked, true);
});

test('growing the window past the menu breakpoint closes the drawer and lifts every lock', function () {
    $page = visit($this->landing, $this->phone);
    $page->assertScript(browserUntil($this->ready), true);
    $before = $page->script($this->marks);

    $page->click($this->button)->assertScript(browserUntil($this->opened), true)
        ->assertScript($this->visible->__invoke('#landing-menu'), true)
        ->resize(1024, 768)->assertScript(browserUntil($this->closed), true)
        ->assertScript($this->visible->__invoke('#landing-menu'), false)
        ->assertScript($this->unlocked, true)
        ->assertScript('document.documentElement.style.overflow === "" && document.body.style.overflow === ""', true)
        ->assertScript($this->marks, $before)
        ->assertScript('document.querySelector(\'[data-test="landing-menu-button"]\').getAttribute("aria-expanded")', 'false');
});

test('under reduced motion the drawer is shown at once and a link lands its section without a smooth scroll', function () {
    $page = visit($this->landing, $this->phone + ['reducedMotion' => 'reduce']);
    $page->assertScript(browserUntil($this->ready), true);

    // No waiting after the tap: the wrapper and the panel are fully shown in the very next frame.
    $page->click($this->button)
        ->assertScript('(() => { const drawer = document.getElementById("landing-menu"); const panel = drawer.querySelector(":scope > div:last-child"); return getComputedStyle(drawer).opacity === "1" && panel.getBoundingClientRect().right <= window.innerWidth + 0.5 && panel.getBoundingClientRect().left >= 0 && getComputedStyle(panel).transitionDuration === "0s"; })()', true)
        ->assertScript('getComputedStyle(document.documentElement).scrollBehavior', 'auto')
        ->click('#landing-menu a[href="#faq"]')->assertScript(browserUntil('window.scrollY > 0'), true)
        ->assertScript('window.scrollY > 0 && document.getElementById("faq").getBoundingClientRect().top >= document.querySelector("header").getBoundingClientRect().height - 1', true)
        ->assertScript($this->visible->__invoke('#landing-menu'), false);
});

test('there is no menu button from 768px up, and the in-page links show instead', function () {
    $page = visit($this->landing, ['viewport' => ['width' => 768, 'height' => 1024]]);
    $page->assertScript(browserUntil($this->ready), true);

    $page->assertScript($this->visible->__invoke($this->button), false)
        ->assertScript($this->visible->__invoke('header nav a[href="#faq"]'), true);
});
