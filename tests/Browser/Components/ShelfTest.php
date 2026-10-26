<?php

use App\Models\Media;

beforeEach(function () {
    $media = Media::factory()->count(14)->create(['cover_url' => '/favicon.svg'])->each->refresh();

    $this->path = componentPage(<<<'BLADE'
        <x-shelf title="Staff Picks" href="/_test/elsewhere" eyebrow="Picked by our editors">
            @foreach ($media as $item)
                <li wire:key="p-{{ $item->id }}"><x-cover-tile :media="$item" /></li>
            @endforeach
        </x-shelf>
        <x-shelf title="Trending Now" featured class="mt-8">
            @foreach ($media->take(3) as $item)
                <li wire:key="t-{{ $item->id }}"><x-cover-tile :media="$item" /></li>
            @endforeach
        </x-shelf>
        <x-shelf title="New Releases" bleed class="mt-8">
            @foreach ($media as $item)
                <li wire:key="n-{{ $item->id }}"><x-cover-tile :media="$item" /></li>
            @endforeach
        </x-shelf>
        BLADE, ['media' => $media]);

    $this->prev = '[aria-label="Previous in Staff Picks"]';
    $this->next = '[aria-label="Next in Staff Picks"]';
    $this->track = 'document.querySelector("section ul")';
});

test('a shelf scrolls with its arrows by keyboard, before and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript("document.querySelector('{$this->prev}').disabled", true)
            ->assertScript("document.querySelector('{$this->next}').disabled", false)
            ->keys($this->next, 'Enter')
            ->wait(1)
            ->assertScript("{$this->track}.scrollLeft > 100", true)
            ->assertScript("document.querySelector('{$this->prev}').disabled", false);
    });
});

test('an arrow disables at its end and keyboard focus moves to the other arrow instead of falling off', function () {
    $page = visit($this->path)->wait(1);
    $page->script("(() => { document.querySelector('{$this->next}').focus(); {$this->track}.scrollLeft = {$this->track}.scrollWidth; })()");
    $page->wait(0.5)
        ->assertScript("document.querySelector('{$this->next}').disabled", true)
        ->assertScript('document.activeElement.getAttribute("aria-label")', 'Previous in Staff Picks');
});

test('arrows are hidden when a shelf has nothing to scroll', function () {
    visit($this->path)->wait(1)
        ->assertScript('getComputedStyle(document.querySelector("[aria-label=\"Next in Trending Now\"]").parentElement).display', 'none')
        ->assertScript("getComputedStyle(document.querySelector('{$this->next}').parentElement).display", 'flex');
});

test('tiles reveal on load, and only the featured shelf wears the gradient', function () {
    visit($this->path)->wait(1)
        ->assertScript('getComputedStyle(document.querySelector("section ul li")).opacity', '1')
        ->assertScript('getComputedStyle(document.querySelectorAll("section")[1]).backgroundImage.includes("linear-gradient")', true)
        ->assertScript('getComputedStyle(document.querySelectorAll("section")[0]).backgroundImage', 'none');
});

test('arrows are 44px targets, and a phone swipes instead: no arrows, no horizontal page scroll', function () {
    visit($this->path, ['viewport' => ['width' => 800, 'height' => 812]])->wait(1)
        ->assertScript("(() => { const r = document.querySelector('{$this->next}').getBoundingClientRect(); return r.width >= 44 && r.height >= 44; })()", true);

    visit($this->path, ['viewport' => ['width' => 375, 'height' => 812]])->wait(1)
        ->assertScript("document.querySelector('{$this->next}').offsetParent === null", true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoAccessibilityIssues();
});

test('reduced motion shows tiles at once and scrolls without easing', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript('document.querySelector("section ul li").style.opacity', '')
            ->script('(() => { window.__behavior = null; const scrollBy = Element.prototype.scrollBy; Element.prototype.scrollBy = function (o) { window.__behavior = o.behavior; return scrollBy.call(this, o); }; })()');
        $page->keys($this->next, 'Enter')
            ->assertScript('window.__behavior', 'auto');
    }, ['reducedMotion' => 'reduce']);
});

test('with motion allowed the arrows scroll smoothly', function () {
    $page = visit($this->path)->wait(1);
    $page->script('(() => { window.__behavior = null; const scrollBy = Element.prototype.scrollBy; Element.prototype.scrollBy = function (o) { window.__behavior = o.behavior; return scrollBy.call(this, o); }; })()');
    $page->keys($this->next, 'Enter')->assertScript('window.__behavior', 'smooth');
});

test('a full-bleed track fades on both screen edges at rest, and the fade reaches into the outermost tiles', function () {
    $bleed = "document.querySelectorAll('section ul')[2]";
    $fade = fn (string $side) => "getComputedStyle({$bleed}).getPropertyValue('--fade-{$side}')";

    foreach ([['width' => 1440, 'height' => 800, 'fade' => '256px', 'gutter' => 148], ['width' => 375, 'height' => 812, 'fade' => '67.5px', 'gutter' => 16]] as $viewport) {
        visit($this->path, ['viewport' => ['width' => $viewport['width'], 'height' => $viewport['height']]])->wait(1)
            ->assertScript($fade('start'), $viewport['fade'])
            ->assertScript($fade('end'), $viewport['fade'])
            ->assertScript("getComputedStyle({$bleed}).maskImage.startsWith('linear-gradient')", true)
            // The first tile starts on the gutter, inside the left fade: its outer part dissolves at rest.
            ->assertScript("(() => { const inset = {$bleed}.firstElementChild.getBoundingClientRect().left - {$bleed}.getBoundingClientRect().left; return Math.abs(inset - {$viewport['gutter']}) < 1 && inset < parseFloat({$fade('start')}); })()", true)
            // The tile running off the right edge sits inside the right fade.
            ->assertScript("(() => { const box = {$bleed}.getBoundingClientRect(); const edge = Array.from({$bleed}.children).find((tile) => tile.getBoundingClientRect().right > box.right); return edge.getBoundingClientRect().left < box.right && edge.getBoundingClientRect().right > box.right - parseFloat({$fade('end')}); })()", true)
            ->assertScript("getComputedStyle(document.querySelector('{$this->next}')).maskImage", 'none');
    }
});

test('a boxed track fades only on its right edge, and the arrows stay opaque', function () {
    $fade = fn (string $side) => "getComputedStyle({$this->track}).getPropertyValue('--fade-{$side}')";

    visit($this->path, ['viewport' => ['width' => 1280, 'height' => 800]])->wait(1)
        ->assertScript($fade('start'), '0px')
        ->assertScript($fade('end'), '32px')
        ->assertScript("getComputedStyle({$this->track}).maskImage.startsWith('linear-gradient')", true)
        ->assertScript("getComputedStyle(document.querySelector('{$this->next}')).maskImage", 'none');
});
