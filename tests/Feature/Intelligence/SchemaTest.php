<?php

use App\Enums\InteractionEvent;
use App\Enums\ModelStatus;
use App\Models\Media;
use App\Models\MediaSimilarity;
use App\Models\MlJobRun;
use App\Models\ModelVersion;
use App\Models\Profile;
use App\Models\Recommendation;
use App\Models\RecommendationEvent;
use App\Models\User;
use App\Models\UserInteraction;
use Database\Seeders\ModelVersionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Views by one reader, a day ago, on one media.
 */
function views(User $reader, Media $media, int $count): void
{
    UserInteraction::factory()->count($count)->create([
        'user_id' => $reader->id,
        'media_id' => $media->id,
        'event' => InteractionEvent::View,
        'occurred_at' => now()->subDay(),
    ]);
}

/**
 * Run a write the database must refuse. The savepoint keeps the test's own transaction usable after the error.
 */
function refused(Closure $write): Closure
{
    return fn () => DB::transaction($write);
}

function popularityRows(): Collection
{
    DB::statement('refresh materialized view media_popularity');

    return DB::table('media_popularity')->get()->keyBy('media_id');
}

test('only one active model version per name is allowed', function () {
    ModelVersion::factory()->create(['name' => 'lsa', 'version' => 'a', 'status' => ModelStatus::Active]);

    expect(refused(fn () => ModelVersion::factory()->create(['name' => 'lsa', 'version' => 'b', 'status' => ModelStatus::Active])))->toThrow(QueryException::class);

    ModelVersion::factory()->create(['name' => 'lsa', 'version' => 'c', 'status' => ModelStatus::Candidate]);
    ModelVersion::factory()->create(['name' => 'item-cf', 'version' => 'a', 'status' => ModelStatus::Active]);

    expect(ModelVersion::activeFor('lsa')->version)->toBe('a')->and(ModelVersion::activeFor('nothing'))->toBeNull();
});

test('a model name and version are unique together', function () {
    ModelVersion::factory()->create(['name' => 'lsa', 'version' => 'a']);

    expect(refused(fn () => ModelVersion::factory()->create(['name' => 'lsa', 'version' => 'a'])))->toThrow(QueryException::class);
});

test('seeded weight versions match config', function () {
    $this->seed(ModelVersionSeeder::class);

    expect(ModelVersion::activeFor('hybrid-weights')->parameters['content'])->toBe(0.30)
        ->and(ModelVersion::activeFor('interaction-weights')->parameters['library_add'])->toBe(3)
        ->and(ModelVersion::activeFor('hybrid-weights')->version)->toBe('seed')
        ->and(array_sum(Arr::except(ModelVersion::activeFor('hybrid-weights')->parameters, ['context'])))->toEqualWithDelta(1.0, 1e-9);
});

test('seeding the weights twice keeps one row and leaves a changed one alone', function () {
    $this->seed(ModelVersionSeeder::class);
    ModelVersion::activeFor('hybrid-weights')->update(['parameters' => ['content' => 0.5]]);
    $this->seed(ModelVersionSeeder::class);

    expect(ModelVersion::where('name', 'hybrid-weights')->count())->toBe(1)
        ->and(ModelVersion::activeFor('hybrid-weights')->parameters)->toBe(['content' => 0.5]);
});

test('similarities cannot repeat a neighbor within a version and cascade with media', function () {
    $a = MediaSimilarity::factory()->create();

    expect(refused(fn () => MediaSimilarity::factory()->create(['media_id' => $a->media_id, 'similar_media_id' => $a->similar_media_id, 'method' => $a->method, 'model_version_id' => $a->model_version_id])))
        ->toThrow(QueryException::class);

    Media::find($a->media_id)->forceDelete();

    expect(MediaSimilarity::count())->toBe(0);
});

test('two versions of a method keep the same pair side by side, and saving one leaves the other alone', function () {
    $active = ModelVersion::factory()->active()->create();
    $candidate = ModelVersion::factory()->create();
    $pair = ['media_id' => Media::factory()->create()->id, 'similar_media_id' => Media::factory()->create()->id, 'method' => 'lsa'];
    $old = MediaSimilarity::factory()->create([...$pair, 'model_version_id' => $active->id, 'score' => 0.9]);
    $new = MediaSimilarity::factory()->create([...$pair, 'model_version_id' => $candidate->id, 'score' => 0.2]);

    $new->update(['score' => 0.3]);

    expect(MediaSimilarity::count())->toBe(2)
        ->and(MediaSimilarity::where('model_version_id', $active->id)->value('score'))->toBe(0.9)
        ->and(MediaSimilarity::where('model_version_id', $candidate->id)->value('score'))->toBe(0.3);

    MediaSimilarity::where('model_version_id', $old->model_version_id)->delete();

    expect(MediaSimilarity::pluck('model_version_id')->all())->toBe([$candidate->id]);
});

