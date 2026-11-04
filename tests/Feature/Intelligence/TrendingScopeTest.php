<?php

use App\Enums\InteractionEvent;
use App\Models\Media;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Support\Facades\DB;

/**
 * Views by a (new) reader, an hour ago, then a fresh `media_popularity`.
 */
function viewed(Media $media, int $count): void
{
    UserInteraction::factory()->count($count)->create([
        'user_id' => User::factory()->create()->id,
        'media_id' => $media->id,
        'event' => InteractionEvent::View,
        'occurred_at' => now()->subHour(),
    ]);
}

function refreshPopularity(): void
{
    DB::statement('refresh materialized view media_popularity');
}

test('with no interactions trending keeps the provider order and its tie-breakers', function () {
    [$a, $b, $c, $d] = Media::factory()->count(4)->sequence(
        ['popularity_score' => 0.2, 'ratings_count' => 900],
        ['popularity_score' => 0.9, 'ratings_count' => 1],
        ['popularity_score' => 0.2, 'ratings_count' => 950],
        ['popularity_score' => 0.2, 'ratings_count' => 950, 'published_on' => '2020-01-01'],
    )->create();
    refreshPopularity();

    // The newest of two equally rated stories first; a story without a date after the dated one.
    expect(Media::trending()->pluck('media.id')->all())->toBe([$b->id, $d->id, $c->id, $a->id]);
});

test('trending lists media that have no row in the view yet', function () {
    $media = Media::factory()->create(['popularity_score' => 0.4]);

    expect(DB::table('media_popularity')->where('media_id', $media->id)->exists())->toBeFalse()
        ->and(Media::trending()->pluck('media.id')->all())->toBe([$media->id]);
});

test('a handful of views never lifts a story above the most popular', function () {
    $favourite = Media::factory()->create(['popularity_score' => 0.9]);
    $newcomer = Media::factory()->create(['popularity_score' => 0.1]);
    $other = Media::factory()->create(['popularity_score' => 0.1]);
    viewed($newcomer, 3);
    viewed($other, 1);
    refreshPopularity();

    $order = Media::trending()->pluck('media.id')->all();

    expect($order[0])->toBe($favourite->id)
        ->and(config('bookflix.recommendations.trending_weight'))->toBeLessThanOrEqual(0.1);
});

test('among stories of equal provider popularity, Bookflix activity decides', function () {
    [$quiet, $busy, $busiest] = Media::factory()->count(3)->sequence(
        ['popularity_score' => 0.5, 'ratings_count' => 99],
        ['popularity_score' => 0.5, 'ratings_count' => 1],
        ['popularity_score' => 0.5, 'ratings_count' => 1],
    )->create();
    viewed($busy, 2);
    viewed($busiest, 6);
    refreshPopularity();

    expect(Media::trending()->pluck('media.id')->all())->toBe([$busiest->id, $busy->id, $quiet->id]);
});

test('activity can reorder close provider scores but not distant ones', function () {
    [$ahead, $behind, $far] = Media::factory()->count(3)->sequence(
        ['popularity_score' => 0.52],
        ['popularity_score' => 0.50],
        ['popularity_score' => 0.30],
    )->create();
    viewed($behind, 5);
    viewed($far, 5);
    viewed($ahead, 1);
    refreshPopularity();

    // $behind and $far are equally busy; $behind (0.50 + 0.05) passes $ahead (0.52 + about 0.02), $far (0.30 + 0.05) stays behind both.
    expect(Media::trending()->pluck('media.id')->all())->toBe([$behind->id, $ahead->id, $far->id]);
});

test('soft deleted and merged media stay out of trending', function () {
    $kept = Media::factory()->create(['popularity_score' => 0.1]);
    $deleted = Media::factory()->create(['popularity_score' => 0.9]);
    $merged = Media::factory()->create(['popularity_score' => 0.8]);
    viewed($deleted, 3);
    viewed($merged, 3);
    $deleted->delete();
    $merged->forceFill(['merged_into_id' => $kept->id])->save();
    refreshPopularity();

    expect(Media::trending()->pluck('media.id')->all())->toBe([$kept->id]);
});

test('interactions of opted-out and deactivated readers add nothing', function () {
    [$a, $b] = Media::factory()->count(2)->sequence(['popularity_score' => 0.5, 'ratings_count' => 9], ['popularity_score' => 0.5, 'ratings_count' => 1])->create();
    $gone = User::factory()->create(['deactivated_at' => now()]);
    UserInteraction::factory()->count(5)->create(['user_id' => $gone->id, 'media_id' => $b->id, 'event' => InteractionEvent::View, 'occurred_at' => now()]);
    refreshPopularity();

    expect(Media::trending()->pluck('media.id')->all())->toBe([$a->id, $b->id]);
});

test('with a limit the narrowed query gives the same first rows as the full blend, filters included', function () {
    // Provider scores close together, some stories busy, a few with no cover: the narrowing must not change who is first.
    $media = Media::factory()->count(60)->sequence(fn ($i) => [
        'popularity_score' => round(0.9 - $i->index * 0.012, 3),
        'ratings_count' => 100 - $i->index,
        'cover_url' => $i->index % 7 === 0 ? null : 'https://example.test/c.jpg',
    ])->create();
    foreach ([3 => 4, 11 => 9, 25 => 2, 40 => 12, 55 => 30, 58 => 1] as $index => $views) {
        viewed($media[$index], $views);
    }
    refreshPopularity();

    foreach ([1, 5, 12, 20, 80] as $limit) {
        $full = Media::trending()->whereNotNull('media.cover_url')->limit($limit)->pluck('media.id')->all();
        $narrow = Media::trending($limit)->whereNotNull('media.cover_url')->limit($limit)->pluck('media.id')->all();
        $narrowFirst = Media::query()->whereNotNull('media.cover_url')->trending($limit)->limit($limit)->pluck('media.id')->all();

        expect($narrow)->toBe($full, "limit {$limit}")->and($narrowFirst)->toBe($full, "limit {$limit}");
    }
});

test('with a limit a busy story far down the provider order is still considered', function () {
    $media = Media::factory()->count(30)->sequence(fn ($i) => ['popularity_score' => 0.9 - $i->index * 0.02])->create();
    viewed($media[29], 50);
    refreshPopularity();

    // 0.32 + 0.05 stays under the tenth place (0.72), so it is not among ten, but is in the narrowed set: the order is the same as the full one.
    expect(Media::trending(10)->limit(10)->pluck('media.id')->all())->toBe(Media::trending()->limit(10)->pluck('media.id')->all());
});

test('a narrowed trending query still takes a page of rows', function () {
    Media::factory()->count(5)->sequence(fn ($i) => ['popularity_score' => 0.5 - $i->index * 0.1])->create();
    refreshPopularity();

    expect(Media::trending(3)->limit(3)->get())->toHaveCount(3)->and(Media::trending(3)->limit(2)->offset(1)->get())->toHaveCount(2);
});
