<?php

use App\Models\Recommendation;
use App\Models\RecommendationEvent;

/*
 * The personalized Home in a real browser: "Not interested" by mouse, keyboard and on a phone (the bottom sheet), the undo toast, focus,
 * click and impression reports, and the shared checks. The page's markup and queries are tests/Feature/Discovery/HomePersonalizedTest.
 */

beforeEach(function () {
    [$this->user, $this->catalog] = richReader();
    $this->catalog->each(fn ($story) => $story->update(['cover_url' => '/favicon.svg']));
    $this->actingAs($this->user);
    // Home is rendered once before the browser asks for it: its Blade views compile and its shelves are built outside the browser's timeouts.
    $this->get(route('home'))->assertOk();

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 390, 'height' => 844]];
    $this->narrow = ['viewport' => ['width' => 320, 'height' => 700]];
    $this->tile = fn (int $n) => 'section[aria-labelledby=shelf-recommended] ul > li:nth-child('.$n.')';
    $this->shelfCount = 'document.querySelectorAll("section[aria-labelledby=shelf-recommended] ul > li").length';
    $this->settle = function ($page) {
        foreach ([0, 500, 1000, 1800, 2600] as $offset) {
            $page->script("window.scrollTo(0, {$offset})");
            $page->wait(0.25);
        }

        $page->script('document.querySelector("section[aria-labelledby=shelf-recommended]").scrollIntoView({ block: "center" })');
        $page->wait(0.3);

        return $page;
    };
    // The recommendation id of the nth tile of Recommended For You, read off its button once the page has built the shelves.
    $this->toast = '[role=status]:not(ui-toast-group)';
    $this->toastShown = fn (): string => 'getComputedStyle(document.querySelector("[role=status]:not(ui-toast-group) > div")).display !== "none"';
    // Dismisses the nth tile of Recommended For You in one script, as a quick second dismissal does (returns its recommendation id).
    $this->dismissByScript = fn ($page, int $n): int => (int) $page->script('(() => { const tile = document.querySelector("section[aria-labelledby=shelf-recommended] ul > li:nth-child('.$n.')"); const button = tile.querySelector("[data-tile-menu]"); button.click(); tile.querySelector("[role=menuitem]").click(); return button.dataset.tileMenu; })()');
    $this->recommendation = fn (int $n): int => Recommendation::where('user_id', $this->user->id)->where('shelf', 'recommended')->orderBy('id')->get()[$n - 1]->id;
});

test('home with history passes the shared checks on desktop, on a phone and with reduced motion, without sideways scroll at 390 and 320', function () {
    foreach ([$this->desktop, $this->phone + ['reducedMotion' => 'reduce'], $this->narrow, $this->desktop + ['reducedMotion' => 'reduce']] as $options) {
        $page = ($this->settle)(visit(route('home'), $options)->wait(1));

        $page->assertSee('Recommended For You')->assertSee('Trending Now')
            ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
            ->assertNoJavaScriptErrors()
            ->assertNoAccessibilityIssues();
    }
});

