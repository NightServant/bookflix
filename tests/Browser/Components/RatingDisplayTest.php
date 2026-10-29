<?php

beforeEach(function () {
    $this->path = componentPage(<<<'BLADE'
        <div class="grid gap-3">
            <x-rating-display :average="4.5" :count="1234" data-test="rated" />
            <x-rating-display average="3.50" :count="1" data-test="odd" />
            <x-rating-display :average="null" :count="0" data-test="none" />
        </div>
        BLADE);

    $this->star = fn (string $test, int $n) => "document.querySelectorAll('[data-test={$test}] [data-star]')[{$n}]";
});

test('a rating passes the shared checks and reads as one sentence, before and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript('document.querySelector("[data-test=rated] .sr-only").textContent.trim()', 'Rated 4.5 out of 5 from 1,234 ratings')
            ->assertScript('document.querySelector("[data-test=odd] .sr-only").textContent.trim()', 'Rated 3.5 out of 5 from 1 rating')
            ->assertScript('document.querySelector("[data-test=none]").textContent.trim()', 'No ratings yet')
            ->assertScript('document.querySelector("[data-test=rated]").textContent.replace(/\s+/g, " ").includes("Rated 4.5 out of 5 from 1,234 ratings")', true);
    });
});

test('the stars and the numbers are hidden from assistive technology and are not in the tab order', function () {
    $page = visit($this->path)->wait(1);

    $page->assertScript('document.querySelectorAll("[data-test=rated] > [aria-hidden=true]").length', 2)
        ->assertScript('document.querySelectorAll("[data-test=rated] [data-star]").length', 5)
        ->assertScript('document.querySelectorAll("[data-test=rated] a, [data-test=rated] button, [data-test=rated] [tabindex]").length', 0);
});

test('a half star fills exactly half of its width and a full star all of it', function () {
    $page = visit($this->path)->wait(1);

    $fill = fn (string $test, int $n) => '(() => { const star = '.($this->star)($test, $n).'; const clip = star.querySelector("span"); return clip ? clip.getBoundingClientRect().width / star.getBoundingClientRect().width : 0; })()';

    $page->assertScript('Array.from(document.querySelectorAll("[data-test=rated] [data-star]")).map((star) => star.dataset.star).join("|")', 'full|full|full|full|half')
        ->assertScript($fill('rated', 3), 1)
        ->assertScript($fill('rated', 4), 0.5)
        ->assertScript($fill('odd', 4), 0)
        ->assertScript('getComputedStyle('.($this->star)('rated', 4).'.querySelector(":scope > svg")).color', 'rgb(65, 65, 65)')
        ->assertScript('getComputedStyle('.($this->star)('rated', 4).'.querySelector(":scope > span svg")).color', 'rgb(255, 255, 255)');
});

test('the stars are five 20px glyphs in a row and the numbers sit beside them', function () {
    $page = visit($this->path)->wait(1);

    $page->assertScript('(() => {
        const stars = Array.from(document.querySelectorAll("[data-test=rated] [data-star]")).map((star) => star.getBoundingClientRect());
        const number = document.querySelector("[data-test=rated] > [aria-hidden=true]:last-child").getBoundingClientRect();

        return stars.every((box, i) => box.width === 20 && (i === 0 || box.left > stars[i - 1].left && Math.abs(box.top - stars[0].top) < 1)) && number.left >= stars[4].right;
    })()', true)
        ->assertNoAccessibilityIssues();
});

test('a rating works the same with reduced motion', function () {
    assertComponentAcceptance($this->path, options: ['reducedMotion' => 'reduce']);
});
