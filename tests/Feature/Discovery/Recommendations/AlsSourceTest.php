<?php

use App\Discovery\Recommendations\CandidatePool;
use App\Discovery\Recommendations\Sources\AlsSource;
use App\Enums\ModelKind;
use App\Enums\ModelStatus;
use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\ModelVersion;
use App\Models\User;
use App\Providers\RecommendationServiceProvider;

test('als neighbors add up over seeds, clamp at one and credit the strongest seed', function () {
    $user = User::factory()->create();
    [$a, $b, $shared, $single] = Media::factory()->count(4)->create();
    libraryEntry($user, $a, 'completed', favorite: true);
    libraryEntry($user, $b, 'completed');
    $v = activeVersion('als');
    similar($a, $shared, 'als', 0.9, $v);
    similar($b, $shared, 'als', 0.9, $v);
    similar($b, $single, 'als', 0.5, $v);

    $c = app(AlsSource::class)->candidatesFor($user, 10);

    expect($c->pluck('mediaId')->all())->toBe([$shared->id, $single->id])
        ->and($c->first()->score)->toBe(1.0)
        ->and($c->first()->reasonMediaId)->toBe($a->id)
        ->and($c->first()->reason)->toBe(ReasonType::PopularWithSimilarReaders)
        ->and($c->first()->source)->toBe('als')
        ->and($c->last()->score)->toEqualWithDelta(0.4, 1e-9);
});

test('with no active als version the source is empty, whatever rows exist', function () {
    $user = User::factory()->create();
    [$seed, $one, $two] = Media::factory()->count(3)->create();
    libraryEntry($user, $seed, 'completed');
    similar($seed, $one, 'als', 0.9, ModelVersion::factory()->create(['name' => 'als', 'kind' => ModelKind::Als, 'status' => ModelStatus::Candidate]));
    similar($seed, $two, 'als', 0.9, ModelVersion::factory()->create(['name' => 'als', 'kind' => ModelKind::Als, 'status' => ModelStatus::Retired]));

    expect(app(AlsSource::class)->candidatesFor($user, 10))->toBeEmpty();
});

test('library, dismissed, deleted and retired rows are excluded and other methods are ignored', function () {
    $user = User::factory()->create();
    [$seed, $inLib, $dismissed, $deleted, $stale, $cf, $ok] = Media::factory()->count(7)->create();
    libraryEntry($user, $seed, 'completed');
    libraryEntry($user, $inLib, 'reading');
    $v = activeVersion('als');
    $old = ModelVersion::factory()->create(['name' => 'als', 'kind' => ModelKind::Als, 'status' => ModelStatus::Retired]);
    foreach ([$inLib, $dismissed, $deleted, $ok] as $m) {
        similar($seed, $m, 'als', 0.6, $v);
    }
    similar($seed, $stale, 'als', 0.9, $old);
    similar($seed, $cf, 'item_cf', 0.9, activeVersion('item-cf'));
    dismissed($user, $dismissed);
    $deleted->delete();

    expect(app(AlsSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$ok->id]);
});

test('a mature, soft deleted or merged neighbor never surfaces to a reader who may not see it', function () {
    $seed = Media::factory()->create();
    $mature = matureStory();
    $gone = Media::factory()->create();
    $target = Media::factory()->create();
    $merged = Media::factory()->create(['merged_into_id' => $target->id]);
    $visible = Media::factory()->create();
    $gated = User::factory()->create();
    $open = User::factory()->showsMature()->create();
    $v = activeVersion('als');
    foreach ([$mature, $gone, $merged, $visible] as $m) {
        similar($seed, $m, 'als', 0.9, $v);
    }
    $gone->delete();
    libraryEntry($gated, $seed, 'completed');
    libraryEntry($open, $seed, 'completed');

    expect(app(AlsSource::class)->candidatesFor($gated, 10)->pluck('mediaId')->all())->toBe([$visible->id])
        ->and(app(AlsSource::class)->candidatesFor($open, 10)->pluck('mediaId')->sort()->values()->all())->toBe(collect([$mature->id, $visible->id])->sort()->values()->all());
});

test('without seeds there is nothing', function () {
    activeVersion('als');

    expect(app(AlsSource::class)->candidatesFor(User::factory()->create(), 10))->toBeEmpty();
});

test('the source is tagged, and the pool skips it for a reader who opted out', function () {
    $keys = collect(app()->tagged(RecommendationServiceProvider::SOURCES))->map->key();
    $user = User::factory()->create();
    $seed = Media::factory()->create();
    $neighbor = Media::factory()->create();
    libraryEntry($user, $seed, 'completed');
    similar($seed, $neighbor, 'als', 0.9, activeVersion('als'));

    expect($keys)->toContain('als')
        ->and(CandidatePool::PERSONALIZED)->toContain('als')
        ->and(app(CandidatePool::class)->bySource($user)['als']->pluck('mediaId')->all())->toBe([$neighbor->id]);

    $user->profile->update(['personalization_enabled' => false]);

    expect(app(CandidatePool::class)->bySource($user->fresh()))->not->toHaveKey('als');
});
