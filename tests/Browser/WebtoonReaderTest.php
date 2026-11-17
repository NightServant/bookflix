<?php

use App\Models\ContentSource;
use App\Models\Media;
use App\Models\ReadingProgress;
use App\Models\Series;
use App\Models\User;

/*
 * The webtoon reader in a real browser: one centered strip of lazy images whose signed addresses are asked for in batches of 50 as the reader nears
 * them, the page most in view saved as the place and resumed, the thin progress line and the "Page n of N" pill, the strip width setting, the end of
 * the strip (the next episode, or a way back), and a file withdrawn in the middle of reading.
 */

beforeEach(function () {
    $this->reader = User::factory()->create();
    $this->source = comicSource('webcomic', 60, [40, 60]);
    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];
    $this->actingAs($this->reader);
    $this->read = fn () => route('reader.show', $this->source->media->slug);
    $this->scroller = 'document.querySelector("[data-reader=webtoon-scroller]")';
    // Scrolls so that the top of page `n` sits right under the top bar.
    $this->scrollTo = fn (int $n) => $this->scroller.'.scrollTop = document.querySelector("[data-reader=webtoon-strip] > [data-page=\"'.$n.'\"]").offsetTop - 64';
    $this->withSrc = 'document.querySelectorAll("[data-reader=webtoon-strip] img[src]").length';
});

test('it opens on the strip: the chip, the pill, the progress line, and no bottom bar', function () {
    visit(($this->read)(), $this->desktop)
        ->waitForText('Page 1 of 60')->wait(0.5)
        ->assertSeeIn('[data-reader=direction-label]', 'Vertical scroll')
        ->assertSeeIn('[data-reader=page-pill]', 'Page 1 of 60')
        ->assertMissing('[data-reader=bottom-bar]')
        ->assertScript('Math.round(document.querySelector("[data-reader=webtoon-progress]").getBoundingClientRect().height)', 3)
        ->assertScript('document.querySelector("[data-reader=webtoon-progress]").getBoundingClientRect().top', 64)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the strip is centered and 720 wide on a desktop, and as wide as the screen on a phone', function () {
    visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 60')->wait(0.5)
        ->assertScript('Math.round(document.querySelector("[data-reader=webtoon-strip]").getBoundingClientRect().width)', 720)
        ->assertScript('Math.round(document.querySelector("[data-reader=webtoon-strip]").getBoundingClientRect().left)', 360);

    visit(($this->read)(), $this->phone)->waitForText('Page 1 of 60')->wait(0.5)
        ->assertScript('Math.round(document.querySelector("[data-reader=webtoon-strip]").getBoundingClientRect().width)', 375)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript('document.querySelector("[data-reader=settings-button]").offsetParent !== null');
});

test('Strip width: Full width takes the whole window and Standard goes back', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 60')->wait(0.5);

    $page->click('[data-reader=settings-button]')->wait(0.3)
        ->assertVisible('[data-reader-option="fit:wide"]')
        ->click('[data-reader-option="fit:wide"]')->wait(0.4)
        ->assertScript('Math.round(document.querySelector("[data-reader=webtoon-strip]").getBoundingClientRect().width) >= 1420')
        ->click('[data-reader-option="fit:standard"]')->wait(0.4)
        ->assertScript('Math.round(document.querySelector("[data-reader=webtoon-strip]").getBoundingClientRect().width)', 720)
        ->assertNoJavaScriptErrors();
});

test('every page is an image that says which page it is, lazy, and only the first batch of 50 has an address', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 60')->wait(0.8);

    $page->assertScript('document.querySelectorAll("[data-reader=webtoon-strip] img").length', 60)
        ->assertScript('[...document.querySelectorAll("[data-reader=webtoon-strip] img")].every((i) => i.alt === "Page " + (i.closest("[data-page]").dataset.page) && i.loading === "lazy")')
        ->assertScript($this->withSrc, 50)
        // The browser itself holds the images back until they are near: far fewer have loaded.
        ->assertScript('[...document.querySelectorAll("[data-reader=webtoon-strip] img")].filter((i) => i.complete && i.naturalWidth > 0).length < 20')
        ->assertNoJavaScriptErrors();
});

