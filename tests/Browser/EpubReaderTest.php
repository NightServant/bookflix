<?php

use App\Models\ReadingProgress;
use App\Models\User;

/*
 * The EPUB reader in a real browser (foliate-js in a sandboxed iframe): it opens a book built here, turns pages, saves the place, resumes it,
 * switches between pages and scrolling without a reload, falls back to the percent, and shows the cards for a file that can't be opened or that was
 * withdrawn in the middle of reading. The chrome around it (panels, bars, keys) is in ReaderChromeTest.
 */

beforeEach(function () {
    $this->reader = User::factory()->create();
    $this->source = epubSource();
    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];
    $this->actingAs($this->reader);
    $this->read = fn () => route('reader.show', $this->source->media->slug);
});

test('it opens the book and shows the chapter and the page', function () {
    visit(($this->read)(), $this->desktop)
        ->waitForText('Chapter 1 of 2')->wait(0.4)
        ->assertSeeIn('[data-reader=subtitle]', 'I. Down the Rabbit-Hole')
        ->assertSeeIn('[data-reader=percent]', '0%')
        ->assertSeeIn('[data-reader=next-chapter-label]', 'Next chapter')
        ->assertScript('document.querySelector("foliate-view").renderer.getAttribute("flow")', 'paginated')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('ArrowRight turns the page, the place is saved, and a reload resumes it', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4);

    expect(ReadingProgress::count())->toBe(0);

    $page->keys(':root', 'ArrowRight')->wait(0.6)->keys(':root', 'ArrowRight')->wait(3);

    $saved = ReadingProgress::sole();

    expect($saved->content_source_id)->toBe($this->source->id)
        ->and($saved->locator['cfi'])->toStartWith('epubcfi(')
        ->and((float) $saved->percent)->toBeGreaterThan(0.0)
        ->and($saved->client_ts)->toBeInt();

    $label = rtrim(rtrim(number_format((float) $saved->percent, 1, '.', ''), '0'), '.').'%';

    $page->assertSeeIn('[data-reader=percent]', $label);
    $meta = $page->script('document.querySelector("[data-reader=meta]").textContent');

    $again = visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(1.4);

    $again->assertSeeIn('[data-reader=percent]', $label)
        ->assertScript('document.querySelector("[data-reader=meta]").textContent', $meta)
        ->assertNoJavaScriptErrors();
});

test('it switches between pages and scrolling without reloading the page', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4);
    $page->script('window.__marker = 42');
    $page->click('[data-reader=settings-button]')->click('[data-reader-option="layout:scrolled"]')->wait(1)
        ->assertScript('document.querySelector("foliate-view").renderer.getAttribute("flow")', 'scrolled')
        ->assertScript('window.__marker', 42)
        ->click('[data-reader-option="layout:paginated"]')->wait(1)
        ->assertScript('document.querySelector("foliate-view").renderer.getAttribute("flow")', 'paginated')
        ->assertScript('window.__marker', 42)
        ->assertNoJavaScriptErrors();
});

test('a place with no CFI falls back to the percent', function () {
    ReadingProgress::create(['user_id' => $this->reader->id, 'media_id' => $this->source->media_id, 'content_source_id' => $this->source->id, 'locator' => null, 'percent' => 60, 'last_read_at' => now()]);

    $page = visit(($this->read)(), $this->desktop)->waitForText('Chapter')->wait(1);
    $shown = (float) $page->script('parseFloat(document.querySelector("[data-reader=percent]").textContent)');

    expect($shown)->toBeGreaterThan(40.0)->toBeLessThan(80.0);
    $page->assertSeeIn('[data-reader=meta]', 'Chapter 2 of 2')->assertNoJavaScriptErrors();
});

test('an EPUB that is not one shows the couldn\'t-open card and a way back', function () {
    $source = epubSource('this is not an epub at all');

    visit(route('reader.show', $source->media->slug), $this->desktop)
        ->waitForText('This file couldn\'t be opened')
        ->assertSee('Your place in the story is saved.')
        ->assertVisible('[data-reader-state=corrupt] a[href="'.route('stories.show', $source->media->slug).'"]')
        ->assertVisible('[data-reader=retry]')
        ->assertNoJavaScriptErrors();
});

test('a withdrawn source makes the next save 403 and shows the withdrawn card, keeping the place', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4);

    $page->keys(':root', 'ArrowRight')->wait(3);

    $before = ReadingProgress::sole()->percent;

    $this->source->update(['verified_at' => null, 'verified_by' => null]);

    $page->keys(':root', 'ArrowRight')->wait(3)
        ->assertSee('This book is no longer available to read here')
        ->assertSee('Your place is saved at')
        ->assertVisible('[data-reader-state=withdrawn] a[href="'.route('stories.show', $this->source->media->slug).'"]')
        ->assertNoJavaScriptErrors();

    expect(ReadingProgress::sole()->percent)->toBe($before);
});

test('the iframe that holds the book is sandboxed without allow-scripts', function () {
    visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4)
        ->assertScript('document.querySelector("foliate-view").renderer.getContents().length', 1)
        ->assertScript('document.querySelector("foliate-view").renderer.getContents()[0].doc.defaultView.frameElement.getAttribute("sandbox")', 'allow-same-origin')
        ->assertScript('document.querySelector("foliate-view").renderer.getContents()[0].doc.defaultView.frameElement.sandbox.contains("allow-scripts")', false)
        ->assertNoConsoleLogs();
});

test('the reader runs clean and passes the accessibility audit on a desktop and on a phone', function (string $size) {
    visit(($this->read)(), $this->{$size})->waitForText('Chapter 1 of 2')->wait(0.9)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
})->with(['desktop', 'phone']);
