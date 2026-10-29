<?php

beforeEach(function () {
    $this->path = componentPage(<<<'BLADE'
        <x-accordion title="Why is this classified here?" name="faq" data-test="first"><p>Because of its subject.</p></x-accordion>
        <x-accordion title="Where do covers come from?" name="faq" data-test="second"><p>From the providers.</p></x-accordion>
        BLADE);

    $this->first = 'document.querySelector("[data-test=first]")';
    $this->second = 'document.querySelector("[data-test=second]")';
    $this->summary = fn (string $which) => "[data-test={$which}] summary";
    $this->panelHeight = fn (string $which) => "document.querySelector('[data-test={$which}] [x-collapse]').getBoundingClientRect().height";
});

test('the accordion follows the APG pattern: Enter and Space toggle, groups are exclusive, before and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript("{$this->first}.open", false)
            ->keys(($this->summary)('first'), 'Enter')
            ->wait(0.5)
            ->assertScript("{$this->first}.open", true)
            ->assertScript(($this->panelHeight)('first').' > 0', true)
            ->keys(($this->summary)('second'), 'Space')
            ->wait(0.5)
            ->assertScript("{$this->second}.open", true)
            ->assertScript("{$this->first}.open", false)
            ->keys(($this->summary)('second'), 'Enter')
            ->wait(0.6)
            ->assertScript("{$this->second}.open", false);
    });
});

test('closing plays the collapse before the details closes, and opening grows the panel', function () {
    $page = visit($this->path)->wait(1);
    $page->click(($this->summary)('first'))->wait(0.5)->assertScript(($this->panelHeight)('first').' > 0', true);

    $page->assertScript("(async () => { document.querySelector('[data-test=first] summary').click(); await new Promise(requestAnimationFrame); return document.querySelector('[data-test=first]').open; })()", true)
        ->wait(0.6)
        ->assertScript("{$this->first}.open", false);
});

test('the summary row is a 44px target with a visible focus ring', function () {
    visit($this->path)->wait(1)
        ->assertScript('document.querySelector("[data-test=first] summary").getBoundingClientRect().height >= 44', true)
        ->keys(($this->summary)('first'), 'Tab')
        ->assertScript('document.activeElement.closest("[data-test=second]") !== null', true)
        ->assertScript('/[1-9]\d*px/.test(getComputedStyle(document.activeElement).boxShadow)', true);
});

test('reduced motion opens and closes without transitions', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->keys(($this->summary)('first'), 'Enter')
            ->assertScript(($this->panelHeight)('first').' > 0', true)
            ->assertScript('getComputedStyle(document.querySelector("[data-test=first] [x-collapse]")).transitionProperty', 'none')
            ->keys(($this->summary)('first'), 'Enter')
            ->wait(0.3)
            ->assertScript("{$this->first}.open", false);
    }, ['reducedMotion' => 'reduce']);
});