test('scrolling toward the end asks for the next batch of addresses', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 60')->wait(0.6);

    $page->script(($this->scrollTo)(30));
    $page->wait(0.8)->assertScript($this->withSrc, 50);
    $page->script(($this->scrollTo)(44));
    $page->wait(1.2)->assertScript($this->withSrc, 60)
        ->assertNoJavaScriptErrors();
});

test('the page most in view is shown, saved as the place, and a reload resumes on it', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 60')->wait(0.6);

    expect(ReadingProgress::count())->toBe(0);

    $page->script(($this->scrollTo)(30));
    $page->wait(3.2)
        ->assertSeeIn('[data-reader=page-pill]', 'Page 30 of 60')
        ->assertScript('Math.round(document.querySelector("[data-reader=webtoon-progress]").style.width.replace("%", ""))', 50);

    $saved = ReadingProgress::sole();

    expect($saved->content_source_id)->toBe($this->source->id)
        ->and($saved->locator)->toBe(['page' => 30])
        ->and((float) $saved->percent)->toBe(50.0);

    $page->script('location.reload()');
    $page->wait(1.8)
        ->assertSeeIn('[data-reader=page-pill]', 'Page 30 of 60')
        ->assertScript('Math.abs(document.querySelector("[data-reader=webtoon-strip] > [data-page=\"30\"]").getBoundingClientRect().top - 64) < 4')
        ->assertNoJavaScriptErrors();

    expect(ReadingProgress::sole()->locator)->toBe(['page' => 30]);
});

test('the end of the strip offers the next episode of the series', function () {
    $series = Series::factory()->create();
    $next = Media::factory()->ofType('webcomic')->create(['title' => 'Harbour Lights 13']);
    $series->media()->attach([$this->source->media_id => ['position' => 1], $next->id => ['position' => 2]]);
    ContentSource::factory()->for($next)->publicDomain()->verified()->cbz(2)->create();

    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 60')->wait(0.6);

    $page->assertScript('document.querySelector("[data-reader=next-episode]").offsetParent === null');
    $page->script($this->scroller.'.scrollTop = '.$this->scroller.'.scrollHeight');
    $page->wait(1)
        ->assertSeeIn('[data-reader=page-pill]', 'Page 60 of 60')
        ->assertSeeIn('[data-reader=next-episode]', 'Next episode')
        ->assertAttribute('[data-reader=next-episode]', 'href', route('reader.show', $next->slug))
        ->assertNoJavaScriptErrors();
});

test('without a next episode the end of the strip says Back to the story', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 60')->wait(0.6);

    $page->script($this->scroller.'.scrollTop = '.$this->scroller.'.scrollHeight');
    $page->wait(1)
        ->assertSeeIn('[data-reader=strip-back]', 'Back to the story')
        ->assertAttribute('[data-reader=strip-back]', 'href', route('stories.show', $this->source->media->slug))
        ->assertNoJavaScriptErrors();
});

test('PageDown scrolls the strip by most of a screen', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 60')->wait(0.6);

    $page->keys(':root', 'PageDown')->wait(0.8)
        ->assertScript($this->scroller.'.scrollTop > 600');
});

test('a file withdrawn in the middle of reading shows the withdrawn card when the next batch is asked for', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Page 1 of 60')->wait(0.6);

    $this->source->update(['verified_at' => null, 'verified_by' => null]);

    $page->script(($this->scrollTo)(46));
    $page->wait(1.5)
        ->assertSee('This book is no longer available to read here')
        ->assertVisible('[data-reader-state=withdrawn] a[href="'.route('stories.show', $this->source->media->slug).'"]')
        ->assertNoJavaScriptErrors();
});

test('the reader runs clean and passes the accessibility audit on a desktop and on a phone', function (string $size) {
    visit(($this->read)(), $this->{$size})->waitForText('Page 1 of 60')->wait(0.9)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
})->with(['desktop', 'phone']);
