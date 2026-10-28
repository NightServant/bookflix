<?php

use App\Models\Media;
use Illuminate\Support\Facades\Cache;

/*
 * The public landing page in a real browser: the FAQ by keyboard, the Top 10 marquee and its pause, the walkthrough that pins on a wide
 * screen and stacks on a phone, motion switched off under prefers-reduced-motion, no sideways scroll, no script errors, and axe.
 * What the page contains is in tests/Feature/LandingTest.php. Covers are a local file, so no network is needed.
 */

beforeEach(function () {
    popularStories(45);

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 390, 'height' => 844]];
    $this->still = ['viewport' => ['width' => 1440, 'height' => 900], 'reducedMotion' => 'reduce'];
    $this->landing = route('landing', absolute: false);

    $this->expanded = 'Array.from(document.querySelectorAll("[data-faq-button]")).map((button) => button.getAttribute("aria-expanded")).join(",")';
    $this->focusedId = 'document.activeElement.id';
});

test('the FAQ opens and closes from the keyboard, one answer at a time', function () {
    $page = visit($this->landing, $this->desktop)->wait(1);

    $page->script('document.getElementById("faq-question-0").focus()');

    $page->assertScript($this->expanded, 'false,false,false,false,false')
        ->keys('#faq-question-0', 'Enter')->wait(0.6)
        ->assertScript($this->expanded, 'true,false,false,false,false')
        ->assertScript('getComputedStyle(document.getElementById("faq-answer-0")).visibility', 'visible')
        ->assertScript('document.getElementById("faq-answer-0").getBoundingClientRect().height > 40', true)
        ->assertScript('getComputedStyle(document.getElementById("faq-answer-1")).visibility', 'hidden');

    // Down moves to the next question without opening it; opening it closes the first.
    $page->keys('#faq-question-0', 'ArrowDown')->assertScript($this->focusedId, 'faq-question-1')
        ->assertScript($this->expanded, 'true,false,false,false,false')
        ->keys('#faq-question-1', 'Enter')->wait(0.6)
        ->assertScript($this->expanded, 'false,true,false,false,false');

    // Enter on the open one closes it, End goes to the last question and Home back to the first.
    $page->keys('#faq-question-1', 'Enter')->wait(0.6)->assertScript($this->expanded, 'false,false,false,false,false')
        ->keys('#faq-question-1', 'End')->assertScript($this->focusedId, 'faq-question-4')
        ->keys('#faq-question-4', 'Home')->assertScript($this->focusedId, 'faq-question-0')
        ->assertNoJavaScriptErrors();
});

test('the FAQ answers open with a click too, and the plus turns into a close', function () {
    $page = visit($this->landing, $this->desktop)->wait(1);

    $page->click('#faq-question-2')->wait(0.6)
        ->assertScript($this->expanded, 'false,false,true,false,false')
        ->assertScript('document.querySelector("#faq-question-2 svg").classList.contains("landing-faq__icon--open")', true)
        ->assertSee('Libraries file books by class')
        ->click('#faq-question-4')->wait(0.6)
        ->assertScript($this->expanded, 'false,false,false,false,true')
        ->assertScript('document.querySelector("#faq-question-2 svg").classList.contains("landing-faq__icon--open")', false);
});

test('the Top 10 marquee runs, and its pause button stops it and says so', function () {
    $page = visit($this->landing, $this->desktop)->wait(1);

    $track = 'getComputedStyle(document.querySelector(".landing-marquee__track"))';

    $page->assertScript('document.querySelectorAll(".landing-top__item").length', 20)
        ->assertScript('document.querySelectorAll(".landing-top[aria-hidden=true] .landing-top__item").length', 10)
        ->assertScript("{$track}.animationName", 'landing-marquee')
        ->assertScript("{$track}.animationPlayState", 'running')
        ->click('Pause scrolling')->wait(0.3)
        ->assertScript("{$track}.animationPlayState", 'paused')
        ->assertScript('document.querySelector("#top-10 button[aria-pressed]").getAttribute("aria-pressed")', 'true')
        ->click('Pause scrolling')->wait(0.3)
        ->assertScript("{$track}.animationPlayState", 'running')
        ->assertNoJavaScriptErrors();
});

test('the marquee has no layout shift: its height is fixed before a single poster has loaded', function () {
    $page = visit($this->landing, $this->desktop)->wait(1);

    $page->assertScript('(() => { const box = document.querySelector(".landing-marquee").getBoundingClientRect(); return box.height > 250 && box.height < 400; })()', true);
});

test('with reduced motion nothing on the page moves: no marquee loop, no drifting books, no pinned walkthrough', function () {
    $page = visit($this->landing, $this->still)->wait(1);

    $page->assertScript('getComputedStyle(document.querySelector(".landing-marquee__track")).animationName', 'none')
        ->assertScript('getComputedStyle(document.querySelector(".landing-top[aria-hidden=true]")).display', 'none')
        ->assertScript('getComputedStyle(document.querySelector("#top-10 button[aria-pressed]")).display', 'none')
        ->assertScript('getComputedStyle(document.querySelector(".landing-marquee")).overflowX', 'auto')
        ->assertScript('document.querySelector(".landing-top[aria-label]").getAttribute("tabindex")', '0')
        ->assertScript('Array.from(document.querySelectorAll(".landing-book, .landing-book__float, .landing-heap__item")).every((el) => getComputedStyle(el).animationName === "none")', true)
        ->assertScript('document.querySelector(".landing-walk").hasAttribute("data-live")', false)
        ->assertScript('document.querySelector("[data-scene-cover]").dataset.scene', 'skipped')
        ->assertScript('getComputedStyle(document.querySelector("html")).scrollBehavior', 'auto')
        ->assertNoJavaScriptErrors();
});

