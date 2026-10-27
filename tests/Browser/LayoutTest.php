<?php

use App\Models\Media;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\DeweySeeder;

test('contained pages and the full-bleed ones start their content on the header gutter, at 1440 and on a phone', function () {
    $this->actingAs(User::factory()->create());
    (new DeweySeeder)->run();

    $story = Media::factory()->novel()->create(['title' => 'Aligned Story', 'cover_url' => '/favicon.svg']);
    $series = Series::factory()->create();
    $series->media()->attach($story, ['position' => 1]);

    // The first thing in the page's own content, per page: the container's content box, or for a flush page the band's own padded container or its heading.
    $pages = [
        'search' => [route('search'), 'main > div'],
        'discover' => [route('discover'), 'main h1'],
        'explore' => [route('explore.index'), 'main h1'],
        'story' => [route('stories.show', $story->slug), 'main header > div.px-gutter'],
        'profile' => [route('profile.edit'), 'main > div'],
        'series' => [route('series.show', $series->slug), 'main header > div.px-gutter'],
        'home' => [route('home'), 'main h1'],
    ];

    foreach ([['width' => 1440, 'height' => 900], ['width' => 375, 'height' => 812]] as $viewport) {
        foreach ($pages as [$url, $selector]) {
            visit($url, ['viewport' => $viewport, 'reducedMotion' => 'reduce'])->wait(1)
                ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
                ->assertScript("(() => {
                    const logo = document.querySelector('header a').getBoundingClientRect().left;
                    const el = Array.from(document.querySelectorAll('{$selector}')).find((node) => node.offsetParent !== null);
                    const start = el.getBoundingClientRect().left + (el.matches('h1') ? 0 : parseFloat(getComputedStyle(el).paddingLeft));

                    return Math.abs(start - logo) < 1.5;
                })()", true);
        }
    }
});

test('the first Tab reaches the skip link and Enter moves focus to main, before and after wire:navigate', function () {
    $this->actingAs(User::factory()->create());

    $skip = 'document.activeElement.textContent.trim() === "Skip to content"';
    $page = visit(route('discover'), ['reducedMotion' => 'reduce'])->wait(1);

    $page->keys(':root', 'Tab')
        ->assertScript($skip, true)
        ->keys(':focus', 'Enter')
        ->assertScript('document.activeElement.id', 'main');

    $page->click('header nav a[href$="/home"]')->wait(1.5)
        ->assertPathBeginsWith('/home')
        ->keys(':root', 'Tab')
        ->assertScript($skip, true)
        ->keys(':focus', 'Enter')
        ->assertScript('document.activeElement.id', 'main');
});

test('a long account name never pushes the header past a phone screen', function () {
    $this->actingAs(User::factory()->create(['name' => 'Maximiliana Wolfeschlegelsteinhausen-Bergerdorff']));

    visit(route('discover'), ['viewport' => ['width' => 375, 'height' => 812], 'reducedMotion' => 'reduce'])->wait(1)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript('document.querySelector("header [data-flux-sidebar-profile]").getBoundingClientRect().right <= window.innerWidth', true)
        ->assertAriaAttribute('header [data-flux-sidebar-profile]', 'label', 'Account menu for Maximiliana Wolfeschlegelsteinhausen-Bergerdorff');
});
