<?php

use App\Models\ReadingProgress;
use App\Models\User;

/*
 * The PDF reader in a real browser (pdf.js with its worker): a PDF built here opens on page 1, turns pages by key and by the page field, saves the
 * page and resumes it, zooms from the keyboard, asks for a password card on an encrypted file, and keeps only a few canvases for a book of 1,000
 * pages. The chrome around it (bars, panels, keys) is in ReaderChromeTest.
 */

beforeEach(function () {
    $this->reader = User::factory()->create();
    $this->source = pdfSource();
    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];
    $this->actingAs($this->reader);
    $this->read = fn () => route('reader.show', $this->source->media->slug);
    // The page the canvas for `n` paints something other than white: the text of the page is on it.
    $this->painted = fn (int $n) => 'document.querySelector(`canvas[data-page="'.$n.'"]`) !== null && (() => { const c = document.querySelector(`canvas[data-page="'.$n.'"]`); const d = c.getContext("2d").getImageData(0, 0, c.width, c.height).data; for (let i = 0; i < d.length; i += 4) { if (d[i] < 128) return true; } return false; })()';
});

test('it renders page 1 on a sheet and shows where the reader is', function () {
    visit(($this->read)(), $this->desktop)
        ->waitForText('PDF · 3 pages')->wait(0.8)
        ->assertScript('document.querySelector("[data-reader=page-input]").value', '1')
        ->assertSeeIn('[data-reader=page-total]', '3')
        ->assertSeeIn('[data-reader=meta]', 'Page 1 of 3')
        ->assertSeeIn('[data-reader=percent]', '33.3%')
        ->assertSeeIn('[data-reader=toolbar] [data-reader=zoom-value]', '100%')
        ->assertScript(($this->painted)(1))
        ->assertScript('document.querySelector("canvas[data-page=\"1\"]").getAttribute("role")', 'img')
        ->assertScript('document.querySelector("canvas[data-page=\"1\"]").getAttribute("aria-label")', 'Page 1')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the arrow and page keys turn the pages', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('PDF · 3 pages')->wait(0.8);

    $page->keys(':root', 'ArrowRight')->wait(0.6)
        ->assertSeeIn('[data-reader=meta]', 'Page 2 of 3')
        ->assertScript('document.querySelector("[data-reader=page-input]").value', '2')
        ->assertScript(($this->painted)(2))
        ->keys(':root', 'PageUp')->wait(0.6)
        ->assertSeeIn('[data-reader=meta]', 'Page 1 of 3')
        ->keys(':root', 'PageDown')->wait(0.6)
        ->assertSeeIn('[data-reader=meta]', 'Page 2 of 3')
        ->keys(':root', 'ArrowLeft')->wait(0.6)
        ->assertSeeIn('[data-reader=meta]', 'Page 1 of 3')
        ->assertNoJavaScriptErrors();
});

test('the page field jumps on Enter and puts back what it can\'t use', function () {
    $source = pdfSource(tinyPdf(5));
    $page = visit(route('reader.show', $source->media->slug), $this->desktop)->waitForText('PDF · 5 pages')->wait(0.8);

    $page->click('[data-reader=page-input]')->type('[data-reader=page-input]', '4')->keys('[data-reader=page-input]', 'Enter')->wait(0.6)
        ->assertSeeIn('[data-reader=meta]', 'Page 4 of 5')
        ->assertScript('document.querySelector("[data-reader=page-input]").value', '4')
        ->type('[data-reader=page-input]', '99')->keys('[data-reader=page-input]', 'Enter')->wait(0.4)
        ->assertScript('document.querySelector("[data-reader=page-input]").value', '4')
        ->assertSeeIn('[data-reader=meta]', 'Page 4 of 5')
        ->type('[data-reader=page-input]', 'abc')->keys('[data-reader=page-input]', 'Enter')->wait(0.4)
        ->assertScript('document.querySelector("[data-reader=page-input]").value', '4')
        ->assertNoJavaScriptErrors();
});

test('the page is saved with a page locator and a reload resumes on it', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('PDF · 3 pages')->wait(0.8);

    expect(ReadingProgress::count())->toBe(0);

    $page->keys(':root', 'ArrowRight')->wait(3);

    $saved = ReadingProgress::sole();

    expect($saved->content_source_id)->toBe($this->source->id)
        ->and($saved->locator)->toBe(['page' => 2])
        ->and(round((float) $saved->percent, 1))->toBe(66.7);

    $page->script('location.reload()');
    $page->wait(1.8)
        ->assertSeeIn('[data-reader=meta]', 'Page 2 of 3')
        ->assertScript('document.querySelector("[data-reader=page-input]").value', '2')
        ->assertScript(($this->painted)(2))
        ->assertNoJavaScriptErrors();

    // Opening on the saved page is not a move: nothing new is written.
    expect(ReadingProgress::sole()->locator)->toBe(['page' => 2]);
});

