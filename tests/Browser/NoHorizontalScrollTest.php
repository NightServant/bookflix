<?php

use App\Models\ContentSource;
use App\Models\DuplicateCandidate;
use App\Models\ExternalReview;
use App\Models\Media;
use App\Models\Report;
use App\Models\Review;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Playwright;

/*
 * No page scrolls sideways at any common width. Each page is visited once and the live window is resized through every width, so the page also
 * survives being resized. The data carries the strings that break layouts: a 64-character unbroken title, a 90-character URL as an alternate title,
 * a 50-character unbroken series name and a 60-character email (reader, reviewer, and a row of the admin Users list).
 * A failing page names every width it overflows at and the widest element sticking out. Collects every failure of a page, not just the first.
 */

const NHS_WIDTHS = [[320, 640], [375, 667], [414, 896], [768, 1024], [1024, 768], [1280, 800], [1440, 900]];

const NHS_LONG_TITLE = 'Supercalifragilisticexpialidocious_Unbreakable_Title_0123456789ab';

const NHS_LONG_SERIES = 'Unbroken_Series_Name_Without_Any_Spaces_At_All_12345';

/** In the page: how far the document is wider than the window, and the unclipped element that reaches furthest right (or left). */
const NHS_PROBE = '(() => {
    const width = document.documentElement.clientWidth;
    // Something is clipped when an ancestor clips it AND the clip reaches it: an absolutely positioned box is clipped only by an ancestor that holds its containing block.
    const contained = (el) => {
        const absolute = getComputedStyle(el).position === "absolute";

        for (let parent = el.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
            const style = getComputedStyle(parent);

            if (style.position === "fixed") {
                return true;
            }

            if (/auto|scroll|hidden|clip/.test(style.overflowX) && (! absolute || (el.offsetParent !== null && parent.contains(el.offsetParent)))) {
                return true;
            }
        }

        return false;
    };
    const sticking = Array.from(document.querySelectorAll("body *"))
        .filter((el) => { const box = el.getBoundingClientRect(); const style = getComputedStyle(el); return box.width > 1 && style.position !== "fixed" && style.visibility !== "hidden" && (box.right > width + 0.5 || box.left < -0.5); })
        .sort((a, b) => b.getBoundingClientRect().right - a.getBoundingClientRect().right);
    // Prefer one nothing clips; when every one is inside a clipping box yet the page is still wider, name the clipped one that reaches furthest.
    const describe = (el) => el.tagName.toLowerCase() + "." + String(el.className).slice(0, 80) + " [" + (el.textContent || "").trim().replace(/\s+/g, " ").slice(0, 40) + "] right=" + Math.round(el.getBoundingClientRect().right) + (contained(el) ? " (clipped)" : "");
    const out = sticking.find((el) => ! contained(el)) || sticking[0];
    const others = sticking.filter((el) => el !== out).slice(0, 3).map(describe);

    return JSON.stringify({
        over: document.documentElement.scrollWidth - window.innerWidth,
        free: sticking.some((el) => ! contained(el)),
        el: out ? describe(out) + (others.length ? " | next: " + others.join(" | ") : "") : null,
    });
})()';

/**
 * The long strings, and the rows every page and admin list needs to show them.
 *
 * @return array{reader: User, story: Media, series: Series}
 */
function nhsSeed(): array
{
    $catalog = homeCatalog();
    $reader = User::factory()->create(['name' => 'Long Email Reader', 'email' => str_repeat('a', 48).'@example.com']);

    $story = Media::factory()->novel()->create([
        'title' => NHS_LONG_TITLE,
        'slug' => 'long-title-story',
        'alternative_titles' => ['https://example.com/'.str_repeat('a', 70)],
        'cover_url' => '/favicon.svg',
        'description' => 'A story with a long title.',
    ]);
    $story->refreshSearchText();

    $series = Series::factory()->create(['title' => NHS_LONG_SERIES, 'slug' => 'long-series-name']);
    $series->media()->attach($story, ['position' => 1]);
    $series->media()->attach($catalog->first(), ['position' => 2]);

    $review = Review::factory()->create(['media_id' => $story->id, 'user_id' => $reader->id]);
    Report::factory()->of($review)->create();
    Report::factory()->of(ExternalReview::factory()->create(['media_id' => $story->id]))->create();

    libraryEntry($reader, $story, 'reading', favorite: true);

    foreach ($catalog->take(5) as $item) {
        libraryEntry($reader, $item, 'reading');
    }

    collectionOf($reader, [$story, $catalog->first()], ['name' => 'Collection of the unbroken title', 'slug' => 'long-collection']);
    collectionOf(User::factory()->admin()->create(), [$story], ['name' => 'Staff pick with the unbroken title', 'is_editorial' => true]);
    DuplicateCandidate::factory()->create(['media_id' => $story->id]);
    ContentSource::factory()->for($story)->publicDomain()->ready()->create();
    classified($story, '813', status: 'pending');
    User::factory()->count(3)->create();

    return ['reader' => $reader, 'story' => $story, 'series' => $series];
}