test('a mouse reveals the overflow button on hover, "Not interested" removes the tile, the toast says so and offers Undo', function () {
    $page = ($this->settle)(visit(route('home'), $this->desktop)->wait(1));
    $button = ($this->tile)(2).' [data-tile-menu]';

    $page->assertScript('getComputedStyle(document.querySelector("'.$button.'")).opacity', '0')
        ->hover(($this->tile)(2).' a')->wait(0.4)
        ->assertScript('getComputedStyle(document.querySelector("'.$button.'")).opacity', '1')
        ->assertScript('document.querySelector("'.$button.'").getBoundingClientRect().width', 44)
        ->click($button)->wait(0.3)
        ->assertScript('document.querySelector("'.$button.'").getAttribute("aria-expanded")', 'true')
        ->assertScript('document.querySelector("'.$button.'").getAttribute("aria-haspopup")', 'menu')
        ->assertScript('document.activeElement.matches("[role=menuitem]")', true)
        ->assertNoAccessibilityIssues()
        ->click(($this->tile)(2).' [role=menuitem]')->wait(0.6)
        ->assertScript($this->shelfCount, 19)
        ->assertScript('document.querySelector("section[aria-labelledby=shelf-recommended] [data-recommendation=\"'.($this->recommendation)(2).'\"]")', null)
        ->assertScript('document.querySelector("[role=status]:not(ui-toast-group)").textContent.includes("Removed. We won")', true)
        ->assertScript('document.querySelector("[role=status]:not(ui-toast-group)").textContent.includes("Press Z to undo.")', true)
        ->assertScript('document.querySelector("[role=status] button[aria-keyshortcuts]").textContent.trim()', 'Undo')
        ->assertNoJavaScriptErrors();

    expect(RecommendationEvent::where('recommendation_id', ($this->recommendation)(2))->where('event', 'dismiss')->count())->toBe(1);

    $page->click('[role=status] button:has-text("Undo")')->wait(0.6)
        ->assertScript($this->shelfCount, 20)
        ->assertScript('document.querySelector("section[aria-labelledby=shelf-recommended] ul > li:nth-child(2) a").matches(":focus")', true)
        ->assertNoJavaScriptErrors();

    expect(RecommendationEvent::where('event', 'dismiss')->count())->toBe(0);
});

test('the keyboard opens the menu on the button with Enter, focuses the item, Escape returns to the button, and confirming moves focus to the next tile', function () {
    $page = ($this->settle)(visit(route('home'), $this->desktop)->wait(1));
    $id = ($this->recommendation)(2);
    $button = "[data-tile-menu='".$id."']";

    $page->keys(($this->tile)(2).' a', 'Tab')->wait(0.2)
        ->assertScript('document.activeElement.matches("'.$button.'")', true)
        ->assertScript('getComputedStyle(document.activeElement).opacity', '1')
        ->keys($button, 'Enter')->wait(0.3)
        ->assertScript('document.activeElement.matches("'.$button.' + [role=menu] [role=menuitem]")', true)
        ->keys($button.' + [role=menu] [role=menuitem]', 'Escape')->wait(0.3)
        ->assertScript('document.activeElement.matches("'.$button.'")', true)
        ->assertScript('document.querySelector("'.$button.'").getAttribute("aria-expanded")', 'false')
        ->keys($button, 'Space')->wait(0.3)
        ->assertScript('document.activeElement.matches("'.$button.' + [role=menu] [role=menuitem]")', true)
        ->keys($button.' + [role=menu] [role=menuitem]', 'Enter')->wait(0.6)
        ->assertScript($this->shelfCount, 19)
        ->assertScript('document.activeElement.matches("section[aria-labelledby=shelf-recommended] ul > li:nth-child(2) a")', true)
        // The focus the page moves to the next tile is not an arrival: its preview card stays closed (it would cover the undo toast).
        ->wait(0.7)
        ->assertScript('[...document.querySelectorAll("[data-story-card]")].some((card) => getComputedStyle(card).display !== "none")', false)
        ->assertNoJavaScriptErrors();
});

test('Enter and Space on the button put focus on the first menu item at once, every time', function () {
    $page = ($this->settle)(visit(route('home'), $this->desktop)->wait(1));

    foreach ([2, 3, 4] as $n) {
        foreach (['Enter', 'Space'] as $key) {
            $button = ($this->tile)($n).' [data-tile-menu]';

            // Focus is read in the microtask right after the key's click, before any timer, so a late focus would fail here.
            $page->script('(() => { const b = document.querySelector("'.$button.'"); b.focus(); b.click(); window.focusedAtOnce = null; queueMicrotask(() => { window.focusedAtOnce = document.activeElement.matches("[role=menuitem]"); }); })()');
            $page->assertScript('window.focusedAtOnce', true)->keys($button.' + [role=menu] [role=menuitem]', 'Escape')->wait(0.2);
        }
    }

    $page->assertNoJavaScriptErrors();
});

