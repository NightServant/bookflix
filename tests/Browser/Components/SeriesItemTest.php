<?php

use App\Models\Media;

beforeEach(function () {
    $media = Media::factory()->count(5)->manga()->sequence(
        ['title' => 'Volume One', 'publication_year' => 1990],
        ['title' => 'Volume One and a Half', 'publication_year' => 1991],
        ['title' => 'Volume Two', 'publication_year' => 1992],
        ['title' => 'Complete Omnibus', 'publication_year' => 1995],
        ['title' => 'Unnumbered Special', 'publication_year' => null],
    )->create(['cover_url' => '/favicon.svg'])->each->refresh();

    $this->path = componentPage(<<<'BLADE'
        <ul class="max-w-xl">
            <li><x-series-item :media="$media[0]" position="1.00" data-test="v0" /></li>
            <li><x-series-item :media="$media[1]" position="1.50" data-test="v1" /></li>
            <li><x-series-item :media="$media[2]" position="2.00" data-test="v2" /></li>
            <li><x-series-item :media="$media[3]" :position="null" label="Omnibus" data-test="v3" /></li>
            <li><x-series-item :media="$media[4]" data-test="v4" /></li>
        </ul>
        BLADE, ['media' => $media]);

    $this->indexes = 'Array.from(document.querySelectorAll("[data-index]")).map((i) => i.textContent.trim()).join("|")';
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];
});

test('series items pass the shared checks and show their indexes, titles and years, before and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript($this->indexes, '[01]|[01.5]|[02]|[Omnibus]')
            ->assertScript('document.querySelectorAll("[data-index]").length', 4)
            ->assertScript('document.querySelector("[data-test=v1] a:not([aria-hidden])").textContent.trim()', 'Volume One and a Half')
            ->assertScript('document.querySelector("[data-test=v1] [data-year]").textContent.trim()', '1991')
            ->assertScript('document.querySelectorAll("[data-year]").length', 4);
    });
});

test('the index is set in tabular figures and numbered volumes line their titles up', function () {
    $page = visit($this->path)->wait(1);

    $page->assertScript('getComputedStyle(document.querySelector("[data-index]")).fontVariantNumeric.includes("tabular-nums")', true)
        ->assertScript('(() => { const left = (n) => document.querySelector(`[data-test=v${n}] a:not([aria-hidden])`).getBoundingClientRect().left; return left(0) === left(1) && left(1) === left(2); })()', true);
});

test('the order across a row is index, cover, title, year', function () {
    $page = visit($this->path)->wait(1);

    $page->assertScript('(() => {
        const row = document.querySelector("[data-test=v0]");
        const [index, cover, title, year] = [row.querySelector("[data-index]"), row.querySelector("img"), row.querySelector("a:not([aria-hidden])"), row.querySelector("[data-year]")].map((el) => el.getBoundingClientRect());

        return index.right <= cover.left && cover.right <= title.left && title.right <= year.left;
    })()', true);
});

test('the keyboard reaches only the title links, which are 44px targets with the focus ring', function () {
    $page = visit($this->path)->wait(1);

    $page->keys(':root', 'Tab')
        ->assertScript('document.activeElement.textContent.trim()', 'Volume One')
        ->assertScript('/[1-9]\d*px/.test(getComputedStyle(document.activeElement).boxShadow)', true)
        ->assertScript('document.activeElement.getBoundingClientRect().height >= 44', true)
        ->keys(':focus', 'Tab')
        ->assertScript('document.activeElement.textContent.trim()', 'Volume One and a Half')
        ->keys(':focus', ['Tab', 'Tab', 'Tab'])
        ->assertScript('document.activeElement.textContent.trim()', 'Unnumbered Special')
        ->keys(':focus', 'Tab')
        ->assertScript('document.activeElement.textContent.trim()', 'Go elsewhere');
});

test('a phone fits the list without sideways scrolling', function () {
    $page = visit($this->path, $this->phone)->wait(1);

    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoAccessibilityIssues();
});

test('series items work the same with reduced motion', function () {
    assertComponentAcceptance($this->path, options: ['reducedMotion' => 'reduce']);
});
