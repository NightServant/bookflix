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
        'discover' => [route('discover'), 'main > div'],
        'explore' => [route('explore.index'), 'main > div'],
        'story' => [route('stories.show', $story->slug), 'main > div'],
        'profile' => [route('profile.edit'), 'main > div'],
        'series' => [route('series.show', $series->slug), 'main header > div[x-data]'],
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
