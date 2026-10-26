<?php

use App\Models\Media;

beforeEach(function () {
    $this->media = Media::factory()->count(5)->sequence(fn ($s) => ['title' => 'Slide Title '.($s->index + 1), 'cover_url' => '/favicon.svg'])->create()
        ->map(fn ($media) => $media->fresh(['mediaType', 'genres']));

    $this->path = componentPage('<x-hero :slides="$slides" />', ['slides' => $this->media]);

    // Small JS probes, so each test reads like the behaviour it checks.
    $this->counter = 'document.querySelector("main section p[aria-hidden]").textContent.replace(/\s+/g, " ").trim()';
    $this->title = 'Array.from(document.querySelectorAll("main article[data-slide]")).filter((a) => a.offsetParent !== null).map((a) => a.querySelector("h1").textContent.replace(/\s+/g, " ").trim()).join("|")';
    $this->cards = 'Array.from(document.querySelectorAll("[data-card] button")).map((b) => b.textContent.trim()).join("|")';
    $this->settle = 1; // seconds: the 400ms morph plus the title stagger
});

test('a hero carousel passes the shared checks and keeps working after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript($this->counter, '01 / 05')
            ->assertScript($this->title, 'Slide Title 1')
            ->assertScript($this->cards, 'Slide Title 2|Slide Title 3|Slide Title 4');
    });
});

test('clicking a card makes it the current slide, updates the counter and shifts the cards', function () {
    $page = visit($this->path)->wait(1);

    $page->click('[aria-label="Show Slide Title 3"]')
        ->wait($this->settle)
        ->assertScript($this->title, 'Slide Title 3')
        ->assertScript($this->counter, '03 / 05')
        ->assertScript($this->cards, 'Slide Title 4|Slide Title 5|Slide Title 1')
        ->assertScript('document.querySelector("[style*=\"--progress\"]").style.getPropertyValue("--progress")', '0.6');
});

test('next, previous and the arrow keys walk the slides and wrap around', function () {
    $page = visit($this->path)->wait(1);

    $page->keys('[aria-label="Next slide"]', 'Enter')->wait($this->settle)
        ->assertScript($this->counter, '02 / 05')
        ->keys('[aria-label="Next slide"]', 'ArrowRight')->wait($this->settle)
        ->assertScript($this->counter, '03 / 05')
        ->keys('[aria-label="Previous slide"]', 'ArrowLeft')->wait($this->settle)
        ->assertScript($this->counter, '02 / 05')
        ->keys('[aria-label="Previous slide"]', 'Space')->wait($this->settle)
        ->keys('[aria-label="Previous slide"]', 'Enter')->wait($this->settle)
        ->assertScript($this->counter, '05 / 05')
        ->assertScript($this->title, 'Slide Title 5')
        ->keys('[aria-label="Next slide"]', 'Enter')->wait($this->settle)
        ->assertScript($this->counter, '01 / 05');
});

test('a card is a button reachable and activated from the keyboard, and focus is handed on when it leaves', function () {
    $page = visit($this->path)->wait(1);

    $page->keys('[aria-label="Show Slide Title 2"]', 'Enter')->wait($this->settle)
        ->assertScript($this->title, 'Slide Title 2')
        ->assertScript('document.activeElement.closest("[data-card]") !== null || document.activeElement.hasAttribute("data-hero-next")', true);
});

test('a change runs as a view transition, naming the cover that morphs, and cleans up after', function () {
    $page = visit($this->path)->wait(1);
    $page->script('(() => { window.__vt = 0; const start = document.startViewTransition.bind(document); document.startViewTransition = (cb) => { window.__vt++; window.__flag = document.documentElement.hasAttribute("data-hero-transition"); return start(cb); }; })()');

    $page->click('[aria-label="Show Slide Title 2"]')->wait($this->settle)
        ->assertScript('window.__vt', 1)
        ->assertScript('window.__flag', true)
        ->assertScript('document.documentElement.hasAttribute("data-hero-transition")', false)
        ->assertScript('document.querySelectorAll("[style*=\"view-transition-name\"]").length', 0)
        ->assertScript($this->title, 'Slide Title 2');
});

