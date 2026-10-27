<?php

use App\Models\Media;
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
    'profile settings' => fn () => route('profile.edit'),
    'privacy settings' => fn () => route('privacy.edit'),
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

test('story, series and class pages guide the reader right after the h1', function () {
    $this->seed(DeweySeeder::class);
    $media = Media::factory()->create();
    $user = User::factory()->create();

    expect(guideAfterH1($this->actingAs($user)->get(route('stories.show', $media->slug))->getContent()))->not->toBe('')
        ->and(guideAfterH1($this->actingAs($user)->get(route('explore.show', '800'))->getContent()))->not->toBe('');
});

test('the search page names the query in its h1 and shows the count once beside it', function () {
    Media::factory()->create(['title' => 'Love Hina']);

    $html = $this->actingAs(User::factory()->create())->get(route('search', ['q' => 'Love']))->getContent();

    expect(trim(htmlQuery($html, '//h1')->item(0)->textContent))->toBe('Results for “Love”')
        ->and(htmlQuery($html, '//header[.//h1]//*[@data-test="result-count"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@data-test="result-count"]')->length)->toBe(1);
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
