<?php

use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;

/*
 * Page-level smoke tests for Phase 4: the discovery pages used together, the way a reader meets them (real layout, real sign-in,
 * real navigation, the keyboard). What each page and component does on its own is in the tests beside this file; the audit of
 * the states they don't visit is AccessibilityTest.
 */

beforeEach(function () {
    (new DeweySeeder)->run();

    $this->user = User::factory()->create(['name' => 'Gabe Reader']);
    $this->story = fullMedia();

    $action = Genre::factory()->create(['name' => 'Action', 'slug' => 'action']);
    $stories = Media::factory()->manga()->count(9)->sequence(fn ($sequence) => [
        'title' => 'Panel Tale '.($sequence->index + 1),
        'cover_url' => '/favicon.svg',
        'ratings_count' => 100 - $sequence->index,
        'published_on' => now()->subDays($sequence->index + 1),
    ])->create();

    foreach ($stories as $story) {
        $story->genres()->attach($action);
    }

    classified($stories, '813');
    Media::all()->each->refreshSearchText();

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];

    // Relative, because `route()` here still points at APP_URL (the browser server aligns it just before the test body runs) and a full URL is visited as given.
    $this->series = $this->story->series()->first();
    $this->paths = [
        'home' => route('home', absolute: false),
        'search' => route('search', ['q' => 'dune'], false),
        'story' => route('stories.show', $this->story->slug, false),
        'series' => route('series.show', $this->series->slug, false),
        'discover' => route('discover', absolute: false),
        'explore' => route('explore.index', absolute: false),
        'explore class' => route('explore.show', '800', false),
    ];

    // The element that has focus, named like a screen reader would: its label or visible text, minus the aria-hidden bits (the ⌘K / Ctrl K hint).
    $this->focused = '(() => {
        const el = document.activeElement;

        if (el.tagName === "INPUT") {
            return "input:" + (el.getAttribute("role") || el.type);
        }

        const copy = el.cloneNode(true);
        copy.querySelectorAll("[aria-hidden=true]").forEach((node) => node.remove());

        return el.tagName.toLowerCase() + ":" + (el.getAttribute("aria-label") || copy.textContent).replace(/\s+/g, " ").trim();
    })()';
    $this->paletteOpen = 'document.querySelector("[data-palette-panel]").offsetParent !== null';
    $this->paletteInput = '[data-palette-panel] [role=combobox]';
    $this->paletteTrigger = 'header [aria-haspopup=dialog]';
    $this->trending = 'main section[aria-labelledby=shelf-trending] ul';

    // Elements that stick out past the viewport's sides and aren't inside something that scrolls or clips: what makes a page scroll sideways.
    $this->sticksOut = '(() => {
        const width = document.documentElement.clientWidth;
        const contained = (el) => {
            for (let parent = el.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
                const style = getComputedStyle(parent);

                if (/auto|scroll|hidden|clip/.test(style.overflowX) || style.position === "fixed") {
                    return true;
                }
            }

            return false;
        };

        return Array.from(document.querySelectorAll("body *"))
            .filter((el) => { const box = el.getBoundingClientRect(); const style = getComputedStyle(el); return box.width > 1 && style.position !== "fixed" && style.visibility !== "hidden" && (box.right > width + 0.5 || box.left < -0.5) && ! contained(el); })
            .slice(0, 5).map((el) => el.tagName.toLowerCase() + "." + String(el.className).slice(0, 50)).join(" ") + "|" + (document.documentElement.scrollWidth <= window.innerWidth);
    })()';
});

test('landing to login to home to search to a story works on desktop and on a phone', function (int $width, int $height) {
    $search = $width > 800 ? 'header [data-flux-navbar] a[href$="/search"]' : 'nav[aria-label=Primary] a[href$="/search"]';

    $page = visit(route('landing'), ['viewport' => ['width' => $width, 'height' => $height]])
        ->click('Sign in')->assertPathIs('/login')
        ->fill('email', $this->user->email)->fill('password', 'password')
        ->press('Log in')->wait(1.5)
        ->assertPathIs('/home')->assertSee('Start reading');

    scrolledThrough($page);
    $page->assertSee('Trending Now')
        ->click($search)->wait(1)->assertPathIs('/search')
        ->keys('search input[role=combobox]', str_split('messiah'))->wait(1.5)
        ->assertSee('Dune Messiah')
        ->click('Dune Messiah')->wait(1.5)
        ->assertPathBeginsWith('/stories/dune-messiah')
        ->assertSee('Dune Messiah')
        ->assertScript($this->sticksOut, '|true')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
})->with([[1440, 900], [390, 844]]);

test('Tab walks Home in reading order: header, hero, search panel, then the first shelf', function () {
    $this->actingAs($this->user);

    $page = visit($this->paths['home'], $this->desktop)->wait(1.5);
    $page->script('document.querySelector("header a").focus()');

    $stops = [];

    foreach (range(1, 19) as $stop) {
        $stops[] = $page->script($this->focused);
        $page->keys(':focus', 'Tab');
    }

    expect($stops)->toBe([
        'a:Bookflix', 'a:Home', 'a:Discover', 'a:Search', 'a:Profile', 'button:Search', 'button:GR Gabe Reader',
        'a:Start reading', 'button:Add to Library', 'button:Show Panel Tale 2', 'button:Show Panel Tale 3', 'button:Show Panel Tale 4', 'button:Previous slide', 'button:Next slide',
        'input:combobox', 'a:Browse Discover',
        'a:See all in Trending Now', 'button:Next in Trending Now', 'a:Dune',
    ]);
});

