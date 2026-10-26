<?php

use App\Models\Media;

beforeEach(function () {
    $this->media = Media::factory()->count(2)->create(['cover_url' => '/favicon.svg'])->each->refresh();
    $this->media[1]->update(['title' => 'Embalming: The Another Tale of Frankenstein and the Long Night at the Villa Diodati']);
    $this->path = componentPage('<div class="flex gap-4">@foreach ($media as $item)<x-cover-tile :media="$item" :progress="40" data-test="tile-{{ $loop->index }}" wire:key="m-{{ $item->id }}" />@endforeach</div>', ['media' => $this->media]);
});

test('tiles pass the shared acceptance checks and take keyboard focus with a visible ring', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $second = '[href="'.route('stories.show', $this->media[1]->slug).'"]';

        $page->keys('@tile-0', 'Tab')
            ->assertScript("document.activeElement.matches('{$second}')", true)
            ->assertScript('getComputedStyle(document.activeElement).boxShadow !== "none"', true);
    });
});

test('hover scales the cover over the fast token', function () {
    visit($this->path)
        ->assertScript('getComputedStyle(document.querySelector("img")).transitionDuration', '0.15s')
        ->hover('@tile-0')
        ->wait(0.4)
        ->assertScript('getComputedStyle(document.querySelector("img")).scale', '1.05');
});

test('reduced motion removes the hover animation', function () {
    assertComponentAcceptance($this->path, options: ['reducedMotion' => 'reduce']);

    visit($this->path, ['reducedMotion' => 'reduce'])
        ->hover('@tile-0')
        ->wait(0.3)
        ->assertScript('getComputedStyle(document.querySelector("img")).transitionDuration', '0s')
        ->assertScript('getComputedStyle(document.querySelector("img")).scale', 'none');
});

test('the tile is at least 44px tall and the progress bar is exposed', function () {
    visit($this->path)
        ->assertScript('document.querySelector("a[href*=stories]").getBoundingClientRect().height >= 44', true)
        ->assertScript('document.querySelectorAll("[role=progressbar][aria-valuenow=\"40\"]").length', 2);
});

test('a long title is cut at two lines, so tiles in a row stay the same height, and the link keeps the whole title', function () {
    visit($this->path)->wait(1)
        ->assertScript('(() => { const title = document.querySelector("[data-test=tile-1] > span.line-clamp-2"); const style = getComputedStyle(title); return style.webkitLineClamp === "2" && title.scrollHeight > title.clientHeight; })()', true)
        ->assertScript('document.querySelector("[data-test=tile-1]").textContent.includes("the Long Night at the Villa Diodati")', true)
        ->assertScript('Math.abs(document.querySelector("[data-test=tile-0]").getBoundingClientRect().height - document.querySelector("[data-test=tile-1]").getBoundingClientRect().height) < 1', true);
});