test('the new title rises word by word out of its mask, and the cards glide one place', function () {
    $page = visit($this->path)->wait(1);

    // Sample on the first frame the new slide is on screen: its last word (80ms stagger) and the sliding cards are mid-flight.
    $page->assertScript('(async () => {
        document.querySelector(\'[aria-label="Show Slide Title 2"]\').click();
        const article = document.querySelector(\'article[data-slide="1"]\');
        for (let frame = 0; frame < 60 && article.offsetParent === null; frame++) {
            await new Promise(requestAnimationFrame);
        }
        const words = Array.from(article.querySelectorAll("[data-word]"));
        const lastWordStillLow = getComputedStyle(words[words.length - 1]).transform !== "none";
        const aCardIsMoving = Array.from(document.querySelectorAll("[data-card]")).some((card) => getComputedStyle(card).transform !== "none");

        return lastWordStillLow && aCardIsMoving;
    })()', true);

    $page->wait($this->settle)
        ->assertScript('Array.from(document.querySelectorAll("[data-word], [data-card]")).every((el) => el.style.transform === "" && el.style.opacity === "")', true);
});

test('without the view transitions api the backdrop crossfades with motion instead', function () {
    $page = visit($this->path)->wait(1);
    $page->script('(() => { delete Document.prototype.startViewTransition; })()');

    $page->assertScript('(async () => {
        document.querySelector("[aria-label=\"Next slide\"]").click();
        await new Promise(requestAnimationFrame);
        await new Promise(requestAnimationFrame);
        return parseFloat(getComputedStyle(document.querySelector("[data-backdrop=\"1\"]")).opacity) < 1;
    })()', true);

    $page->wait($this->settle)
        ->assertScript($this->counter, '02 / 05')
        ->assertScript('getComputedStyle(document.querySelector("[data-backdrop=\"1\"]")).opacity', '1');
});

test('reduced motion swaps slides at once: no view transition, no stagger, no inline motion styles', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->script('(() => { window.__vt = 0; if (document.startViewTransition) { const start = document.startViewTransition.bind(document); document.startViewTransition = (cb) => { window.__vt++; return start(cb); }; } })()');

        $page->click('[aria-label="Show Slide Title 3"]')
            ->assertScript($this->counter, '03 / 05')
            ->assertScript($this->title, 'Slide Title 3')
            ->assertScript('window.__vt', 0)
            ->assertScript('document.documentElement.hasAttribute("data-hero-transition")', false)
            ->assertScript('document.querySelectorAll("main section [style*=\"transition\"], main section [style*=\"transform\"], main section [style*=\"opacity\"], main section [style*=\"view-transition-name\"]").length', 0)
            ->click('[aria-label="Previous slide"]')
            ->assertScript($this->counter, '02 / 05');
    }, ['reducedMotion' => 'reduce']);
});

test('on a desktop the cards sit beside the text over a full-bleed backdrop', function () {
    visit($this->path, ['viewport' => ['width' => 1280, 'height' => 800]])->wait(1)
        ->assertScript('(() => {
            const section = document.querySelector("main section").getBoundingClientRect();
            const backdrop = document.querySelector("[data-backdrop=\"0\"]").getBoundingClientRect();
            const text = document.querySelector("article[data-slide=\"0\"]").getBoundingClientRect();
            const card = document.querySelector("[data-card]").getBoundingClientRect();

            return Math.abs(backdrop.width - section.width) < 1 && Math.abs(backdrop.height - section.height) < 1 && card.left >= text.right;
        })()', true);
});

test('on a phone the text and backdrop stack, the cards scroll-snap under the text and every control is 44px', function () {
    visit($this->path, ['viewport' => ['width' => 375, 'height' => 812]])->wait(1)
        ->assertScript('(() => {
            const text = document.querySelector("article[data-slide=\"0\"]").getBoundingClientRect();
            const list = document.querySelector("ul[aria-label=\"Up next\"]");
            const style = getComputedStyle(list);

            return list.getBoundingClientRect().top >= text.bottom && style.overflowX === "auto" && style.scrollSnapType.startsWith("x");
        })()', true)
        ->assertScript('Array.from(document.querySelectorAll("main section button")).filter((b) => b.offsetParent !== null).every((b) => { const r = b.getBoundingClientRect(); return r.width >= 44 && r.height >= 44; })', true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoAccessibilityIssues();
});