test('the keyboard alone finds a story: Command-K from the page, type, Enter, and the palette works again on the next page', function () {
    $this->actingAs($this->user);

    $page = visit($this->paths['home'], $this->desktop)->wait(1.5);

    $page->keys('[data-hero-next]', 'Meta+k')->wait(0.6)
        ->assertScript($this->paletteOpen, true)
        ->assertScript('document.activeElement === document.querySelector("[data-palette-panel] [role=combobox]")', true)
        ->keys($this->paletteInput, str_split('messiah'))->wait(1.2)
        ->keys($this->paletteInput, ['ArrowDown', 'Enter'])->wait(1.5)
        ->assertPathBeginsWith('/stories/dune-messiah')
        ->assertSee('Dune Messiah')
        ->assertScript($this->paletteOpen, false);

    $page->keys($this->paletteTrigger, 'Control+k')->wait(0.6)
        ->assertScript($this->paletteOpen, true)
        ->keys($this->paletteInput, 'Escape')->wait(0.6)
        ->assertScript($this->paletteOpen, false)
        ->assertScript('document.activeElement === document.querySelector("header [aria-haspopup=dialog]")', true)
        ->assertNoJavaScriptErrors();
});

test('a reader can wire:navigate across every page and back with the palette, tabs and shelf arrows still working', function () {
    $this->actingAs($this->user);

    $page = visit($this->paths['home'], $this->desktop)->wait(1.5);

    $page->click('header [data-flux-navbar] a[href$="/discover"]')->wait(1)->assertPathIs('/discover')->assertSee('Browse by format')
        ->click('Explore the Library')->wait(1)->assertPathIs('/explore')->assertSee('Browse by subject')
        ->click('a[href$="/explore/800"] >> nth=0')->wait(1)->assertPathIs('/explore/800')->assertSee('American Literature')
        ->click('a[href$="/stories/dune"]')->wait(1.2)->assertPathIs('/stories/dune')->assertSee('Part of')
        ->click('Dune Chronicles')->wait(1)->assertPathIs('/series/'.$this->series->slug)->assertSee('Volumes')
        ->assertNoJavaScriptErrors();

    // Back through the history restores the story page whole: its tabs still switch.
    $page->script('history.back()');
    $page->wait(1.2)->assertPathIs('/stories/dune')
        ->click('[role=tab]:has-text("Series")')->wait(0.5)
        ->assertScript('Array.from(document.querySelectorAll("[role=tab]")).filter((tab) => tab.getAttribute("aria-selected") === "true").map((tab) => tab.textContent.trim()).join("|")', 'Series')
        ->assertNoJavaScriptErrors();

    // Home again by its logo: the hero, the lazy shelves, the palette and a shelf's arrows all work on a page that arrived by navigation.
    $page->click('header a[href$="/home"] >> nth=0')->wait(1.5)->assertPathIs('/home')
        ->click('[data-hero-next]')->wait(1)
        ->assertScript('document.querySelector("main section p[aria-hidden]").textContent.replace(/\s+/g, " ").trim()', '02 / 05');

    scrolledThrough($page);
    $page->script('document.querySelector("main section[aria-labelledby=shelf-trending]").scrollIntoView()');
    $page->wait(0.6)
        ->assertScript('document.querySelectorAll("[data-shelf-skeleton]").length', 0)
        ->assertScript("document.querySelector('{$this->trending}').scrollLeft", 0)
        ->keys('[aria-label="Next in Trending Now"]', 'Enter')->wait(1.2)
        ->assertScript("document.querySelector('{$this->trending}').scrollLeft > 100", true)
        ->keys($this->paletteTrigger, 'Meta+k')->wait(0.6)
        ->assertScript($this->paletteOpen, true)
        ->assertNoJavaScriptErrors();
});

test('with reduced motion nothing on a page keeps animating or waits to be revealed', function (string $name) {
    $this->actingAs($this->user);

    $page = visit($this->paths[$name], $this->desktop + ['reducedMotion' => 'reduce'])->wait(1);
    scrolledThrough($page);

    $page->assertScript('matchMedia("(prefers-reduced-motion: reduce)").matches', true)
        ->assertScript('document.getAnimations().filter((animation) => animation.playState === "running").map((animation) => (animation.animationName || animation.transitionProperty) + ":" + animation.effect.target.tagName.toLowerCase() + "." + String(animation.effect.target.className.baseVal ?? animation.effect.target.className).slice(0, 40)).join(",")', '')
        ->assertScript('Array.from(document.querySelectorAll("[x-motion-reveal] > *")).every((el) => getComputedStyle(el).opacity === "1")', true)
        ->assertScript('getComputedStyle(document.documentElement).scrollBehavior', 'auto')
        ->assertNoJavaScriptErrors();
})->with(['home', 'search', 'story', 'series', 'discover', 'explore', 'explore class']);

test('on a phone every page settles with nothing sticking out sideways, so the page never scrolls sideways', function (string $name) {
    $this->actingAs($this->user);

    $page = visit($this->paths[$name], $this->phone)->wait(1);
    scrolledThrough($page);
    $page->assertScript($this->sticksOut, '|true')->assertNoJavaScriptErrors();

    if ($name === 'story') {
        $page->click('summary:has-text("Content warnings")')->wait(0.5)->assertScript($this->sticksOut, '|true')
            ->click('[role=tab]:has-text("Series")')->wait(0.5)->assertScript($this->sticksOut, '|true')
            ->click('[role=tab]:has-text("Related")')->wait(0.5)->assertScript($this->sticksOut, '|true');
    }

    if ($name === 'search') {
        $page->click('List')->wait(1.5)->assertScript($this->sticksOut, '|true');
    }
})->with(['home', 'search', 'story', 'series', 'discover', 'explore', 'explore class']);
