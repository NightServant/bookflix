<?php

beforeEach(function () {
    $this->path = componentPage(<<<'BLADE'
        <x-quick-links :links="[
            ['label' => 'Continue reading', 'route' => 'home', 'icon' => 'book-open'],
            ['label' => 'New releases and the very latest arrivals across every shelf of the catalog', 'route' => 'explore.index', 'icon' => 'sparkles'],
            ['label' => 'Browse by subject', 'route' => 'landing', 'icon' => 'squares-2x2'],
            ['label' => 'My collections', 'route' => 'home', 'icon' => 'rectangle-stack'],
        ]" />
        BLADE);

    $this->labels = 'Array.from(document.querySelectorAll("nav[aria-label=\'Quick links\'] a")).map((a) => a.textContent.trim()).join("|")';
    $this->cards = 'Array.from(document.querySelectorAll("nav[aria-label=\'Quick links\'] [data-flux-card]"))';
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];
    $this->desktop = ['viewport' => ['width' => 1280, 'height' => 800]];
});

test('quick links pass the shared checks, expose their links and work after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript($this->labels, 'Continue reading|New releases and the very latest arrivals across every shelf of the catalog|Browse by subject|My collections')
            ->assertScript('document.querySelector("nav[aria-label=\'Quick links\'] a").getAttribute("href")', route('home'));
    });
});

test('every card is a 44px target, even where the labels wrap', function () {
    foreach ([$this->phone, $this->desktop] as $viewport) {
        visit($this->path, $viewport)->wait(1)
            ->assertScript($this->cards.'.every((card) => card.getBoundingClientRect().height >= 44)', true)
            ->assertScript('Array.from(document.querySelectorAll("nav[aria-label=\'Quick links\'] a")).every((a) => a.getBoundingClientRect().height >= 44)', true);
    }
});

test('cards in a row share one height, however long a label runs', function () {
    foreach ([$this->phone, $this->desktop] as $viewport) {
        visit($this->path, $viewport)->wait(1)
            ->assertScript('(() => {
                const cards = '.$this->cards.'.map((card) => card.getBoundingClientRect());
                const rows = Object.groupBy(cards, (rect) => Math.round(rect.top));

                return Object.values(rows).every((row) => row.every((rect) => Math.abs(rect.height - row[0].height) < 1)) && cards.length === 4;
            })()', true);
    }
});

test('a desktop lays the cards out in a row, a phone stacks them', function () {
    visit($this->path, $this->desktop)->wait(1)
        ->assertScript('new Set('.$this->cards.'.map((card) => Math.round(card.getBoundingClientRect().top))).size', 1);

    visit($this->path, $this->phone)->wait(1)
        ->assertScript('new Set('.$this->cards.'.map((card) => Math.round(card.getBoundingClientRect().left))).size', 1)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);
});

test('keyboard order matches the visual order and each link wears the focus ring', function () {
    foreach ([$this->phone, $this->desktop] as $viewport) {
        $page = visit($this->path, $viewport)->wait(1);

        // The tab sequence, read as the focus moves, against the links sorted into reading order (top to bottom, then left to right).
        $page->keys(':root', 'Tab')
            ->assertScript('document.activeElement.textContent.trim()', 'Continue reading')
            ->assertScript('/[1-9]\d*px/.test(getComputedStyle(document.activeElement).boxShadow)', true)
            ->keys(':focus', 'Tab')
            ->assertScript('document.activeElement.textContent.trim().startsWith("New releases")', true)
            ->keys(':focus', 'Tab')
            ->assertScript('document.activeElement.textContent.trim()', 'Browse by subject')
            ->keys(':focus', 'Tab')
            ->assertScript('document.activeElement.textContent.trim()', 'My collections')
            ->assertScript('(() => {
                const links = Array.from(document.querySelectorAll("nav[aria-label=\'Quick links\'] a"));
                const reading = [...links].sort((a, b) => Math.round(a.getBoundingClientRect().top) - Math.round(b.getBoundingClientRect().top) || a.getBoundingClientRect().left - b.getBoundingClientRect().left);

                return links.every((link, index) => link === reading[index]);
            })()', true);
    }
});

test('the cards use the kit surface and radius, not the flux defaults', function () {
    visit($this->path, $this->desktop)->wait(1)
        ->assertScript('getComputedStyle('.$this->cards.'[0]).borderRadius', '8px')
        ->assertScript('getComputedStyle('.$this->cards.'[0]).backgroundColor', 'rgb(35, 35, 35)');
});

test('quick links work the same with reduced motion', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript($this->cards.'.length', 4);
    }, ['reducedMotion' => 'reduce']);
});
