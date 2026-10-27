<?php

use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    (new DeweySeeder)->run();

    $genre = Genre::factory()->create(['name' => 'Action', 'slug' => 'action']);

    $stories = Media::factory()->manga()->count(9)->sequence(fn ($sequence) => [
        'title' => 'Panel Tale '.($sequence->index + 1),
        'cover_url' => '/favicon.svg',
        'ratings_count' => 100 - $sequence->index,
        'published_on' => now()->subDays($sequence->index + 1),
    ])->create();

    foreach ($stories as $story) {
        $story->genres()->attach($genre);
    }

    classified($stories, '813');

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];
    $this->scrollDown = 'window.scrollTo(0, document.body.scrollHeight)';
});

test('home passes the shared checks on desktop and on a phone with its shelves loaded', function () {
    foreach ([$this->desktop, $this->phone] as $viewport) {
        $page = visit(route('home'), $viewport + ['reducedMotion' => 'reduce'])->wait(1);

        foreach ([0, 500, 1000, 2000] as $offset) {
            $page->script("window.scrollTo(0, {$offset})");
            $page->wait(0.6);
        }

        $page->script($this->scrollDown);
        $page->wait(1.5)
            ->assertSee('Trending Now')->assertSee('New Releases')->assertSee('Explore by Subject')
            ->assertScript('document.querySelectorAll("[data-shelf-skeleton]").length', 0)
            ->assertNoJavaScriptErrors()
            ->assertNoAccessibilityIssues();
    }
});

test('the hero sits flush under the header and every band spans the page, content on the header gutter', function () {
    $page = visit(route('home'), $this->desktop + ['reducedMotion' => 'reduce'])->wait(1);

    foreach ([0, 600, 1400] as $offset) {
        $page->script("window.scrollTo(0, {$offset})");
        $page->wait(0.8);
    }

    $page->script('window.scrollTo(0, 0)');
    $page->wait(0.5)
        ->assertScript('Math.abs(document.querySelector("main section[aria-roledescription=carousel]").getBoundingClientRect().top - document.querySelector("header").getBoundingClientRect().bottom) < 1.5', true)
        ->assertScript('Array.from(document.querySelectorAll("main section[aria-roledescription=carousel], main section[aria-labelledby^=shelf-]")).every((band) => { const r = band.getBoundingClientRect(); return r.left === 0 && Math.abs(r.width - document.documentElement.clientWidth) < 1; })', true)
        ->assertScript('(() => { const logo = document.querySelector("header a").getBoundingClientRect().left; return ["main section[aria-roledescription=carousel] h1", "main section[aria-labelledby=shelf-trending] h2", "main section[aria-labelledby=shelf-trending] ul li:first-child a", "main search label"].every((selector) => { const el = Array.from(document.querySelectorAll(selector)).find((node) => node.offsetParent !== null); return Math.abs(el.getBoundingClientRect().left - logo) < 1.5; }); })()', true);
});

test('every shelf track is contained to the header margins (plus the ring room), nothing shows in the side margins, and the fades are state-aware', function () {
    foreach ([$this->desktop, $this->phone] as $viewport) {
        $page = visit(route('home'), $viewport + ['reducedMotion' => 'reduce'])->wait(1);

        foreach ([0, 600, 1400] as $offset) {
            $page->script("window.scrollTo(0, {$offset})");
            $page->wait(0.8);
        }

        $page->script($this->scrollDown);
        $page->wait(1);

        $edges = '(() => {
            const header = document.querySelector("header");
            const style = getComputedStyle(header);
            const left = header.querySelector("a").getBoundingClientRect().left;
            const right = header.getBoundingClientRect().right - parseFloat(style.paddingRight);

            return { left, right };
        })()';

        // The track reaches 4px (the focus ring's room) past each gutter and pads it back in, so the tiles sit on the gutter.
        $page->assertScript("(() => { const ring = 4; const { left, right } = {$edges}; const tracks = Array.from(document.querySelectorAll('main section[aria-labelledby^=shelf-] ul[x-edge-fade]')); return tracks.length === 3 && tracks.every((track) => { const box = track.getBoundingClientRect(); return Math.abs(box.left - (left - ring)) < 1.5 && Math.abs(box.right - (right + ring)) < 1.5; }); })()", true)
            // The tiles that spill past a track are clipped to it: nothing is hit in either side margin of a track.
            ->assertScript("(() => { const { left, right } = {$edges}; return Array.from(document.querySelectorAll('main section[aria-labelledby^=shelf-] ul[x-edge-fade]')).every((track) => { const box = track.getBoundingClientRect(); const y = box.top + 60; return [left / 2, right + (window.innerWidth - right) / 2].every((x) => ! document.elementsFromPoint(x, y).some((el) => el.closest('ul') === track || el.closest('li') ?.parentElement === track)); }); })()", true)
            ->assertScript("(() => { const track = document.querySelector('main section[aria-labelledby=shelf-trending] ul'); return [getComputedStyle(track).getPropertyValue('--fade-start'), track.matches('[data-fade-start]'), track.matches('[data-fade-end]')].join('|'); })()", '0px|false|true');

        $page->script("document.querySelector('main section[aria-labelledby=shelf-trending] ul').scrollLeft = 400");
        $page->wait(0.8)->assertScript("document.querySelector('main section[aria-labelledby=shelf-trending] ul').matches('[data-fade-start]')", true);
    }
});

