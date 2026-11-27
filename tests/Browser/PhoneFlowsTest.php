<?php

use App\Enums\ReadingStatus;
use App\Models\LibraryEntry;
use App\Models\User;
use Database\Seeders\DeweySeeder;

/*
 * The real phone path, end to end, at 375x667 as a reader: search for a title, open its story, add it to the library and change its status, read it
 * (a verified public-domain EPUB), turn a page, go back, and open and close a dialog by tap and by Escape. After every step: no sideways scroll, no fixed
 * control pushed past the window edge, and no script error.
 */

beforeEach(function () {
    (new DeweySeeder)->run();

    $this->reader = User::factory()->create();
    $this->source = epubSource();
    $this->story = $this->source->media;
    $this->story->update(['cover_url' => '/favicon.svg']);
    $this->story->refreshSearchText();
    $this->actingAs($this->reader);

    $this->phone = ['viewport' => ['width' => 375, 'height' => 667], 'reducedMotion' => 'reduce'];

    /** The page does not scroll sideways and no visible fixed or sticky box is cut by the window's right edge. */
    $this->sound = '(() => {
        const width = window.innerWidth;
        const fixed = Array.from(document.querySelectorAll("body *")).filter((el) => {
            const style = getComputedStyle(el);
            const box = el.getBoundingClientRect();

            return (style.position === "fixed" || style.position === "sticky") && style.display !== "none" && style.visibility !== "hidden" && box.width > 1 && box.right > width + 0.5 && box.left < width - 0.5 && ! el.closest("ui-toast-group");
        }).map((el) => el.tagName.toLowerCase() + "." + String(el.className).slice(0, 40));

        return document.documentElement.scrollWidth <= document.documentElement.clientWidth && fixed.length === 0 ? "ok" : "scrollWidth " + document.documentElement.scrollWidth + " of " + document.documentElement.clientWidth + "; " + fixed.join(" ");
    })()';
});

test('search, story, library, reader and a dialog work on a phone without sideways scroll or script errors', function () {
    $page = visit('/search', $this->phone)->wait(1.5);
    $page->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();

    // Search for the title and open it.
    $page->keys('search input[role=combobox]', str_split('open book'))->wait(2)
        ->assertSee('The Open Book')
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors()
        ->click('The Open Book')->wait(1.5)
        ->assertPathBeginsWith('/stories/')
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();

    // Add it to the library, then change its status.
    $page->click('Add to Library')->wait(1.2)
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();
    expect(LibraryEntry::query()->where('user_id', $this->reader->id)->where('media_id', $this->story->id)->exists())->toBeTrue();

    $page->click('[data-test="library-status"]')->wait(0.5)
        ->click('ui-menu [role=menuitem]:has-text("Reading"), ui-menu button:has-text("Reading")')->wait(1.2)
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();
    expect(LibraryEntry::query()->where('user_id', $this->reader->id)->where('media_id', $this->story->id)->value('status'))->toBe(ReadingStatus::Reading);

    // A dialog on the story page, closed by tap and by Escape.
    $page->click('[data-test="log-progress"]')->wait(0.8)
        ->assertScript('document.querySelector("dialog[open]") !== null', true)
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors()
        ->click('dialog[open] button:has-text("Cancel")')->wait(0.8)
        ->assertScript('document.querySelector("dialog[open]") === null', true)
        ->assertScript($this->sound, 'ok')
        ->click('[data-test="log-progress"]')->wait(0.8)
        ->assertScript('document.querySelector("dialog[open]") !== null', true)
        ->keys(':root', 'Escape')->wait(0.8)
        ->assertScript('document.querySelector("dialog[open]") === null', true)
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();

    // Read it: open the reader, turn a page, then go back.
    $page->click('[data-test="read-action"]')->wait(1)
        ->waitForText('Chapter 1 of 2')->wait(0.6)
        ->assertPathBeginsWith('/read/')
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors()
        ->keys(':root', 'ArrowRight')->wait(0.8)
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();

    $page->script('history.back()');
    $page->wait(1.5)->assertPathBeginsWith('/stories/')
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();
});
