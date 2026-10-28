<?php

use App\Discovery\LandingShowcase;
use App\Models\Media;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Stories with a cover, ranked by popularity: the first has the highest score.
 */
function popularStories(int $count, array $overrides = []): array
{
    return Media::factory()->count($count)->sequence(fn ($sequence) => [
        'title' => 'Popular Tale '.($sequence->index + 1),
        'cover_url' => 'https://s4.anilist.co/file/cover-'.($sequence->index + 1).'.png',
        'popularity_score' => 1 - ($sequence->index / 100),
        ...$overrides,
    ])->create()->all();
}

beforeEach(fn () => Cache::flush());

test('the pool holds the forty most popular stories that have a cover, most popular first', function () {
    popularStories(45);
    Media::factory()->create(['title' => 'No Cover Hit', 'cover_url' => null, 'popularity_score' => 5]);

    $pool = app(LandingShowcase::class)->pool();

    expect($pool)->toHaveCount(LandingShowcase::POOL)
        ->and($pool[0]['title'])->toBe('Popular Tale 1')
        ->and($pool[39]['title'])->toBe('Popular Tale 40')
        ->and(collect($pool)->pluck('title'))->not->toContain('No Cover Hit');
});

test('merged, deleted and adult stories stay out of the pool', function () {
    $stories = popularStories(4);
    $stories[0]->update(['merged_into_id' => $stories[3]->id]);
    $stories[1]->delete();
    $stories[2]->update(['age_rating' => 'adult']);

    expect(collect(app(LandingShowcase::class)->pool())->pluck('title')->all())->toBe(['Popular Tale 4']);
});

test('a pool entry carries what a book needs and a relative proxy path for the scene', function () {
    [$story] = popularStories(1);

    expect(app(LandingShowcase::class)->pool()[0])->toBe([
        'id' => $story->id,
        'title' => 'Popular Tale 1',
        'cover' => 'https://s4.anilist.co/file/cover-1.png',
        'scene' => route('covers.show', ['media' => $story->id, 'v' => substr(sha1('https://s4.anilist.co/file/cover-1.png'), 0, 8)], false),
    ])->and($story->coverProxyUrl())->toBe(url(app(LandingShowcase::class)->pool()[0]['scene']));
});

test('the top ten is the first ten of the pool in popularity order', function () {
    popularStories(15);

    expect(collect(app(LandingShowcase::class)->topTen())->pluck('title')->all())->toBe(array_map(fn (int $n): string => "Popular Tale {$n}", range(1, 10)));
});

test('a random pick comes from the pool without repeats, and varies between calls', function () {
    popularStories(40);
    $showcase = app(LandingShowcase::class);

    $pick = $showcase->random(16);

    expect($pick)->toHaveCount(16)
        ->and(collect($pick)->pluck('id')->unique())->toHaveCount(16)
        ->and(collect($pick)->pluck('id')->diff(collect($showcase->pool())->pluck('id')))->toBeEmpty();

    $orders = collect(range(1, 5))->map(fn () => collect($showcase->random(16))->pluck('id')->implode(','))->unique();

    expect($orders->count())->toBeGreaterThan(1);
});

test('a small catalog repeats its covers rather than leaving a hole, and an empty one gives nothing', function () {
    expect(app(LandingShowcase::class)->random(7))->toBe([]);

    Cache::flush();
    popularStories(3);

    expect(app(LandingShowcase::class)->random(7))->toHaveCount(7);
});

test('the pool costs one query when cold and none when warm', function () {
    popularStories(12);
    $showcase = app(LandingShowcase::class);

    DB::enableQueryLog();
    $showcase->random(7);
    $showcase->topTen();
    expect(DB::getQueryLog())->toHaveCount(1);

    DB::flushQueryLog();
    $showcase->random(7);
    $showcase->topTen();
    expect(DB::getQueryLog())->toBeEmpty();
});