test('a hover preview opened on one shelf lands over the shelf beneath it, whole', function () {
    $page = visit(route('home'), ['viewport' => ['width' => 1440, 'height' => 1000]])->wait(1);

    foreach ([0, 600, 1400] as $offset) {
        $page->script("window.scrollTo(0, {$offset})");
        $page->wait(0.8);
    }

    $page->script('window.scrollTo(0, 560)');
    $page->wait(0.8);
    $page->hover('main section[aria-labelledby=shelf-trending] ul > li:nth-child(5) a.group')->wait(1.2)
        ->assertScript('(() => {
            const panel = document.querySelector("[data-story-card]:not([style*=\"display: none\"])");
            const box = panel.getBoundingClientRect();
            const below = document.querySelector("main section[aria-labelledby=shelf-new-releases]").getBoundingClientRect().top;
            const hit = document.elementFromPoint(box.left + box.width / 2, Math.min(box.bottom - 10, below + 40));

            return box.bottom > below && panel.contains(hit);
        })()', true);
});

test('home keeps its DOM light: one preview and one quick view per shelf, filled from the tile in use', function () {
    $page = visit(route('home'), $this->desktop)->wait(1);

    foreach ([0, 600, 1400, 2200] as $offset) {
        $page->script("window.scrollTo(0, {$offset})");
        $page->wait(0.8);
    }

    $shelves = 'document.querySelectorAll("main section[aria-labelledby=shelf-trending], main section[aria-labelledby=shelf-new-releases]").length';

    $page->assertScript('document.querySelectorAll("[data-story-preview]").length', 18)
        ->assertScript('document.querySelectorAll("dialog").length <= 3', true)
        // Nothing renders a summary until a tile asks for it, and then it is one per shelf, not one per tile.
        ->assertScript('document.querySelectorAll("[data-story-summary]").length', 0)
        ->assertScript('document.querySelectorAll("[data-story-card]").length', 2);

    $page->script('window.scrollTo(0, 560)');
    $page->wait(0.6);
    $page->hover('main section[aria-labelledby=shelf-trending] ul > li:nth-child(2) a.group')->wait(0.8)
        ->assertScript('document.querySelectorAll("[data-story-summary]").length <= '.$shelves, true)
        ->assertScript('document.querySelector("[data-story-card]:not([style*=\"display: none\"]) h2").textContent.trim()', 'Panel Tale 2');
});

test('lazy shelves swap in without shifting the page', function () {
    $page = visit(route('home'), $this->desktop)->wait(0.3);

    $page->script('window.__shift = 0; new PerformanceObserver((list) => list.getEntries().forEach((entry) => entry.hadRecentInput || (window.__shift += entry.value))).observe({ type: "layout-shift", buffered: true });');

    foreach ([400, 900, 1500, 2200] as $offset) {
        $page->script("window.scrollTo(0, {$offset})");
        $page->wait(1);
    }

    $page->assertScript('document.querySelectorAll("[data-shelf-skeleton]").length', 0)
        ->assertScript('window.__shift < 0.05', true);
});

test('with reduced motion every shelf tile is visible at once', function () {
    $page = visit(route('home'), $this->desktop + ['reducedMotion' => 'reduce'])->wait(1);

    $page->script('window.scrollTo(0, 700)');
    $page->wait(1.2)
        ->assertScript('Array.from(document.querySelectorAll("[x-motion-reveal] > *")).length > 0', true)
        ->assertScript('Array.from(document.querySelectorAll("[x-motion-reveal] > *")).every((el) => getComputedStyle(el).opacity === "1")', true);
});

test('going to Search and back keeps the hero and the shelves working', function () {
    $page = visit(route('home'), $this->desktop)->wait(1.5);

    $page->click('header [data-flux-navbar] a[href$="/search"]')->wait(1)->assertPathIs('/search')
        ->click('header [data-flux-navbar] a[href$="/home"]')->wait(1.5)->assertPathIs('/home')
        ->assertScript('document.querySelector("main section[aria-roledescription=carousel]") !== null', true)
        ->click('[data-hero-next]')->wait(1)
        ->assertScript('document.querySelector("main section p[aria-hidden]").textContent.replace(/\s+/g, " ").trim()', '02 / 05');

    $page->script('window.scrollTo(0, 800)');
    $page->wait(1);
    $page->script($this->scrollDown);
    $page->wait(1.5)->assertSee('Trending Now')->assertScript('document.querySelectorAll("[data-shelf-skeleton]").length', 0)->assertNoJavaScriptErrors();
});

test('Enter in the search panel opens the search with the typed text', function () {
    visit(route('home'), $this->desktop)->wait(1)
        ->click('main search [role=combobox]')->type('main search [role=combobox]', 'panel')->keys('main search [role=combobox]', 'Enter')->wait(1)
        ->assertPathIs('/search')
        ->assertQueryStringHas('q', 'panel')
        ->assertNoJavaScriptErrors();
});