// The layout is what is measured here, not how fast a page loads: a page that is slow to open on a busy machine must not fail the run (the suite default is 10s).
beforeEach(fn () => Playwright::setTimeout(30_000));
afterEach(fn () => Playwright::setTimeout(10_000));

dataset('nhs pages', [
    'guest landing' => ['guest', '/'],
    'guest login' => ['guest', '/login'],
    'guest register' => ['guest', '/register'],
    'guest forgot password' => ['guest', '/forgot-password'],

    'reader home' => ['reader', '/home'],
    'reader discover' => ['reader', '/discover'],
    'reader search' => ['reader', '/search?q=Supercalifragilisticexpialidocious'],
    'reader library' => ['reader', '/library'],
    'reader upload' => ['reader', '/library/upload'],
    'reader story' => ['reader', '/stories/long-title-story'],
    'reader series' => ['reader', '/series/long-series-name'],
    'reader explore' => ['reader', '/explore'],
    'reader explore shelf' => ['reader', '/explore/813'],
    'reader profile' => ['reader', '/profile'],
    'reader collections' => ['reader', '/collections'],
    'reader collection' => ['reader', '/collections/long-collection'],
    'reader history' => ['reader', '/history'],
    'reader assistant' => ['reader', '/assistant'],
    'reader settings profile' => ['reader', '/settings/profile'],
    'reader settings preferences' => ['reader', '/settings/preferences'],
    'reader settings privacy' => ['reader', '/settings/privacy'],
    'reader settings security' => ['reader', '/settings/security'],

    'admin dashboard' => ['admin', '/admin'],
    'admin providers' => ['admin', '/admin/providers'],
    'admin media' => ['admin', '/admin/media'],
    'admin media edit' => ['admin', '/admin/media/long-title-story'],
    'admin duplicates' => ['admin', '/admin/duplicates'],
    'admin classifications' => ['admin', '/admin/classifications'],
    'admin staff picks' => ['admin', '/admin/staff-picks'],
    'admin content' => ['admin', '/admin/content'],
    'admin models' => ['admin', '/admin/models'],
    'admin models compare' => ['admin', '/admin/models/compare'],
    'admin users' => ['admin', '/admin/users'],
    'admin reports' => ['admin', '/admin/reports'],
]);

test('no page scrolls sideways', function (string $role, string $path) {
    Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
    $seed = nhsSeed();

    if ($role === 'admin') {
        $this->actingAs(User::factory()->admin()->create());
        $path = str_replace('/admin/media/long-title-story', '/admin/media/'.$seed['story']->getRouteKey(), $path);
    } elseif ($role === 'reader') {
        $this->actingAs($seed['reader']);
    }

    [$width, $height] = NHS_WIDTHS[0];

    if ($path === '/settings/security') {
        // The security page asks for the password first; confirm it once, then go there.
        visit('/user/confirm-password', ['viewport' => ['width' => $width, 'height' => $height]])
            ->fill('password', 'password')->click('[data-test="confirm-password-button"]')->wait(1.5);
    }

    $page = visit($path, ['viewport' => ['width' => $width, 'height' => $height], 'reducedMotion' => 'reduce'])->wait(1.5);
    $failures = [];

    foreach (NHS_WIDTHS as [$width, $height]) {
        $page->resize($width, $height)->wait(0.4);
        $probe = json_decode((string) $page->script(NHS_PROBE), true);

        if (($probe['over'] ?? 0) > 0 || ($probe['free'] ?? false)) {
            $failures[] = "{$role} {$path} at {$width}: page is {$probe['over']}px too wide; widest element {$probe['el']}";
        }
    }

    expect($failures)->toBe([]);
})->with('nhs pages');
