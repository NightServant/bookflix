<?php

use App\Models\ReadingProgress;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * The paged comic reader in a real browser, on a manga from Japan (right to left) built here: the keys and the side buttons turn the other way, the
 * place is saved as a page and resumed, the settings (direction, page size, two pages) work and are kept for the story on this device, a spread never
 * splits a page that is wider than tall, every image says "Page n", a withdrawn file and an unreadable archive show their cards, and the page passes
 * the accessibility audit on a desktop and on a phone.
 */

beforeEach(function () {
    $this->reader = User::factory()->create();
    $this->source = comicSource('manga', 5, [400, 600], [3 => [900, 600]]);
    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];
    $this->actingAs($this->reader);
    $this->read = fn () => route('reader.show', $this->source->media->slug);
    // The pages on screen: their numbers, left to right.
    $this->visiblePages = 'JSON.stringify([...document.querySelectorAll("[data-reader=pages-row] img")].filter((i) => !i.hidden).sort((a, b) => a.getBoundingClientRect().left - b.getBoundingClientRect().left).map((i) => Number(i.dataset.page)))';
});

test('it opens on page 1 right to left, with the left button going forward', function () {
    visit(($this->read)(), $this->desktop)
        ->waitForText('Page 1 of 5')->wait(0.4)
        ->assertSeeIn('[data-reader=direction-label]', 'Right to left')
        ->assertScript($this->visiblePages, '[1]')
        ->assertAttribute('[data-reader=pages-row] img[data-page="1"]', 'alt', 'Page 1')
        ->assertAttribute('[data-reader=next-page]', 'aria-label', 'Next page')
        ->assertScript('document.querySelector("[data-reader=next-page]").getBoundingClientRect().left < innerWidth / 2')
        ->assertScript('document.querySelector("[data-reader=prev-page]").getBoundingClientRect().left > innerWidth / 2')
        ->assertSeeIn('[data-reader=next-page] + span', 'Next')
        ->assertScript('document.querySelector("[data-reader=progress]").getAttribute("dir")', 'rtl')
        ->assertSeeIn('[data-reader=percent]', '20%')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('one page is fitted to the height of the window', function () {
    visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 5')->wait(0.4)
        ->assertScript('Math.round(document.querySelector("[data-reader=pages-stage]").clientHeight)', 748)
        ->assertScript('Math.round(document.querySelector("[data-reader=pages-row] img[data-page=\"1\"]").getBoundingClientRect().height)', 748)
        ->assertScript('Math.round(document.querySelector("[data-reader=pages-row] img[data-page=\"1\"]").getBoundingClientRect().width)', 498);
});

test('ArrowLeft goes to the next page and ArrowRight back, with the page, the percent and the bar following', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 5')->wait(0.4);

    $page->keys(':root', 'ArrowLeft')->wait(0.5)
        ->assertSeeIn('[data-reader=meta]', 'Page 2 of 5')
        ->assertSeeIn('[data-reader=percent]', '40%')
        ->assertScript($this->visiblePages, '[2]')
        ->keys(':root', 'ArrowRight')->wait(0.5)
        ->assertSeeIn('[data-reader=meta]', 'Page 1 of 5')
        ->keys(':root', 'PageDown')->wait(0.5)
        ->assertSeeIn('[data-reader=meta]', 'Page 2 of 5')
        ->keys(':root', 'PageUp')->wait(0.5)
        ->assertSeeIn('[data-reader=meta]', 'Page 1 of 5')
        ->assertNoJavaScriptErrors();
});

test('the side buttons turn the page: the left one forward, the right one back', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 5')->wait(0.4);

    $page->click('[data-reader=next-page]')->wait(0.5)
        ->assertSeeIn('[data-reader=meta]', 'Page 2 of 5')
        ->click('[data-reader=next-page]')->wait(0.5)
        ->assertSeeIn('[data-reader=meta]', 'Page 3 of 5')
        ->click('[data-reader=prev-page]')->wait(0.5)
        ->assertSeeIn('[data-reader=meta]', 'Page 2 of 5')
        ->assertNoJavaScriptErrors();
});

