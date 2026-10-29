<?php

use App\Models\Creator;
use App\Models\Genre;
use App\Models\Media;

beforeEach(function () {
    $media = Media::factory()->count(2)->novel()->sequence(
        ['title' => 'Dracula', 'slug' => 'dracula', 'publication_year' => 1897],
        ['title' => 'Pride and Prejudice', 'slug' => 'pride-and-prejudice', 'publication_year' => 1813],
    )->create([
        'cover_url' => '/favicon.svg',
        'description' => str_repeat('A solicitor travels to Transylvania to meet a count and learns that the castle keeps its own hours. ', 6),
    ]);

    $media[0]->creators()->attach(Creator::factory()->create(['name' => 'Bram Stoker']), ['role' => 'author', 'position' => 1]);
    $media[1]->creators()->attach(Creator::factory()->create(['name' => 'Jane Austen']), ['role' => 'author', 'position' => 1]);
    $media->each(fn ($item) => $item->genres()->attach(
        Genre::factory()->count(3)->sequence(['name' => 'Gothic', 'slug' => 'gothic-'.$item->id], ['name' => 'Horror', 'slug' => 'horror-'.$item->id], ['name' => 'Classic', 'slug' => 'classic-'.$item->id])->create()
    ));

    $rows = Media::with(['mediaType', 'publicPrimaryDewey.deweyClass', 'creators', 'genres'])->orderBy('id')->get();

    $this->path = componentPage('<div class="grid gap-3">@foreach ($rows as $row)<x-result-row :media="$row" data-test="row-{{ $loop->index }}" wire:key="r-{{ $row->id }}" />@endforeach</div>', ['rows' => $rows]);

    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];
    $this->desktop = ['viewport' => ['width' => 1280, 'height' => 800]];
    $this->row = fn (int $index) => "document.querySelector('[data-test=row-{$index}]')";
});

test('result rows pass the shared checks and show cover, title, creators, facts, blurb and chips, before and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript(($this->row)(0).'.querySelector("h3 a").textContent.trim()', 'Dracula')
            ->assertScript(($this->row)(0).'.querySelector("h3 a").getAttribute("href")', route('stories.show', 'dracula'))
            ->assertScript(($this->row)(0).'.querySelector("[data-creators]").textContent.trim()', 'Bram Stoker')
            ->assertScript(($this->row)(0).'.querySelector("ul").textContent.replace(/\s+/g, " ").trim().startsWith("Novel")', true)
            ->assertScript(($this->row)(0).'.querySelectorAll("ul[aria-label=Genres] li").length', 3)
            ->assertScript(($this->row)(0).'.querySelector("img").getAttribute("src")', '/favicon.svg')
            ->assertScript(($this->row)(1).'.querySelector("h3 a").textContent.trim()', 'Pride and Prejudice');
    });
});

test('the cover is a small 2:3 block and the blurb really stops at two lines', function () {
    $page = visit($this->path, $this->desktop)->wait(1);

    $page->assertScript('(() => { const box = '.($this->row)(0).'.querySelector("a[aria-hidden]").getBoundingClientRect(); return Math.abs(box.width / box.height - 2 / 3) < 0.01 && box.width <= 96; })()', true)
        ->assertScript('(() => { const p = '.($this->row)(0).'.querySelector("p.line-clamp-2"); const line = parseFloat(getComputedStyle(p).lineHeight); return p.scrollHeight > p.clientHeight && p.clientHeight <= line * 2 + 1; })()', true);
});

test('the keyboard reaches one link per row, the title, and it wears the focus ring', function () {
    $page = visit($this->path)->wait(1);

    $page->keys(':root', 'Tab')
        ->assertScript('document.activeElement.textContent.trim()', 'Dracula')
        ->assertScript('/[1-9]\d*px/.test(getComputedStyle(document.activeElement).boxShadow)', true)
        ->assertScript('document.activeElement.getBoundingClientRect().height >= 44', true)
        ->keys(':focus', 'Tab')
        ->assertScript('document.activeElement.textContent.trim()', 'Pride and Prejudice')
        ->keys(':focus', 'Tab')
        ->assertScript('document.activeElement.textContent.trim()', 'Go elsewhere');
});

test('the row fits a phone without sideways scrolling and the chips wrap', function () {
    $page = visit($this->path, $this->phone)->wait(1);

    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript('Array.from('.($this->row)(0).'.querySelectorAll("ul[aria-label=Genres] li")).every((chip) => chip.getBoundingClientRect().right <= '.($this->row)(0).'.getBoundingClientRect().right)', true)
        ->assertNoAccessibilityIssues();
});

test('the chips are the only fully rounded part and sit on the input surface', function () {
    $page = visit($this->path)->wait(1);

    $page->assertScript('parseFloat(getComputedStyle('.($this->row)(0).'.querySelector("ul[aria-label=Genres] li")).borderRadius) > 100', true)
        ->assertScript('getComputedStyle('.($this->row)(0).'.querySelector("ul[aria-label=Genres] li")).backgroundColor', 'rgb(45, 45, 45)')
        ->assertScript('getComputedStyle('.($this->row)(0).').borderRadius', '8px');
});

test('result rows work the same with reduced motion', function () {
    assertComponentAcceptance($this->path, options: ['reducedMotion' => 'reduce']);
});
