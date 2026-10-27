<?php

beforeEach(function () {
    $this->path = componentPage(<<<'BLADE'
        <x-page-header data-test="header" title="Discover" description="Browse the whole catalog by format, genre and subject. Pick a shelf to start, or search for something specific." />
        BLADE);

    $this->style = fn (string $selector) => 'getComputedStyle(document.querySelector("'.$selector.'"))';
});

test('the title is on the page-title scale, tight and balanced, and the description is quieter body text under it', function () {
    foreach ([['width' => 390, 'height' => 844, 'title' => '24px'], ['width' => 1440, 'height' => 900, 'title' => '40px']] as $viewport) {
        $page = visit($this->path, ['viewport' => ['width' => $viewport['width'], 'height' => $viewport['height']]])->wait(1);

        $page->assertScript(($this->style)('h1').'.fontSize', $viewport['title'])
            ->assertScript(($this->style)('h1').'.textWrap', 'balance')
            ->assertScript('parseFloat('.($this->style)('h1').'.letterSpacing) < 0', true)
            ->assertScript(($this->style)('header p').'.fontSize', '16px')
            ->assertScript(($this->style)('header p').'.color', 'rgb(179, 179, 179)')
            ->assertScript('document.querySelector("header p").getBoundingClientRect().top > document.querySelector("h1").getBoundingClientRect().bottom', true)
            ->assertScript('document.querySelector("header p").getBoundingClientRect().width <= 680', true);
    }
});
