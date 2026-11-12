<?php

use App\Models\LtrCandidate;
use App\Models\LtrScore;
use App\Models\Media;
use App\Models\ModelVersion;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

function ltrRows(User $user, Media $media): void
{
    $version = ModelVersion::factory()->create(['name' => 'ltr', 'kind' => 'ltr']);
    LtrCandidate::create(['user_id' => $user->id, 'media_id' => $media->id, 'features' => ['popularity' => 0.5], 'generated_at' => now()]);
    LtrScore::create(['user_id' => $user->id, 'media_id' => $media->id, 'score' => 1.5, 'model_version_id' => $version->id, 'generated_at' => now()]);
}

test('deleting a reader removes their ltr rows', function () {
    [$user, $other] = User::factory()->count(2)->create();
    $media = Media::factory()->create();
    ltrRows($user, $media);
    ltrRows($other, $media);

    $user->delete();

    expect(LtrCandidate::count())->toBe(1)->and(LtrScore::count())->toBe(1)
        ->and(LtrScore::first()->user_id)->toBe($other->id);
});

test('deleting a media removes its ltr rows', function () {
    $user = User::factory()->create();
    [$gone, $kept] = Media::factory()->count(2)->create();
    ltrRows($user, $gone);
    ltrRows($user, $kept);

    $gone->forceDelete();

    expect(LtrCandidate::pluck('media_id')->all())->toBe([$kept->id])->and(LtrScore::pluck('media_id')->all())->toBe([$kept->id]);
});

test('a reader has one row per title in each table, and scores are real numbers', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    ltrRows($user, $media);
    $type = Schema::getColumnType('ltr_scores', 'score');

    expect($type)->toBe('float4')
        ->and(fn () => LtrCandidate::create(['user_id' => $user->id, 'media_id' => $media->id, 'features' => [], 'generated_at' => now()]))->toThrow(QueryException::class);
});

test('recommendations have a nullable position', function () {
    expect(Recommendation::factory()->create()->refresh()->position)->toBeNull()
        ->and(Recommendation::factory()->create(['position' => 3])->refresh()->position)->toBe(3);
});
