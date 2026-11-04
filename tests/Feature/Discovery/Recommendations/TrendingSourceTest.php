<?php

use App\Discovery\Recommendations\Sources\TrendingSource;
use App\Enums\InteractionEvent;
use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Support\Facades\DB;

test('scores follow the trending blend, scaled to one by the best', function () {
    $user = User::factory()->create();
    [$low, $top] = Media::factory()->count(2)->sequence(['popularity_score' => 0.2], ['popularity_score' => 0.8])->create();
    DB::statement('refresh materialized view media_popularity');

    $c = app(TrendingSource::class)->candidatesFor($user, 10);

    expect($c->pluck('mediaId')->all())->toBe([$top->id, $low->id])
        ->and($c->first()->score)->toBe(1.0)
        ->and($c->last()->score)->toEqualWithDelta(0.2 / 0.8, 1e-9)
        ->and($c->first()->reason)->toBe(ReasonType::Trending)
        ->and($c->first()->source)->toBe('trending');
});

test('with zero interactions and no popularity data it still returns candidates, ranked by position', function () {
    $user = User::factory()->create();
    Media::factory()->count(3)->sequence(['ratings_count' => 5], ['ratings_count' => 50], ['ratings_count' => 20])->create();
    DB::statement('refresh materialized view media_popularity');

    $c = app(TrendingSource::class)->candidatesFor($user, 10);

    expect($c)->toHaveCount(3)
        ->and($c->pluck('score')->all())->toEqualWithDelta([1.0, 2 / 3, 1 / 3], 1e-9);
});

test('a few interactions do not lift a story over a far more popular one', function () {
    $user = User::factory()->create();
    [$popular, $quiet] = Media::factory()->count(2)->sequence(['popularity_score' => 0.9], ['popularity_score' => 0.2])->create();
    $reader = User::factory()->create();
    UserInteraction::factory()->count(3)->create(['user_id' => $reader->id, 'media_id' => $quiet->id, 'event' => InteractionEvent::View, 'occurred_at' => now()->subHour()]);
    DB::statement('refresh materialized view media_popularity');

    expect(app(TrendingSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$popular->id, $quiet->id]);
});

test('library, dismissed, deleted and merged media are excluded', function () {
    $user = User::factory()->create();
    [$inLib, $dismissed, $deleted, $merged, $ok] = Media::factory()->count(5)->create();
    libraryEntry($user, $inLib, 'reading');
    dismissed($user, $dismissed);
    $deleted->delete();
    $merged->update(['merged_into_id' => $ok->id]);
    DB::statement('refresh materialized view media_popularity');

    expect(app(TrendingSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$ok->id]);
});

test('a mature story is left out until the reader opts in', function () {
    $mature = matureStory();
    $gated = User::factory()->create();
    $open = User::factory()->showsMature()->create();
    DB::statement('refresh materialized view media_popularity');

    expect(app(TrendingSource::class)->candidatesFor($gated, 10))->toBeEmpty()
        ->and(app(TrendingSource::class)->candidatesFor($open, 10)->pluck('mediaId')->all())->toBe([$mature->id]);
});