test('a media is never its own neighbor', function () {
    $media = Media::factory()->create();

    expect(refused(fn () => MediaSimilarity::factory()->create(['media_id' => $media->id, 'similar_media_id' => $media->id])))->toThrow(QueryException::class);
});

test('recommendations and their events cascade with the reader', function () {
    $event = RecommendationEvent::factory()->create();

    expect(Recommendation::count())->toBe(1)->and($event->recommendation->reason_payload)->toBeArray();

    User::find($event->recommendation->user_id)->forceDelete();

    expect(Recommendation::count())->toBe(0)->and(RecommendationEvent::count())->toBe(0);
});

test('an ml job run keeps its params and metrics as json', function () {
    $run = MlJobRun::factory()->create(['params' => ['components' => 200]]);

    expect($run->fresh()->params)->toBe(['components' => 200])->and($run->metrics)->toBeNull()->and($run->finished_at)->toBeNull();
});

test('popularity view has a row for every live media and ignores deactivated users', function () {
    [$hot, $cold] = Media::factory()->count(2)->create();
    $active = User::factory()->create();
    $gone = User::factory()->create(['deactivated_at' => now()]);
    views($active, $hot, 3);
    views($gone, $cold, 5);

    $rows = popularityRows();

    expect($rows)->toHaveCount(2)
        ->and((int) $rows[$hot->id]->interactions_7d)->toBe(3)
        ->and((int) $rows[$cold->id]->interactions_7d)->toBe(0)
        ->and((float) $rows[$hot->id]->popularity_percentile)->toBeGreaterThan((float) $rows[$cold->id]->popularity_percentile);
});

test('popularity view skips readers who turned personalization off and counts a reader with no profile row', function () {
    [$optedOut, $noProfile] = Media::factory()->count(2)->create();
    $off = User::factory()->create();
    Profile::updateOrCreate(['user_id' => $off->id], ['personalization_enabled' => false]);
    $bare = User::factory()->create();
    Profile::where('user_id', $bare->id)->delete();
    views($off, $optedOut, 4);
    views($bare, $noProfile, 2);

    $rows = popularityRows();

    expect((int) $rows[$optedOut->id]->interactions_7d)->toBe(0)->and((int) $rows[$noProfile->id]->interactions_7d)->toBe(2);
});

test('popularity view leaves out soft deleted and merged media', function () {
    [$live, $deleted, $merged] = Media::factory()->count(3)->create();
    $deleted->delete();
    $merged->update(['merged_into_id' => $live->id]);

    expect(popularityRows()->keys()->all())->toBe([$live->id]);
});

test('popularity view windows interactions by 7 and 30 days and weights the trending score', function () {
    $media = Media::factory()->create();
    $reader = User::factory()->create();
    views($reader, $media, 2);
    UserInteraction::factory()->count(4)->create(['user_id' => $reader->id, 'media_id' => $media->id, 'occurred_at' => now()->subDays(20)]);
    UserInteraction::factory()->count(9)->create(['user_id' => $reader->id, 'media_id' => $media->id, 'occurred_at' => now()->subDays(60)]);

    $row = popularityRows()[$media->id];

    expect((int) $row->interactions_7d)->toBe(2)
        ->and((int) $row->interactions_30d)->toBe(6)
        ->and((float) $row->trending_score)->toBe(2 + 0.25 * (6 - 2));
});

test('refreshing popularity with no interactions does not fail', function () {
    Media::factory()->count(3)->create();

    DB::statement('refresh materialized view concurrently media_popularity');

    expect(DB::table('media_popularity')->count())->toBe(3)
        ->and((float) DB::table('media_popularity')->max('popularity_percentile'))->toBeLessThanOrEqual(1.0);
});

test('the popularity view can refresh concurrently after interactions arrive', function () {
    $media = Media::factory()->create();
    DB::statement('refresh materialized view concurrently media_popularity');
    views(User::factory()->create(), $media, 1);
    DB::statement('refresh materialized view concurrently media_popularity');

    expect((int) DB::table('media_popularity')->where('media_id', $media->id)->value('interactions_7d'))->toBe(1);
});

test('the columns the python service reads exist', function () {
    $expected = json_decode(file_get_contents(base_path('ml/tests/expected_columns.json')), true);

    expect($expected)->not->toBeEmpty();

    foreach ($expected as $table => $columns) {
        expect(Schema::getColumnListing($table))->toContain(...$columns);
    }
});
