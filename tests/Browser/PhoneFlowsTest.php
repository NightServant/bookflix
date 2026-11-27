<?php

use App\Enums\ReadingStatus;
use App\Models\LibraryEntry;
use App\Models\ReadingProgress;
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
    $ready = browserUntil('document.readyState === "complete" && !! window.Livewire');
    $dialogOpen = browserUntil('document.querySelector("dialog[open]") !== null');
    $dialogShut = browserUntil('document.querySelector("dialog[open]") === null');

    $page = visit('/search', $this->phone);
    $page->assertScript($ready, true)->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();

    // Search for the title and open it.
    $page->keys('search input[role=combobox]', str_split('open book'))
        ->waitForText('The Open Book')
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors()
        ->click('The Open Book')->assertScript(browserUntil('location.pathname.startsWith("/stories/")'), true)
        ->assertScript($ready, true)->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();

    // Add it to the library, then change its status.
    $page->click('Add to Library')->assertScript(browserUntil('!! document.querySelector(\'[data-test="library-status"]\')'), true)
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();
    expect(LibraryEntry::query()->where('user_id', $this->reader->id)->where('media_id', $this->story->id)->exists())->toBeTrue();

    $page->click('[data-test="library-status"]')->assertScript(browserUntil('Array.from(document.querySelectorAll("ui-menu [role=menuitem], ui-menu button")).some((el) => el.offsetParent !== null)'), true)
        ->click('ui-menu [role=menuitem]:has-text("Reading"), ui-menu button:has-text("Reading")')
        ->assertScript(browserUntil('document.querySelector(\'[data-test="library-status"]\').textContent.trim() === "Reading"'), true)
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();
    expect(LibraryEntry::query()->where('user_id', $this->reader->id)->where('media_id', $this->story->id)->value('status'))->toBe(ReadingStatus::Reading);

    // A dialog on the story page, closed by tap and by Escape.
    $page->click('[data-test="log-progress"]')->assertScript($dialogOpen, true)
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors()
        ->click('dialog[open] button:has-text("Cancel")')->assertScript($dialogShut, true)
        ->assertScript($this->sound, 'ok')
        ->click('[data-test="log-progress"]')->assertScript($dialogOpen, true)
        ->keys(':root', 'Escape')->assertScript($dialogShut, true)
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();

    // The same dialog, submitted: a percent and a page, saved and shown on the page.
    $page->click('[data-test="log-progress"]')->assertScript($dialogOpen, true)
        ->fill('dialog[open] input[type=number][min="0"][max="100"]', '42')
        ->fill('dialog[open] input[type=number][min="1"]', '17')
        ->assertScript($this->sound, 'ok')
        ->click('dialog[open] button:has-text("Save progress")')->assertScript($dialogShut, true)
        ->assertScript(browserUntil('document.querySelector(\'[data-test="log-progress"]\').textContent.includes("42% read")'), true)
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();

    $progress = ReadingProgress::query()->where('user_id', $this->reader->id)->where('media_id', $this->story->id)->firstOrFail();
    expect((float) $progress->percent)->toBe(42.0)->and($progress->page)->toBe(17);

    // Read it: open the reader, turn a page, then go back.
    $page->click('[data-test="read-action"]')->waitForText('Chapter 1 of 2')
        ->assertScript(browserUntil('location.pathname.startsWith("/read/")'), true)
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors()
        ->keys(':root', 'ArrowRight')
        ->assertScript(browserUntil('document.querySelector("[data-reader=percent]") !== null'), true)
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();

    $page->script('history.back()');
    $page->assertScript(browserUntil('location.pathname.startsWith("/stories/")'), true)
        ->assertScript($this->sound, 'ok')->assertNoJavaScriptErrors();
});
