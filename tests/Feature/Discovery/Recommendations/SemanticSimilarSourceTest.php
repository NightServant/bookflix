<?php

use App\Discovery\Recommendations\CandidatePool;
use App\Discovery\Recommendations\Sources\SemanticSimilarSource;
use App\Enums\ModelStatus;
use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\ModelVersion;
use App\Models\User;

test('contributes candidates with reason similar_themes and the seed as reason media', function () {
    $user = User::factory()->create();
    [$seed, $n1, $n2] = Media::factory()->count(3)->create();
    libraryEntry($user, $seed, 'completed');
    $v = activeVersion('embedding');
    similar($seed, $n1, 'embedding', 0.9, $v);
    similar($seed, $n2, 'embedding', 0.6, $v);

    $c = app(SemanticSimilarSource::class)->candidatesFor($user, 10);

    expect($c->pluck('mediaId')->all())->toBe([$n1->id, $n2->id])
        ->and($c->first()->score)->toEqualWithDelta(0.9 * 0.8, 1e-9)
        ->and($c->first()->reason)->toBe(ReasonType::SimilarThemes)
        ->and($c->first()->reasonMediaId)->toBe($seed->id)
        ->and($c->first()->source)->toBe('semantic_similar');
});

test('a neighbor reached from two seeds is credited to the better seed', function () {
    $user = User::factory()->create();
    [$weak, $strong, $n] = Media::factory()->count(3)->create();
    libraryEntry($user, $weak, 'reading');
    libraryEntry($user, $strong, 'completed', favorite: true);
    $v = activeVersion('embedding');
    similar($weak, $n, 'embedding', 0.9, $v);
    similar($strong, $n, 'embedding', 0.6, $v);

    $c = app(SemanticSimilarSource::class)->candidatesFor($user, 10);

    expect($c)->toHaveCount(1)
        ->and($c->first()->score)->toEqualWithDelta(0.6, 1e-9)
        ->and($c->first()->reasonMediaId)->toBe($strong->id);
});

test('ignores rows of retired embedding versions', function () {
    $user = User::factory()->create();
    [$seed, $old, $new] = Media::factory()->count(3)->create();
    libraryEntry($user, $seed, 'completed');
    $retired = ModelVersion::factory()->create(['name' => 'embedding', 'kind' => 'embedding', 'status' => ModelStatus::Retired]);
    $candidate = ModelVersion::factory()->create(['name' => 'embedding', 'kind' => 'embedding', 'status' => ModelStatus::Candidate]);
    similar($seed, $old, 'embedding', 0.99, $retired);
    similar($seed, $old, 'embedding', 0.99, $candidate);

    expect(app(SemanticSimilarSource::class)->candidatesFor($user, 10))->toBeEmpty();

    similar($seed, $new, 'embedding', 0.5, activeVersion('embedding'));

    expect(app(SemanticSimilarSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$new->id]);
});

test('lsa rows never count as embedding neighbors', function () {
    $user = User::factory()->create();
    [$seed, $n] = Media::factory()->count(2)->create();
    libraryEntry($user, $seed, 'completed');
    similar($seed, $n, 'lsa', 0.9, activeVersion('lsa'));

    expect(app(SemanticSimilarSource::class)->candidatesFor($user, 10))->toBeEmpty();
});

test('no seeds give an empty collection and the limit is honoured', function () {
    $user = User::factory()->create();
    $v = activeVersion('embedding');
    [$other, $n] = Media::factory()->count(2)->create();
    similar($other, $n, 'embedding', 0.9, $v);

    expect(app(SemanticSimilarSource::class)->candidatesFor($user, 10))->toBeEmpty();

    $seed = Media::factory()->create();
    libraryEntry($user, $seed, 'completed');
    foreach (Media::factory()->count(30)->create() as $i => $neighbor) {
        similar($seed, $neighbor, 'embedding', 0.5 + $i / 100, $v);
    }

    expect(app(SemanticSimilarSource::class)->candidatesFor($user, 5))->toHaveCount(5);
});

test('same media never its own neighbor, nor any title in the reader\'s library', function () {
    $user = User::factory()->create();
    [$a, $b, $n] = Media::factory()->count(3)->create();
    libraryEntry($user, $a, 'completed');
    libraryEntry($user, $b, 'completed');
    $v = activeVersion('embedding');
    similar($a, $b, 'embedding', 0.99, $v);
    similar($b, $a, 'embedding', 0.99, $v);
    similar($a, $n, 'embedding', 0.4, $v);

    $ids = app(SemanticSimilarSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all();

    expect($ids)->toBe([$n->id]);
});

test('dismissed, deleted and merged titles are excluded', function () {
    $user = User::factory()->create();
    [$seed, $dismissed, $deleted, $merged, $ok] = Media::factory()->count(5)->create();
    libraryEntry($user, $seed, 'completed');
    $v = activeVersion('embedding');
    foreach ([$dismissed, $deleted, $merged, $ok] as $m) {
        similar($seed, $m, 'embedding', 0.7, $v);
    }
    dismissed($user, $dismissed);
    $deleted->delete();
    $merged->update(['merged_into_id' => $ok->id]);

    expect(app(SemanticSimilarSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$ok->id]);
});

test('a mature neighbor is left out until the reader opts in', function () {
    $seed = Media::factory()->create();
    $mature = matureStory();
    $gated = User::factory()->create();
    $open = User::factory()->showsMature()->create();
    similar($seed, $mature, 'embedding', 0.9, activeVersion('embedding'));

    foreach ([$gated, $open] as $user) {
        libraryEntry($user, $seed, 'completed');
    }

    expect(app(SemanticSimilarSource::class)->candidatesFor($gated, 10))->toBeEmpty()
        ->and(app(SemanticSimilarSource::class)->candidatesFor($open, 10)->pluck('mediaId')->all())->toBe([$mature->id]);
});

test('it is a personalized source of the pool, so an opted out reader gets nothing from it', function () {
    $user = User::factory()->create();
    [$seed, $n] = Media::factory()->count(2)->create();
    libraryEntry($user, $seed, 'completed');
    similar($seed, $n, 'embedding', 0.9, activeVersion('embedding'));

    expect(app(CandidatePool::class)->bySource($user, 10)['semantic_similar']->pluck('mediaId')->all())->toBe([$n->id]);

    $user->profile->update(['personalization_enabled' => false]);

    expect(app(CandidatePool::class)->bySource($user->fresh(), 10))->not->toHaveKey('semantic_similar');
});
