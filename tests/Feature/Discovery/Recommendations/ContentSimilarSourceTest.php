<?php

use App\Discovery\Recommendations\Sources\ContentSimilarSource;
use App\Enums\ModelStatus;
use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\ModelVersion;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('neighbors of seeds are scored by neighbor score times seed strength and attributed to the seed', function () {
    $user = User::factory()->create();
    [$seed, $n1, $n2] = Media::factory()->count(3)->create();
    libraryEntry($user, $seed, 'completed');
    $v = activeVersion('lsa');
    similar($seed, $n1, 'lsa', 0.8, $v);
    similar($seed, $n2, 'lsa', 0.5, $v);

    $c = app(ContentSimilarSource::class)->candidatesFor($user, 10);

    expect($c->pluck('mediaId')->all())->toBe([$n1->id, $n2->id])
        ->and($c->first()->score)->toEqualWithDelta(0.8 * 0.8, 1e-9)
        ->and($c->first()->reason)->toBe(ReasonType::BecauseYouRead)
        ->and($c->first()->reasonMediaId)->toBe($seed->id)
        ->and($c->first()->source)->toBe('content_similar');
});

test('a neighbor reached from two seeds takes the better seed', function () {
    $user = User::factory()->create();
    [$weak, $strong, $n] = Media::factory()->count(3)->create();
    libraryEntry($user, $weak, 'reading');
    libraryEntry($user, $strong, 'completed', favorite: true);
    $v = activeVersion('lsa');
    similar($weak, $n, 'lsa', 0.9, $v);
    similar($strong, $n, 'lsa', 0.6, $v);

    $c = app(ContentSimilarSource::class)->candidatesFor($user, 10);

    expect($c)->toHaveCount(1)
        ->and($c->first()->score)->toEqualWithDelta(0.6, 1e-9)
        ->and($c->first()->reasonMediaId)->toBe($strong->id);
});

test('library items, dismissed, deleted and merged items and non active versions are excluded', function () {
    $user = User::factory()->create();
    [$seed, $inLib, $dismissed, $deleted, $merged, $stale, $ok] = Media::factory()->count(7)->create();
    libraryEntry($user, $seed, 'completed');
    libraryEntry($user, $inLib, 'want_to_read');
    $v = activeVersion('lsa');
    $old = ModelVersion::factory()->create(['name' => 'lsa', 'status' => ModelStatus::Retired]);
    foreach ([$inLib, $dismissed, $deleted, $merged, $ok] as $m) {
        similar($seed, $m, 'lsa', 0.7, $v);
    }
    similar($seed, $stale, 'lsa', 0.9, $old);
    dismissed($user, $dismissed);
    $deleted->delete();
    $merged->update(['merged_into_id' => $ok->id]);

    expect(app(ContentSimilarSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$ok->id]);
});

test('readers only see the active version: a candidate version changes neither scores nor the set', function () {
    $user = User::factory()->create();
    [$seed, $a, $b] = Media::factory()->count(3)->create();
    libraryEntry($user, $seed, 'completed');
    $active = activeVersion('lsa');
    $candidate = ModelVersion::factory()->create(['name' => 'lsa', 'status' => ModelStatus::Candidate]);
    similar($seed, $a, 'lsa', 0.5, $active);
    similar($seed, $a, 'lsa', 0.99, $candidate);
    similar($seed, $b, 'lsa', 0.99, $candidate);

    $c = app(ContentSimilarSource::class)->candidatesFor($user, 10);

    expect($c->pluck('mediaId')->all())->toBe([$a->id])
        ->and($c->first()->score)->toEqualWithDelta(0.5 * 0.8, 1e-9);
});

test('another method never counts as lsa', function () {
    $user = User::factory()->create();
    [$seed, $n] = Media::factory()->count(2)->create();
    libraryEntry($user, $seed, 'completed');
    similar($seed, $n, 'item_cf', 0.9, activeVersion('item-cf'));

    expect(app(ContentSimilarSource::class)->candidatesFor($user, 10))->toBeEmpty();
});

test('no seeds or no similarity rows give an empty collection and the limit is honoured', function () {
    $user = User::factory()->create();

    expect(app(ContentSimilarSource::class)->candidatesFor($user, 10))->toBeEmpty();

    $seed = Media::factory()->create();
    libraryEntry($user, $seed, 'completed');
    expect(app(ContentSimilarSource::class)->candidatesFor($user, 10))->toBeEmpty();

    $v = activeVersion('lsa');
    foreach (Media::factory()->count(30)->create() as $i => $n) {
        similar($seed, $n, 'lsa', 0.5 + $i / 100, $v);
    }

    expect(app(ContentSimilarSource::class)->candidatesFor($user, 5))->toHaveCount(5);
});

test('abandoned and low rated stories are not seeds', function () {
    $user = User::factory()->create();
    [$abandoned, $low, $n1, $n2] = Media::factory()->count(4)->create();
    libraryEntry($user, $abandoned, 'abandoned');
    libraryEntry($user, $low, 'completed');
    Rating::factory()->create(['user_id' => $user->id, 'media_id' => $low->id, 'score' => 4]);
    $v = activeVersion('lsa');
    similar($abandoned, $n1, 'lsa', 0.9, $v);
    similar($low, $n2, 'lsa', 0.9, $v);

    expect(app(ContentSimilarSource::class)->candidatesFor($user, 10))->toBeEmpty();
});

test('twenty seeds still take a handful of queries', function () {
    $user = User::factory()->create();
    $v = activeVersion('lsa');
    $targets = Media::factory()->count(5)->create();

    foreach (Media::factory()->count(20)->create() as $seed) {
        libraryEntry($user, $seed, 'completed');
        foreach ($targets as $target) {
            similar($seed, $target, 'lsa', 0.5, $v);
        }
    }

    $user = $user->fresh();
    DB::enableQueryLog();
    app(ContentSimilarSource::class)->candidatesFor($user, 10);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(6);
});

test('a mature neighbor is left out until the reader opts in', function () {
    $seed = Media::factory()->create();
    $mature = matureStory();
    $gated = User::factory()->create();
    $open = User::factory()->showsMature()->create();
    $v = activeVersion('lsa');
    similar($seed, $mature, 'lsa', 0.9, $v);

    foreach ([$gated, $open] as $user) {
        libraryEntry($user, $seed, 'completed');
    }

    expect(app(ContentSimilarSource::class)->candidatesFor($gated, 10))->toBeEmpty()
        ->and(app(ContentSimilarSource::class)->candidatesFor($open, 10)->pluck('mediaId')->all())->toBe([$mature->id]);
});
