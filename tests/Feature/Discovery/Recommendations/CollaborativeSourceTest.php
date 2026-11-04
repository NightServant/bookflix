<?php

use App\Discovery\Recommendations\Sources\CollaborativeSource;
use App\Enums\ModelStatus;
use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\ModelVersion;
use App\Models\User;

test('item cf neighbors add up over seeds, clamp at one and credit the strongest seed', function () {
    $user = User::factory()->create();
    [$a, $b, $shared, $single] = Media::factory()->count(4)->create();
    libraryEntry($user, $a, 'completed', favorite: true);
    libraryEntry($user, $b, 'completed');
    $v = activeVersion('item-cf');
    similar($a, $shared, 'item_cf', 0.9, $v);
    similar($b, $shared, 'item_cf', 0.9, $v);
    similar($b, $single, 'item_cf', 0.5, $v);

    $c = app(CollaborativeSource::class)->candidatesFor($user, 10);

    expect($c->pluck('mediaId')->all())->toBe([$shared->id, $single->id])
        ->and($c->first()->score)->toBe(1.0)
        ->and($c->first()->reasonMediaId)->toBe($a->id)
        ->and($c->first()->reason)->toBe(ReasonType::PopularWithSimilarReaders)
        ->and($c->first()->source)->toBe('collaborative')
        ->and($c->last()->score)->toEqualWithDelta(0.4, 1e-9);
});

test('library, dismissed, deleted and non active rows are excluded and lsa rows are ignored', function () {
    $user = User::factory()->create();
    [$seed, $inLib, $dismissed, $deleted, $stale, $lsa, $ok] = Media::factory()->count(7)->create();
    libraryEntry($user, $seed, 'completed');
    libraryEntry($user, $inLib, 'reading');
    $v = activeVersion('item-cf');
    $old = ModelVersion::factory()->create(['name' => 'item-cf', 'kind' => 'item_cf', 'status' => ModelStatus::Retired]);
    foreach ([$inLib, $dismissed, $deleted, $ok] as $m) {
        similar($seed, $m, 'item_cf', 0.6, $v);
    }
    similar($seed, $stale, 'item_cf', 0.9, $old);
    similar($seed, $lsa, 'lsa', 0.9, activeVersion('lsa'));
    dismissed($user, $dismissed);
    $deleted->delete();

    expect(app(CollaborativeSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$ok->id]);
});

test('a mature neighbor is left out until the reader opts in', function () {
    $seed = Media::factory()->create();
    $mature = matureStory();
    $gated = User::factory()->create();
    $open = User::factory()->showsMature()->create();
    similar($seed, $mature, 'item_cf', 0.9, activeVersion('item-cf'));
    libraryEntry($gated, $seed, 'completed');
    libraryEntry($open, $seed, 'completed');

    expect(app(CollaborativeSource::class)->candidatesFor($gated, 10))->toBeEmpty()
        ->and(app(CollaborativeSource::class)->candidatesFor($open, 10)->pluck('mediaId')->all())->toBe([$mature->id]);
});

test('without seeds there is nothing', function () {
    expect(app(CollaborativeSource::class)->candidatesFor(User::factory()->create(), 10))->toBeEmpty();
});
