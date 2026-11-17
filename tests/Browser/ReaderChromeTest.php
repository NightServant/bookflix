<?php

use App\Models\User;

/*
 * The reader's own interface in a real browser, around an EPUB opened from a book built here: the two panels (focus trapped, Esc, focus back), the bars
 * that hide after three seconds and come back, the keyboard, Next chapter and the end of the book, the size of every control, and reduced motion.
 */

beforeEach(function () {
    $this->source = epubSource();
    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];
    $this->actingAs(User::factory()->create());
    $this->read = fn () => route('reader.show', $this->source->media->slug);
    $this->opacity = 'getComputedStyle(document.querySelector("[data-reader=toolbar]")).opacity';
    $this->doc = 'document.querySelector("foliate-view").renderer.getContents()[0].doc';
    $this->focusIn = fn (string $selector) => "document.querySelector('{$selector}').contains(document.activeElement)";
});

test('the reading settings panel traps focus, Esc closes it, and focus returns to the Aa button', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4);

    $page->click('[data-reader=settings-button]')->wait(0.3)
        ->assertVisible('#reader-settings')
        ->assertAttribute('[data-reader=settings-button]', 'aria-expanded', 'true');

    for ($tab = 0; $tab < 25; $tab++) {
        $page->keys(':root', 'Tab');
        expect($page->script(($this->focusIn)('#reader-settings')))->toBeTrue("focus left the panel after {$tab} tabs");
    }

    $page->keys(':root', 'Escape')->wait(0.3)
        ->assertMissing('#reader-settings')
        ->assertScript('document.activeElement.dataset.reader', 'settings-button');
});

test('the contents panel opens with the slash key, lists the chapters, traps focus, and Esc returns focus to its button', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4);

    $page->keys(':root', '/')->wait(0.3)
        ->assertVisible('#reader-contents')
        ->assertSeeIn('#reader-contents nav', 'I. Down the Rabbit-Hole')
        ->assertSeeIn('#reader-contents nav', 'II. The Pool of Tears')
        ->assertSeeIn('[data-reader=contents-footer]', 'You are in I. Down the Rabbit-Hole')
        ->assertAttribute('[data-reader=chapter][aria-current=true]', 'aria-current', 'true');

    for ($tab = 0; $tab < 8; $tab++) {
        $page->keys(':root', 'Tab');
        expect($page->script(($this->focusIn)('#reader-contents')))->toBeTrue("focus left the contents after {$tab} tabs");
    }

    $page->keys(':root', 'Escape')->wait(0.3)
        ->assertMissing('#reader-contents')
        ->assertScript('document.activeElement.dataset.reader', 'contents-button');
});

test('choosing a chapter in the contents goes there and closes the panel', function () {
    visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4)
        ->click('[data-reader=contents-button]')->wait(0.3)
        ->click('II. The Pool of Tears')->wait(1)
        ->assertMissing('#reader-contents')
        ->assertSeeIn('[data-reader=meta]', 'Chapter 2 of 2')
        ->assertSeeIn('[data-reader=subtitle]', 'II. The Pool of Tears')
        ->assertNoJavaScriptErrors();
});

test('the settings change the book and are kept on this device', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4);

    $page->click('[data-reader=settings-button]')
        ->click('[data-reader=text-larger]')->click('[data-reader=text-larger]')
        ->click('[data-reader-option="theme:sepia"]')
        ->click('[data-reader-option="typeface:serif"]')->wait(0.8)
        ->assertSeeIn('[data-reader=text-size-value]', '130%')
        ->assertScript("getComputedStyle({$this->doc}.documentElement).fontSize", '23.36px')
        ->assertScript("getComputedStyle({$this->doc}.body).backgroundColor", 'rgb(33, 28, 20)')
        ->assertScript('JSON.parse(localStorage.getItem("bookflix.reader.settings")).fontSize', 130);

    // The same browser, a new load: what was chosen is still there.
    $page->script('location.reload()');
    $page->wait(3)->waitForText('Chapter 1 of 2')->wait(0.8)
        ->assertScript("getComputedStyle({$this->doc}.body).backgroundColor", 'rgb(33, 28, 20)');

    $page->script('window.dispatchEvent(new PointerEvent("pointermove", { pointerType: "mouse" }))');
    $page->click('[data-reader=settings-button]')
        ->assertSeeIn('[data-reader=text-size-value]', '130%')
        ->assertAttribute('[data-reader-option="theme:sepia"]', 'aria-checked', 'true');
});

