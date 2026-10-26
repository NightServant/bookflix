<?php

beforeEach(function () {
    $this->path = componentPage(<<<'BLADE'
        <div x-data="{ clicks: 0 }" class="flex flex-wrap gap-4">
            <x-cta href="/_test/elsewhere" data-test="link">Start reading</x-cta>
            <x-cta variant="secondary" data-test="button" @click="clicks++">Browse the catalog</x-cta>
            <p data-test="clicks" x-text="clicks">0</p>
        </div>
        BLADE);
});

test('a cta link and button pass the shared acceptance checks and take Enter and Space', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->keys('@button', 'Enter')
            ->assertSeeIn('@clicks', '1')
            ->keys('@button', 'Space')
            ->assertSeeIn('@clicks', '2')
            ->keys('@button', 'Shift+Tab')
            ->assertScript('document.activeElement.getAttribute("data-test")', 'link')
            ->assertScript('/[1-9]\\d*px/.test(getComputedStyle(document.activeElement).boxShadow)', true);
    });
});

test('the cta is red, 24px, padded 22px by 8px, flat and at least 44px tall', function () {
    visit($this->path)
        ->assertScript('(() => { const s = getComputedStyle(document.querySelector("[data-test=link]")); return [s.fontSize, s.paddingLeft, s.paddingTop, s.backgroundColor, s.minHeight].join("|"); })()', '24px|22px|8px|rgb(229, 9, 20)|44px')
        ->assertScript('document.querySelector("[data-test=link]").getBoundingClientRect().height >= 44', true)
        ->assertScript('/[1-9]\\d*px/.test(getComputedStyle(document.querySelector("[data-test=link]")).boxShadow)', false)
        ->assertScript('getComputedStyle(document.querySelector("[data-test=button]")).backgroundColor !== "rgb(229, 9, 20)"', true);
});