test('the zoom controls work from the keyboard', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('PDF · 3 pages')->wait(0.8);

    $page->keys('[data-reader=toolbar] [data-reader=zoom-in]', 'Enter')->wait(0.4)->assertSeeIn('[data-reader=toolbar] [data-reader=zoom-value]', '125%')
        ->keys('[data-reader=toolbar] [data-reader=zoom-in]', 'Space')->wait(0.4)->assertSeeIn('[data-reader=toolbar] [data-reader=zoom-value]', '150%')
        ->keys('[data-reader=toolbar] [data-reader=zoom-out]', 'Enter')->wait(0.4)->assertSeeIn('[data-reader=toolbar] [data-reader=zoom-value]', '125%')
        ->keys(':root', '-')->wait(0.3)->assertSeeIn('[data-reader=toolbar] [data-reader=zoom-value]', '100%')
        ->keys(':root', '+')->wait(0.3)->assertSeeIn('[data-reader=toolbar] [data-reader=zoom-value]', '125%')
        ->keys('[data-reader=toolbar] [data-reader=fit-width]', 'Enter')->wait(0.5)->assertSeeIn('[data-reader=toolbar] [data-reader=zoom-value]', '193%')
        ->assertScript('Math.round(document.querySelector("canvas[data-page=\"1\"]").getBoundingClientRect().width)', 1392)
        ->assertScript(($this->painted)(1))
        ->assertNoJavaScriptErrors();

    for ($i = 0; $i < 6; $i++) {
        $page->keys('[data-reader=toolbar] [data-reader=zoom-out]', 'Enter');
    }

    $page->wait(0.4)->assertSeeIn('[data-reader=toolbar] [data-reader=zoom-value]', '50%')
        ->assertScript('document.querySelector("[data-reader=zoom-out]").getAttribute("aria-disabled")', 'true')
        ->assertScript('Math.round(document.querySelector("canvas[data-page=\"1\"]").getBoundingClientRect().width)', 360)
        ->assertNoJavaScriptErrors();
});

test('an encrypted PDF shows the password card', function () {
    $source = pdfSource(tinyPdf(3, encrypted: true));

    visit(route('reader.show', $source->media->slug), $this->desktop)
        ->waitForText('This PDF is protected by a password')
        ->assertSee('Bookflix can\'t open password-protected files.')
        ->assertVisible('[data-reader-state=password] a[href="'.route('stories.show', $source->media->slug).'"]')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('a file that is not a PDF shows the couldn\'t-open card', function () {
    $source = pdfSource('this is not a pdf at all');

    visit(route('reader.show', $source->media->slug), $this->desktop)
        ->waitForText('This file couldn\'t be opened')
        ->assertVisible('[data-reader=retry]')
        ->assertNoJavaScriptErrors();
});

test('a book of 1,000 pages opens at once and keeps only a few canvases', function () {
    $source = pdfSource(tinyPdf(1000));

    $page = visit(route('reader.show', $source->media->slug), $this->desktop)->waitForText('PDF · 1000 pages')->wait(1);

    $page->assertSeeIn('[data-reader=meta]', 'Page 1 of 1000')
        ->assertScript('document.querySelectorAll("canvas").length <= 4')
        ->type('[data-reader=page-input]', '640')->keys('[data-reader=page-input]', 'Enter')->wait(1.2)
        ->assertSeeIn('[data-reader=meta]', 'Page 640 of 1000')
        ->assertScript('document.querySelectorAll("canvas").length <= 4')
        ->assertScript('document.querySelector("canvas[data-page=\"640\"]") !== null')
        ->assertScript('document.querySelector("canvas[data-page=\"1\"]") === null')
        ->assertScript(($this->painted)(640))
        ->keys(':root', 'End')->wait(1)
        ->assertScript('document.querySelectorAll("canvas").length <= 4')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('a long-open tab asks for a fresh address before the old one expires', function () {
    config(['bookflix.reader.url_ttl_minutes' => 1]);
    $source = pdfSource(tinyPdf(1000));

    $page = visit(route('reader.show', $source->media->slug), $this->desktop)->waitForText('PDF · 1000 pages')->wait(0.8);

    // The address lives 60 s, less than the margin the reader keeps, so the first range after the opening one asks again.
    $page->type('[data-reader=page-input]', '700')->keys('[data-reader=page-input]', 'Enter')->wait(1.5)
        ->assertSeeIn('[data-reader=meta]', 'Page 700 of 1000')
        ->assertScript('Number(document.querySelector("[data-reader-engine]").dataset.urlRefreshes) >= 1')
        ->assertScript(($this->painted)(700))
        ->assertNoJavaScriptErrors();
});

test('a file withdrawn in the middle of reading shows the withdrawn card', function () {
    $source = pdfSource(tinyPdf(1000));

    $page = visit(route('reader.show', $source->media->slug), $this->desktop)->waitForText('PDF · 1000 pages')->wait(0.8);

    $source->update(['verified_at' => null, 'verified_by' => null]);

    $page->type('[data-reader=page-input]', '800')->keys('[data-reader=page-input]', 'Enter')->wait(2)
        ->assertSee('This book is no longer available to read here')
        ->assertNoJavaScriptErrors();
});

test('the reader runs clean and passes the accessibility audit on a desktop and on a phone', function (string $size) {
    visit(($this->read)(), $this->{$size})->waitForText('PDF · 3 pages')->wait(0.9)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
})->with(['desktop', 'phone']);

test('on a phone the zoom floats over the page and the page field stays out of the bar', function () {
    visit(($this->read)(), $this->phone)->waitForText('Page 1 of 3')->wait(0.6)
        ->assertVisible('[data-reader=zoom-float]')
        ->assertScript('getComputedStyle(document.querySelector("[data-reader=page-input]")).display === "none" || document.querySelector("[data-reader=page-input]").offsetParent === null')
        ->assertScript('Math.round(document.querySelector("canvas[data-page=\"1\"]").getBoundingClientRect().width)', 343)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});