test('on a phone the button is always there, opens a bottom sheet naming the story, and Cancel, Esc and the dimmed area close it', function () {
    $page = ($this->settle)(visit(route('home'), $this->phone)->wait(1));
    $button = ($this->tile)(1).' [data-tile-menu]';

    $page->assertScript('getComputedStyle(document.querySelector("'.$button.'")).opacity', '1')
        ->assertScript('document.querySelector("'.$button.'").getAttribute("aria-haspopup")', 'dialog')
        ->click($button)->wait(0.4)
        ->assertScript('document.querySelector("dialog[aria-labelledby=tile-sheet-title]").open', true)
        ->assertScript('document.querySelector("#tile-sheet-title").textContent.trim().length > 0', true)
        ->assertScript('document.activeElement.textContent.includes("Not interested")', true)
        ->assertScript('(() => { const r = document.querySelector("dialog[aria-labelledby=tile-sheet-title]").getBoundingClientRect(); return Math.abs(r.bottom - innerHeight) < 1 && r.width === innerWidth; })()', true)
        ->assertNoAccessibilityIssues()
        ->click('dialog[aria-labelledby=tile-sheet-title] button:has-text("Cancel")')->wait(0.3)
        ->assertScript('document.querySelector("dialog[aria-labelledby=tile-sheet-title]").open', false)
        ->assertScript('document.activeElement.matches("'.$button.'")', true)
        ->click($button)->wait(0.3)->keys('dialog[aria-labelledby=tile-sheet-title] button:has-text("Cancel")', 'Escape')->wait(0.3)
        ->assertScript('document.querySelector("dialog[aria-labelledby=tile-sheet-title]").open', false)
        ->assertScript($this->shelfCount, 20);

    $page->click($button)->wait(0.3)
        ->click('dialog[aria-labelledby=tile-sheet-title] button:has-text("Not interested")')->wait(0.6)
        ->assertScript('document.querySelector("dialog[aria-labelledby=tile-sheet-title]").open', false)
        ->assertScript($this->shelfCount, 19)
        ->assertScript('document.querySelector("[role=status]:not(ui-toast-group)").textContent.includes("Removed")', true)
        ->assertNoJavaScriptErrors();

    expect(RecommendationEvent::where('event', 'dismiss')->count())->toBe(1);
});

// The toast keeps its 10 s in the product; these tests shorten the window (config) so they prove the same behaviour without waiting it out.
test('the toast stays for its whole window and then goes', function () {
    config(['bookflix.recommendations.undo_seconds' => 3]);
    $page = ($this->settle)(visit(route('home'), $this->desktop)->wait(1));

    $page->hover(($this->tile)(2).' a')->click(($this->tile)(2).' [data-tile-menu]')->wait(0.2)
        ->click(($this->tile)(2).' [role=menuitem]')->wait(0.3)
        ->assertScript(($this->toastShown)(), true)
        ->wait(1.5)
        ->assertScript(($this->toastShown)(), true)
        ->wait(2.2)
        ->assertScript('getComputedStyle(document.querySelector("'.$this->toast.' > div")).display', 'none');
});

test('the toast waits while one of its buttons has focus, and goes a little after focus leaves', function () {
    config(['bookflix.recommendations.undo_seconds' => 3]);
    $page = ($this->settle)(visit(route('home'), $this->desktop)->wait(1));

    $page->hover(($this->tile)(2).' a')->click(($this->tile)(2).' [data-tile-menu]')->wait(0.2)
        ->click(($this->tile)(2).' [role=menuitem]')->wait(1.5);
    $page->script('document.querySelector("'.$this->toast.' button[aria-keyshortcuts]").focus()');
    $page->assertScript('document.querySelector("'.$this->toast.'").contains(document.activeElement)', true)
        ->wait(4)
        ->assertScript(($this->toastShown)(), true);
    $page->script('document.querySelector("section[aria-labelledby=shelf-recommended] ul > li:nth-child(2) a").focus()');
    $page->wait(3.5)
        ->assertScript('getComputedStyle(document.querySelector("'.$this->toast.' > div")).display', 'none');
});