test('the bars hide after three seconds, never while a panel is open, and come back on mouse move', function () {
    $moved = 'window.dispatchEvent(new PointerEvent("pointermove", { pointerType: "mouse" }))';

    // The mouse starts in the corner, over the top bar, which keeps it up: move it onto the page.
    $page = visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4)
        ->assertScript($this->opacity, '1')
        ->hover('[data-reader-engine]');

    $page->wait(3.8)->assertScript($this->opacity, '0');

    $page->script($moved);
    $page->wait(0.5)->assertScript($this->opacity, '1');

    $page->click('[data-reader=settings-button]')->wait(3.8)->assertScript($this->opacity, '1')
        ->assertVisible('#reader-settings');

    $page->keys(':root', 'Escape');
    $page->wait(0.5)->assertScript('document.activeElement.dataset.reader', 'settings-button')->wait(3.8)->assertScript($this->opacity, '1');
});

test('a tap on the middle third of the page shows and hides the bars on a touch screen, and the sides turn the page', function () {
    $page = visit(($this->read)(), $this->phone + ['hasTouch' => true])->waitForText('Chapter 1 of 2')->wait(0.4)
        ->hover('[data-reader-engine]')->wait(3.8)->assertScript($this->opacity, '0');

    $tap = fn (float $x) => $page->script("document.querySelector('[data-reader-engine]').dispatchEvent(new PointerEvent('pointerup', { pointerType: 'touch', clientX: window.innerWidth * {$x}, clientY: 400, bubbles: true }))");

    $tap(0.5);
    $page->wait(0.5)->assertScript($this->opacity, '1');

    $tap(0.5);
    $page->wait(0.5)->assertScript($this->opacity, '0');

    $before = $page->script('document.querySelector("[data-reader=percent]").textContent');
    $tap(0.9);
    $page->wait(0.8);
    expect($page->script('document.querySelector("[data-reader=percent]").textContent'))->not->toBe($before);
});

test('the keyboard pages, and Next chapter goes to the last chapter and disables itself', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4);

    $before = $page->script('document.querySelector("[data-reader=percent]").textContent');
    $page->keys(':root', 'PageDown')->wait(0.8);
    expect($page->script('document.querySelector("[data-reader=percent]").textContent'))->not->toBe($before);
    $page->keys(':root', 'ArrowLeft')->wait(0.8)->assertSeeIn('[data-reader=percent]', $before);

    $page->assertAttributeMissing('[data-reader=next-chapter]', 'aria-disabled')
        ->click('[data-reader=next-chapter]')->wait(1)
        ->assertSeeIn('[data-reader=meta]', 'Chapter 2 of 2')
        ->assertSeeIn('[data-reader=next-chapter-label]', 'Last chapter')
        ->assertAttribute('[data-reader=next-chapter]', 'aria-disabled', 'true');
});

test('F toggles full screen where the browser allows it', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4);

    $allowed = $page->script('document.fullscreenEnabled');
    $page->keys(':root', 'f')->wait(0.8);

    expect($page->script('document.fullscreenElement !== null'))->toBe((bool) $allowed);

    if ($allowed) {
        $page->keys(':root', 'f')->wait(0.8)->assertScript('document.fullscreenElement === null');
    }
});