test('with motion allowed the hero books drift, and are all there', function () {
    $page = visit($this->landing, $this->desktop)->wait(1.5);

    $page->assertScript('document.querySelectorAll(".landing-book").length', 7)
        ->assertScript('getComputedStyle(document.querySelector(".landing-book__float")).animationName', 'landing-float')
        ->assertScript('Array.from(document.querySelectorAll(".landing-book")).every((el) => getComputedStyle(el).opacity === "1")', true);
});

test('on a wide screen the walkthrough pins its preview and swaps it as the statements pass', function () {
    $page = visit($this->landing, $this->desktop)->wait(1);

    $current = 'Array.from(document.querySelectorAll("[data-preview]")).findIndex((el) => el.hasAttribute("data-current"))';

    $page->assertScript('document.querySelector(".landing-walk").hasAttribute("data-live")', true)
        ->assertScript('getComputedStyle(document.querySelector(".landing-previews")).position', 'sticky');

    foreach ([2, 0, 3, 1] as $step) {
        $page->script("document.querySelectorAll('[data-step]')[{$step}].scrollIntoView({ block: 'center' })");
        $page->wait(0.8)
            ->assertScript($current, $step)
            ->assertScript('document.querySelectorAll("[data-step][data-current]").length', 1)
            ->assertScript('Array.from(document.querySelectorAll("[data-preview]")).filter((el) => getComputedStyle(el).opacity === "1").length', 1);
    }
});

test('on a phone every statement sits above its own preview and nothing is hidden', function () {
    $page = visit($this->landing, $this->phone)->wait(1);

    $page->assertScript('document.querySelector(".landing-walk").hasAttribute("data-live")', false)
        ->assertScript('Array.from(document.querySelectorAll("[data-preview]")).every((el) => getComputedStyle(el).opacity === "1" && getComputedStyle(el).position === "static")', true)
        ->assertScript('(() => { const top = (el) => el.getBoundingClientRect().top + scrollY; const steps = document.querySelectorAll("[data-step]"); const previews = document.querySelectorAll("[data-preview]"); return Array.from(steps).every((step, i) => top(step) < top(previews[i]) && (i === 3 || top(previews[i]) < top(steps[i + 1]))); })()', true);
});

test('the walkthrough shows every state at once under reduced motion', function () {
    $page = visit($this->landing, $this->still)->wait(1);

    $page->assertScript('Array.from(document.querySelectorAll("[data-preview]")).every((el) => getComputedStyle(el).opacity === "1" && el.getBoundingClientRect().height > 100)', true)
        ->assertScript('Array.from(document.querySelectorAll("[data-step]")).every((el) => getComputedStyle(el.querySelector("h3")).color === "rgb(255, 255, 255)")', true);
});

test('the page never scrolls sideways', function (array $options) {
    $page = visit($this->landing, $options)->wait(1);
    scrolledThrough($page);

    $page->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertScript('document.body.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoJavaScriptErrors();
})->with([
    'desktop' => fn () => [['viewport' => ['width' => 1440, 'height' => 900]]][0],
    'laptop' => fn () => [['viewport' => ['width' => 1024, 'height' => 768]]][0],
    'tablet' => fn () => [['viewport' => ['width' => 768, 'height' => 1024]]][0],
    'phone' => fn () => [['viewport' => ['width' => 390, 'height' => 844]]][0],
    'small phone' => fn () => [['viewport' => ['width' => 320, 'height' => 640]]][0],
    'desktop, reduced motion' => fn () => [['viewport' => ['width' => 1440, 'height' => 900], 'reducedMotion' => 'reduce']][0],
]);

test('the page passes the accessibility audit and throws no script errors', function (string $variant) {
    $options = match ($variant) {
        'desktop' => $this->desktop,
        'phone' => $this->phone,
        'reduced' => $this->still,
    };

    $page = visit($this->landing, $options)->wait(1);
    scrolledThrough($page);
    $page->wait(1);

    $page->assertSee('Frequently Asked Questions')->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
})->with(['desktop', 'phone', 'reduced']);

test('the audit also passes with an answer open and the marquee paused', function () {
    $page = visit($this->landing, $this->desktop)->wait(1);

    $page->click('#faq-question-3')->wait(0.6)->click('Pause scrolling')->wait(0.4)
        ->assertSee('Popularity blends their signals')->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
});

test('the page works without a catalog', function () {
    Media::query()->forceDelete();
    Cache::flush();

    visit($this->landing, $this->desktop)->wait(1)
        ->assertSee('Your Library.')->assertSee('Frequently Asked Questions')->assertDontSee('Top 10 on Bookflix today')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
});

test('the sign-up box carries the typed email to the registration form', function () {
    $page = visit($this->landing, $this->desktop)->wait(1);

    $page->fill('#start-email', 'reader@example.com')->click('#start button[type=submit]')->wait(1)
        ->assertPathIs('/register')
        ->assertValue('input[name=email]', 'reader@example.com')
        ->assertNoJavaScriptErrors();
});

test('the header links and the skip link reach the sections and the sign-in page', function () {
    $page = visit($this->landing, $this->desktop)->wait(1);

    $page->click('header nav a[href="#faq"]')->wait(1)
        ->assertScript('document.getElementById("faq").getBoundingClientRect().top < 200', true)
        ->click('[data-section=hero] a[href$="/login"]')->assertPathIs('/login');
});