test('the last page is the end: the percent is 100 and there is no further page', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 5')->wait(0.4);

    for ($i = 0; $i < 6; $i++) {
        $page->keys(':root', 'ArrowLeft')->wait(0.3);
    }

    $page->wait(0.4)
        ->assertSeeIn('[data-reader=meta]', 'Page 5 of 5')
        ->assertSeeIn('[data-reader=percent]', '100%')
        ->assertNoJavaScriptErrors();
});

test('the page is saved with a page locator and a reload resumes on it', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 5')->wait(0.4);

    expect(ReadingProgress::count())->toBe(0);

    $page->keys(':root', 'ArrowLeft')->wait(0.4)->keys(':root', 'ArrowLeft')->wait(3);

    $saved = ReadingProgress::sole();

    expect($saved->content_source_id)->toBe($this->source->id)
        ->and($saved->locator)->toBe(['page' => 3])
        ->and((float) $saved->percent)->toBe(60.0);

    $page->script('location.reload()');
    $page->wait(1.5)
        ->assertSeeIn('[data-reader=meta]', 'Page 3 of 5')
        ->assertScript($this->visiblePages, '[3]')
        ->assertNoJavaScriptErrors();

    // Opening on the saved page is not a move: nothing new is written.
    expect(ReadingProgress::sole()->locator)->toBe(['page' => 3]);
});

test('Two pages is offered on a wide screen only, and shows a spread right to left', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 5')->wait(0.4);

    $page->click('[data-reader=settings-button]')->wait(0.3)
        ->assertVisible('[data-reader-option="spread:two"]')
        ->click('[data-reader-option="spread:two"]')->wait(0.8)
        ->assertScript($this->visiblePages, '[2,1]')
        ->assertSeeIn('[data-reader=meta]', 'Page 1 of 5');

    // Page 3 is wider than tall: it is shown alone, and the spread resumes after it.
    $page->keys(':root', 'Escape')->keys(':root', 'ArrowLeft')->wait(0.8)
        ->assertScript($this->visiblePages, '[3]')
        ->keys(':root', 'ArrowLeft')->wait(0.8)
        ->assertScript($this->visiblePages, '[5,4]')
        ->keys(':root', 'ArrowRight')->wait(0.8)
        ->assertScript($this->visiblePages, '[3]')
        ->assertNoJavaScriptErrors();
});

test('a wide page next to a tall one is shown alone', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 5')->wait(0.4);

    $page->click('[data-reader=settings-button]')->click('[data-reader-option="spread:two"]')->wait(0.6)
        ->keys(':root', 'Escape')->click('[data-reader=next-page]')->wait(0.8)
        ->assertScript($this->visiblePages, '[3]')
        ->assertNoJavaScriptErrors();
});

test('Two pages is not offered on a phone and a saved choice is ignored there', function () {
    visit(($this->read)(), $this->phone)->waitForText('Page 1 of 5')->wait(0.4)
        ->click('[data-reader=settings-button]')->wait(0.3)
        ->assertVisible('[data-reader-option="fit:width"]')
        ->assertScript('document.querySelector("[data-reader-option=\"spread:two\"]").offsetParent === null')
        ->assertNoJavaScriptErrors();
});

