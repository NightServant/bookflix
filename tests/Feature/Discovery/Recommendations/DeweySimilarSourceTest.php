<?php

use App\Discovery\Recommendations\Sources\DeweySimilarSource;
use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->seed(DeweySeeder::class));

test('stories in the same division as a seed qualify and other divisions do not', function () {
    $user = User::factory()->create();
    [$seed, $sibling, $maths] = Media::factory()->count(3)->create();
    classified($seed, '813');
    classified($sibling, '811');
    classified($maths, '510');
    libraryEntry($user, $seed, 'completed');
    DB::statement('refresh materialized view media_popularity');

    $c = app(DeweySimilarSource::class)->candidatesFor($user, 10);

    expect($c->pluck('mediaId')->all())->toBe([$sibling->id])
        ->and($c->first()->reason)->toBe(ReasonType::SimilarThemes)
        ->and($c->first()->reasonMediaId)->toBe($seed->id)
        ->and($c->first()->source)->toBe('dewey_similar')
        ->and($c->first()->score)->toBeBetween(0.0, 1.0);
});

test('the score is half closeness times strength and half popularity percentile', function () {
    $user = User::factory()->create();
    [$seed, $near, $quiet] = Media::factory()->count(3)->sequence([], ['ratings_count' => 100], ['ratings_count' => 0])->create();
    classified($seed, '813');
    classified($near, '811');
    classified($quiet, '813');
    libraryEntry($user, $seed, 'completed');
    DB::statement('refresh materialized view media_popularity');
    $percentile = fn (Media $m): float => (float) DB::table('media_popularity')->where('media_id', $m->id)->value('popularity_percentile');

    $c = app(DeweySimilarSource::class)->candidatesFor($user, 10)->keyBy('mediaId');

    expect($c[$near->id]->score)->toEqualWithDelta((2 / 3) * 0.8 * 0.5 + 0.5 * $percentile($near), 1e-9)
        ->and($c[$quiet->id]->score)->toEqualWithDelta(1.0 * 0.8 * 0.5 + 0.5 * $percentile($quiet), 1e-9);
});

test('rejected and low confidence classes are ignored on both sides', function () {
    $user = User::factory()->create();
    [$seed, $rejected, $unsure, $ok, $rejectedSeed, $other] = Media::factory()->count(6)->create();
    classified($seed, '813');
    classified($rejected, '811', status: 'rejected');
    classified($unsure, '811', status: 'pending', confidence: 0.1);
    classified($ok, '811');
    classified($rejectedSeed, '510', status: 'rejected');
    classified($other, '520');
    libraryEntry($user, $seed, 'completed');
    libraryEntry($user, $rejectedSeed, 'completed');
    DB::statement('refresh materialized view media_popularity');

    expect(app(DeweySimilarSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$ok->id]);
});

test('library, dismissed, deleted and merged media are excluded', function () {
    $user = User::factory()->create();
    [$seed, $inLib, $dismissed, $deleted, $merged, $ok] = Media::factory()->count(6)->create();
    classified(new Collection([$seed, $inLib, $dismissed, $deleted, $merged, $ok]), '811');
    libraryEntry($user, $seed, 'completed');
    libraryEntry($user, $inLib, 'reading');
    dismissed($user, $dismissed);
    $deleted->delete();
    $merged->update(['merged_into_id' => $ok->id]);
    DB::statement('refresh materialized view media_popularity');

    expect(app(DeweySimilarSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$ok->id]);
});

test('a mature story is left out until the reader opts in', function () {
    $seed = Media::factory()->create();
    $mature = matureStory();
    classified(new Collection([$seed, $mature]), '811');
    $gated = User::factory()->create();
    $open = User::factory()->showsMature()->create();
    libraryEntry($gated, $seed, 'completed');
    libraryEntry($open, $seed, 'completed');
    DB::statement('refresh materialized view media_popularity');

    expect(app(DeweySimilarSource::class)->candidatesFor($gated, 10))->toBeEmpty()
        ->and(app(DeweySimilarSource::class)->candidatesFor($open, 10)->pluck('mediaId')->all())->toBe([$mature->id]);
});

test('without seeds or classes there is nothing', function () {
    $user = User::factory()->create();
    expect(app(DeweySimilarSource::class)->candidatesFor($user, 10))->toBeEmpty();

    libraryEntry($user, Media::factory()->create(), 'completed');
    expect(app(DeweySimilarSource::class)->candidatesFor($user, 10))->toBeEmpty();
});