test('turning onto the last page shows the end of the book card, with a way back', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4)
        ->click('[data-reader=contents-button]')->wait(0.3)->click('II. The Pool of Tears')->wait(1);

    for ($turn = 0; $turn < 40 && ! $page->script('document.querySelector("[data-reader=finished]").checkVisibility()'); $turn++) {
        $page->keys(':root', 'ArrowRight')->wait(0.4);
    }

    $page->assertVisible('[data-reader=finished]')
        ->assertSee('You finished')
        ->assertSee('Marked as completed in your library.')
        ->assertSeeIn('[data-reader=percent]', '100%')
        ->assertSeeIn('[data-reader=next-chapter-label]', 'Last chapter')
        ->assertVisible('[data-reader=finished] a[href="'.route('stories.show', $this->source->media->slug).'"]')
        ->assertScript('document.querySelector("[data-reader=finished]").contains(document.activeElement)', true);

    $page->keys(':root', 'Escape')->wait(0.3)->assertMissing('[data-reader=finished]');
});

test('every control is at least 44 px', function (string $size) {
    $page = visit(($this->read)(), $this->{$size})->waitForText('Chapter 1 of 2')->wait(0.4);

    $small = $page->script(<<<'JS'
        [...document.querySelectorAll('[data-reader=toolbar] a, [data-reader=toolbar] button, [data-reader=bottom-bar] button, [data-reader=bottom-bar] input, [data-reader=prev-page], [data-reader=next-page]')]
            .filter((el) => el.checkVisibility())
            .map((el) => ({ name: el.getAttribute('aria-label') || el.dataset.reader, box: el.getBoundingClientRect() }))
            .filter(({ box }) => box.width < 43.9 || box.height < 43.9)
            .map(({ name, box }) => `${name} ${box.width}x${box.height}`)
    JS);

    expect($small)->toBe([]);

    $page->click('[data-reader=settings-button]')->wait(0.3);

    $small = $page->script(<<<'JS'
        [...document.querySelectorAll('#reader-settings button')]
            .filter((el) => el.checkVisibility())
            .map((el) => ({ name: el.getAttribute('aria-label') || el.textContent.trim(), box: el.getBoundingClientRect() }))
            .filter(({ box }) => box.width < 43.9 || box.height < 43.9)
            .map(({ name, box }) => `${name} ${box.width}x${box.height}`)
    JS);

    expect($small)->toBe([]);

    $page->keys(':root', 'Escape')->click('[data-reader=contents-button]')->wait(0.3);

    $small = $page->script(<<<'JS'
        [...document.querySelectorAll('#reader-contents button[data-reader=chapter]')]
            .map((el) => el.getBoundingClientRect())
            .filter((box) => box.height < 47.9)
            .length
    JS);

    expect($small)->toBe(0);
})->with(['desktop', 'phone']);

test('with reduced motion the bars have no transition, and they hide at once', function () {
    $transition = 'getComputedStyle(document.querySelector("[data-reader=toolbar]")).transitionDuration';

    visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4)->assertScript($transition, '0.2s');

    $page = visit(($this->read)(), $this->desktop + ['reducedMotion' => 'reduce'])->waitForText('Chapter 1 of 2')->wait(0.4)
        ->assertScript($transition, '0s')
        ->assertScript($this->opacity, '1')
        ->hover('[data-reader-engine]');

    $page->wait(3.2)->assertScript($this->opacity, '0');
});

test('both panels and the bars pass the accessibility audit on a desktop and on a phone', function (string $size) {
    $page = visit(($this->read)(), $this->{$size})->waitForText('Chapter 1 of 2')->wait(0.9)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();

    $page->click('[data-reader=settings-button]')->wait(0.4)->assertNoAccessibilityIssues();
    $page->keys(':root', 'Escape')->click('[data-reader=contents-button]')->wait(0.4)->assertNoAccessibilityIssues()->assertNoJavaScriptErrors()->assertNoConsoleLogs();
})->with(['desktop', 'phone']);