test('the toast waits while the pointer is over it, also when a new message arrives meanwhile', function () {
    config(['bookflix.recommendations.undo_seconds' => 3]);
    $page = ($this->settle)(visit(route('home'), $this->desktop)->wait(1));

    $page->hover(($this->tile)(2).' a')->click(($this->tile)(2).' [data-tile-menu]')->wait(0.2)
        ->click(($this->tile)(2).' [role=menuitem]')->wait(1.5)
        ->hover($this->toast.' button[aria-keyshortcuts]')->wait(0.3);
    // A second dismissal says its message while the pointer stays where it is: that does not start the clock again.
    ($this->dismissByScript)($page, 3);
    $page->wait(4.5)
        ->assertScript(($this->toastShown)(), true)
        ->hover(($this->tile)(1).' a')->wait(3.5)
        ->assertScript('getComputedStyle(document.querySelector("'.$this->toast.' > div")).display', 'none');
});

test('Undo is on the Z key while the toast offers it, and the toast says so', function () {
    $page = ($this->settle)(visit(route('home'), $this->desktop)->wait(1));
    $id = ($this->recommendation)(2);

    $page->hover(($this->tile)(2).' a')->click(($this->tile)(2).' [data-tile-menu]')->wait(0.2)
        ->click(($this->tile)(2).' [role=menuitem]')->wait(0.8)
        ->assertScript($this->shelfCount, 19)
        ->assertScript('document.querySelector("'.$this->toast.'").textContent.includes("Press Z to undo.")', true)
        ->assertScript('document.querySelector("'.$this->toast.' button[aria-keyshortcuts]").getAttribute("aria-keyshortcuts").includes("Z")', true);

    // Typing a z into the search box is not an undo.
    $page->click('main search [role=combobox]')->type('main search [role=combobox]', 'z')->wait(0.4)
        ->assertScript($this->shelfCount, 19);

    $page->keys(($this->tile)(2).' a', 'z')->wait(0.8)
        ->assertScript($this->shelfCount, 20)
        ->assertScript('document.querySelector("section[aria-labelledby=shelf-recommended] [data-recommendation=\"'.$id.'\"]") !== null', true)
        ->assertScript(($this->toastShown)(), false)
        ->assertNoJavaScriptErrors();

    expect(RecommendationEvent::where('event', 'dismiss')->count())->toBe(0);

    // With nothing to undo, Z does nothing.
    $page->keys(($this->tile)(2).' a', 'z')->wait(0.3)->assertScript($this->shelfCount, 20);
});

test('closing the toast while it has focus hands focus to the tile that took it, not to the page', function () {
    $page = ($this->settle)(visit(route('home'), $this->desktop)->wait(1));

    $page->hover(($this->tile)(2).' a')->click(($this->tile)(2).' [data-tile-menu]')->wait(0.2)
        ->click(($this->tile)(2).' [role=menuitem]')->wait(0.8);
    $page->script('document.querySelector("'.$this->toast.' button[aria-label=Close]").focus()');
    $page->keys($this->toast.' button[aria-label=Close]', 'Enter')->wait(0.4)
        ->assertScript(($this->toastShown)(), false)
        ->assertScript('document.activeElement.matches("section[aria-labelledby=shelf-recommended] ul > li:nth-child(2) a")', true)
        ->assertNoJavaScriptErrors();
});