test('Fit width fills the column and the settings are kept for the story on this device', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 5')->wait(0.4);

    $page->click('[data-reader=settings-button]')->wait(0.3)
        ->click('[data-reader-option="fit:width"]')->click('[data-reader-option="direction:ltr"]')->wait(0.5)
        ->assertScript('Math.round(document.querySelector("[data-reader=pages-row] img[data-page=\"1\"]").getBoundingClientRect().width)', 1440)
        ->assertSeeIn('[data-reader=direction-label]', 'Left to right')
        ->assertScript('document.querySelector("[data-reader=progress]").getAttribute("dir")', null)
        ->assertAttribute('[data-reader=next-page]', 'aria-label', 'Next page')
        ->assertScript('document.querySelector("[data-reader=next-page]").getBoundingClientRect().left > innerWidth / 2')
        ->assertScript('JSON.parse(localStorage.getItem("bookflix.reader.comic.'.$this->source->media_id.'")).fit', 'width');

    $page->script('location.reload()');
    $page->wait(1.5)
        ->assertSeeIn('[data-reader=direction-label]', 'Left to right')
        ->assertScript('Math.round(document.querySelector("[data-reader=pages-row] img[data-page=\"1\"]").getBoundingClientRect().width)', 1440)
        ->keys(':root', 'ArrowRight')->wait(0.5)
        ->assertSeeIn('[data-reader=meta]', 'Page 2 of 5')
        ->assertNoJavaScriptErrors();
});

test('every page image says which page it is', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 5')->wait(0.6);

    $page->assertScript('[...document.querySelectorAll("[data-reader-engine] img")].length > 0 && [...document.querySelectorAll("[data-reader-engine] img")].every((i) => i.alt === "Page " + i.dataset.page)')
        ->assertScript('[...document.querySelectorAll("[data-reader-engine] img")].filter((i) => !i.hidden).every((i) => i.complete && i.naturalWidth > 0)')
        ->assertNoJavaScriptErrors();
});

test('only the pages next to the one on screen are loaded', function () {
    $this->source = comicSource('manga', 12, [40, 60]);

    visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 12')->wait(0.8)
        ->assertScript('[...document.querySelectorAll("[data-reader-engine] img")].map((i) => Number(i.dataset.page)).join(",")', '1,2,3,4,5')
        ->assertScript('document.querySelectorAll("[data-reader-engine] img[src]").length', 5);
});

test('a file withdrawn in the middle of reading shows the withdrawn card, keeping the place', function () {
    $source = comicSource('manga', 12, [40, 60]);
    $page = visit(route('reader.show', $source->media->slug), $this->desktop)->waitForText('Page 1 of 12')->wait(0.6);

    $page->keys(':root', 'ArrowLeft')->wait(3);

    $before = ReadingProgress::sole()->percent;

    $source->update(['verified_at' => null, 'verified_by' => null]);

    $page->keys(':root', 'ArrowLeft')->wait(1)
        ->assertSee('This book is no longer available to read here')
        ->assertVisible('[data-reader-state=withdrawn] a[href="'.route('stories.show', $source->media->slug).'"]')
        ->assertNoJavaScriptErrors();

    expect(ReadingProgress::sole()->percent)->toBe($before);
});

test('an archive the server can\'t read shows the couldn\'t-open card', function () {
    Storage::disk($this->source::disk())->put($this->source->storage_path, 'this is not a zip at all');

    visit(($this->read)(), $this->desktop)
        ->waitForText('This file couldn\'t be opened')
        ->assertVisible('[data-reader-state=corrupt] a[href="'.route('stories.show', $this->source->media->slug).'"]')
        ->assertVisible('[data-reader=retry]')
        ->assertNoJavaScriptErrors();
});

test('on a phone the page fills the width and the side buttons give way to a hint', function () {
    visit(($this->read)(), $this->phone)->waitForText('Page 1 of 5')->wait(0.6)
        ->assertScript('document.querySelector("[data-reader=next-page]").offsetParent === null')
        ->assertSee('Tap the left side for the next page')
        ->assertSee('right to left')
        ->assertScript('Math.round(document.querySelector("[data-reader=pages-row] img[data-page=\"1\"]").getBoundingClientRect().width)', 375)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});

test('the reader runs clean and passes the accessibility audit on a desktop and on a phone', function (string $size) {
    visit(($this->read)(), $this->{$size})->waitForText('Page 1 of 5')->wait(0.8)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
})->with(['desktop', 'phone']);
