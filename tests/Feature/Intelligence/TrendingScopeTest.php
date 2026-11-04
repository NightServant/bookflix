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
