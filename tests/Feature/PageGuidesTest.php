<?php

use App\Models\Media;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\DeweySeeder;

/**
 * Every routed page opens with an h1 and a plain guiding line right after it (redesign 4).
 * The guide is the paragraph that follows the h1 in document order and is never empty.
 */
function guideAfterH1(string $html): string
{
    $paragraph = htmlQuery($html, '(//h1)[1]/following::*[self::p or @data-flux-subheading][1]');

    return $paragraph->length ? trim(preg_replace('/\s+/', ' ', $paragraph->item(0)->textContent)) : '';
}

dataset('signed in pages', [
    'discover' => fn () => route('discover'),
    'explore' => fn () => route('explore.index'),
    'search' => fn () => route('search'),
    'library' => fn () => route('library.index'),
    'history' => fn () => route('history.index'),
    'profile settings' => fn () => route('profile.edit'),
    'privacy settings' => fn () => route('privacy.edit'),
    'preferences settings' => fn () => route('preferences.edit'),
    'onboarding' => fn () => route('onboarding'),
]);

test('a signed-in page guides the reader right after its h1', function (string $url) {
    $html = $this->actingAs(User::factory()->create())->get($url)->assertOk()->getContent();

    expect(htmlQuery($html, '//h1')->length)->toBe(1)
        ->and(guideAfterH1($html))->not->toBe('');
})->with('signed in pages');

dataset('admin pages', [
    'index' => fn () => route('admin.index'),
    'providers' => fn () => route('admin.providers'),
    'media' => fn () => route('admin.media.index'),
    'duplicates' => fn () => route('admin.duplicates'),
    'classifications' => fn () => route('admin.classifications'),
    'users' => fn () => route('admin.users'),
]);

test('an admin page guides the reader right after its h1', function (string $url) {
    $html = $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk()->getContent();

    expect(htmlQuery($html, '//h1')->length)->toBe(1)
        ->and(guideAfterH1($html))->not->toBe('');
})->with('admin pages');

test('the media edit page guides the reader after its h1', function () {
    $html = $this->actingAs(User::factory()->admin()->create())->get(route('admin.media.edit', Media::factory()->create()))->assertOk()->getContent();

    expect(guideAfterH1($html))->not->toBe('');
});

test('the story and series guides follow the title facts instead of splitting them from the h1', function () {
    $media = Media::factory()->create(['alternative_titles' => ['Another Name']]);
    $series = Series::factory()->create();
    $media->series()->attach($series, ['position' => 1]);
    $user = User::factory()->create();

    $story = $this->actingAs($user)->get(route('stories.show', $media->slug))->getContent();
    $page = $this->actingAs($user)->get(route('series.show', $series->slug))->getContent();

    expect(htmlQuery($story, '//h1/following::p[1][contains(., "Also known as")]')->length)->toBe(1)
        ->and(htmlQuery($story, '//p[contains(., "Also known as")]/following::p[contains(., "Read the summary")]')->length)->toBe(1)
        ->and(htmlQuery($page, '//h1/following::p[1][contains(., "1 volume")]')->length)->toBe(1)
        ->and(htmlQuery($page, '//p[contains(., "1 volume")]/following::p[contains(., "Every volume in this series")]')->length)->toBe(1);
});

test('story, series and class pages guide the reader right after the h1', function () {
    $this->seed(DeweySeeder::class);
    $media = Media::factory()->create();
    $user = User::factory()->create();

    expect(guideAfterH1($this->actingAs($user)->get(route('stories.show', $media->slug))->getContent()))->not->toBe('')
        ->and(guideAfterH1($this->actingAs($user)->get(route('explore.show', '800'))->getContent()))->not->toBe('');
});

test('the search page names the query in its h1 and shows the count once, above its results', function () {
    Media::factory()->create(['title' => 'Love Hina']);

    $html = $this->actingAs(User::factory()->create())->get(route('search', ['q' => 'Love']))->getContent();

    expect(trim(htmlQuery($html, '//h1')->item(0)->textContent))->toBe('Results for “Love”')
        ->and(htmlQuery($html, '//header[.//h1]//*[@data-test="result-count"]')->length)->toBe(0)
        ->and(htmlQuery($html, '//*[@data-test="result-count"]')->length)->toBe(1);
});

test('text too short to search leaves the page titled Search, and a long query is clamped in the h1', function () {
    $user = User::factory()->create();

    $short = $this->actingAs($user)->get(route('search', ['q' => 'a']))->getContent();
    $long = $this->actingAs($user)->get(route('search', ['q' => str_repeat('dragon ', 40)]))->getContent();

    expect(trim(htmlQuery($short, '//h1')->item(0)->textContent))->toBe('Search')
        ->and(htmlQuery($long, '//h1[contains(@class,"line-clamp-2")][contains(@class,"break-words")]')->length)->toBe(1);
});

test('an empty search is titled Search', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('search'))->getContent();

    expect(trim(htmlQuery($html, '//h1')->item(0)->textContent))->toBe('Search');
});

dataset('auth pages', [
    'login' => '/login',
    'register' => '/register',
    'forgot password' => '/forgot-password',
    'reset password' => '/reset-password/token?email=a@b.test',
]);

test('an auth page says what happens next', function (string $url) {
    $html = $this->get($url)->assertOk()->getContent();

    expect(htmlQuery($html, '//h1')->length)->toBe(1)
        ->and(strlen(guideAfterH1($html)))->toBeGreaterThan(40);
})->with('auth pages');

test('verify email and confirm password pages guide the reader', function () {
    $unverified = User::factory()->unverified()->create();

    expect(guideAfterH1($this->actingAs($unverified)->get('/email/verify')->getContent()))->not->toBe('');

    $html = $this->actingAs(User::factory()->create())->get('/user/confirm-password')->getContent();
    expect(guideAfterH1($html))->not->toBe('');
});

test('a shelf shows its one-line helper under the title only when given one', function () {
    $with = renderedComponent('<x-shelf title="Trending Now" description="The most popular stories."><li>One</li></x-shelf>');
    $without = renderedComponent('<x-shelf title="Trending Now"><li>One</li></x-shelf>');

    expect(htmlQuery($with, '//h2[normalize-space()="Trending Now"]/following-sibling::p[normalize-space()="The most popular stories."]')->length)->toBe(1)
        ->and(htmlQuery($without, '//h2/following-sibling::p')->length)->toBe(0);
});