test('two quick dismissals: Undo while the second tile is still leaving brings back the first, the toast\'s own tile', function () {
    $page = ($this->settle)(visit(route('home'), $this->desktop)->wait(1));
    $first = ($this->recommendation)(2);

    $page->hover(($this->tile)(2).' a')->click(($this->tile)(2).' [data-tile-menu]')->wait(0.2)
        ->click(($this->tile)(2).' [role=menuitem]')->wait(0.8)
        ->assertScript($this->shelfCount, 19);
    $page->script('window.__said = []; new MutationObserver(() => window.__said.push(document.querySelector("'.$this->toast.' span").textContent)).observe(document.querySelector("'.$this->toast.' span"), { childList: true, characterData: true, subtree: true });');

    // The second one is made, and Undo pressed, before the second tile has finished leaving.
    $second = (int) $page->script('(() => { const tile = document.querySelector("section[aria-labelledby=shelf-recommended] ul > li:nth-child(3)"); const button = tile.querySelector("[data-tile-menu]"); button.click(); tile.querySelector("[role=menuitem]").click(); [...document.querySelectorAll("'.$this->toast.' button")].find((b) => b.textContent.trim() === "Undo").click(); return button.dataset.tileMenu; })()');
    $page->wait(2)
        ->assertScript('document.querySelector("section[aria-labelledby=shelf-recommended] [data-recommendation=\"'.$first.'\"]") !== null', true)
        ->assertScript('document.querySelector("section[aria-labelledby=shelf-recommended] [data-recommendation=\"'.$second.'\"]")', null)
        ->assertScript($this->shelfCount, 19)
        // The second dismissal said the same message again, which a screen reader only repeats if the text was cleared in between.
        ->assertScript('window.__said.includes("")', true)
        ->assertNoJavaScriptErrors();

    expect(RecommendationEvent::where('event', 'dismiss')->pluck('recommendation_id')->all())->toBe([$second]);
});

test('with reduced motion the tile is gone at once', function () {
    $page = ($this->settle)(visit(route('home'), $this->desktop + ['reducedMotion' => 'reduce'])->wait(1));

    $page->hover(($this->tile)(2).' a')->click(($this->tile)(2).' [data-tile-menu]')->wait(0.2)
        ->click(($this->tile)(2).' [role=menuitem]')
        ->assertScript($this->shelfCount, 19)
        ->assertScript('getComputedStyle(document.querySelector("section[aria-labelledby=shelf-recommended] ul > li:nth-child(2)")).transitionDuration', '0s');
});

test('opening a recommended tile records a click without any parameter in the link', function () {
    $page = ($this->settle)(visit(route('home'), $this->desktop)->wait(1));
    $id = ($this->recommendation)(3);

    $page->assertScript('document.querySelectorAll("main a[href*=\"rec=\"]").length', 0)
        ->click(($this->tile)(3).' a')->wait(1.5);

    expect(RecommendationEvent::where('recommendation_id', $id)->where('event', 'click')->count())->toBe(1);

    $page->assertScript('location.search', '')->assertNoJavaScriptErrors();
});

test('tiles that scroll into view are reported once each, in a batch', function () {
    $page = ($this->settle)(visit(route('home'), $this->desktop)->wait(1));
    $page->script('window.scrollTo(0, 0)');
    $page->wait(0.5);
    $page->script('window.scrollTo(0, 700)');
    $page->wait(2.5);

    $counts = RecommendationEvent::where('event', 'impression')->selectRaw('recommendation_id, count(*) as n')->groupBy('recommendation_id')->pluck('n', 'recommendation_id');

    expect($counts)->not->toBeEmpty()->and($counts->max())->toBe(1);
});

test('Enter on a recommended tile records one click', function () {
    $page = ($this->settle)(visit(route('home'), $this->desktop)->wait(1));
    $id = ($this->recommendation)(3);

    $page->assertScript('document.querySelectorAll("main a[href*=\"rec=\"]").length', 0)
        ->keys(($this->tile)(3).' a', 'Enter')->wait(1.5);

    expect(RecommendationEvent::where('recommendation_id', $id)->where('event', 'click')->count())->toBe(1);

    $page->assertScript('location.pathname.startsWith("/stories/")', true)->assertNoJavaScriptErrors();
});
