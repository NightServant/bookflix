<?php

use App\Discovery\Recommendations\CandidatePool;
use App\Discovery\Recommendations\Explanation;
use App\Discovery\Recommendations\HybridRanker;
use App\Discovery\Recommendations\RankingWeights;
use App\Discovery\Recommendations\Sources\FollowedReadersSource;
use App\Enums\ReasonType;
use App\Enums\Visibility;
use App\Models\Follow;
use App\Models\Media;
use App\Models\Rating;
use App\Models\User;

function followedReader(User $viewer, string $handle, bool $library = true): User
{
    $reader = User::factory()->create();
    $reader->profile()->updateOrCreate([], ['handle' => $handle, 'profile_visibility' => Visibility::Public, 'library_visibility' => $library ? Visibility::Public : Visibility::Private]);
    Follow::query()->insert(['follower_id' => $viewer->id, 'followed_id' => $reader->id, 'created_at' => now()]);

    return $reader->fresh();
}

test('stories are scored by distinct followers, with the count in the payload and no names', function () {
    $viewer = User::factory()->create();
    [$ann, $bob, $cy] = [followedReader($viewer, 'ann'), followedReader($viewer, 'bob'), followedReader($viewer, 'cy')];
    [$both, $one, $lowRated] = Media::factory()->count(3)->create();
    libraryEntry($ann, $both, 'completed');
    Rating::factory()->create(['user_id' => $ann->id, 'media_id' => $both->id, 'score' => 10]);
    Rating::factory()->create(['user_id' => $bob->id, 'media_id' => $both->id, 'score' => 8]);
    libraryEntry($cy, $one, 'completed');
    Rating::factory()->create(['user_id' => $cy->id, 'media_id' => $lowRated->id, 'score' => 7]);

    $c = app(FollowedReadersSource::class)->candidatesFor($viewer, 10);

    expect($c->pluck('mediaId')->all())->toBe([$both->id, $one->id])
        ->and($c[0]->score)->toBe(1.0)->and($c[0]->payload)->toBe(['followers' => 2])
        ->and($c[1]->score)->toBe(0.5)
        ->and($c[0]->source)->toBe('followed_readers')->and($c[0]->reason)->toBe(ReasonType::PopularWithFollowed)
        ->and($c[0]->reasonMediaId)->toBeNull()
        ->and((new Explanation($c[0]->reason))->label())->toBe('Popular with readers you follow');
});

test('private-library followers, strangers, deactivated and admin readers count for nothing', function () {
    $viewer = User::factory()->create();
    $closed = followedReader($viewer, 'closed', library: false);
    $off = followedReader($viewer, 'off');
    $boss = followedReader($viewer, 'boss');
    $stranger = User::factory()->create();
    $stranger->profile()->updateOrCreate([], ['profile_visibility' => Visibility::Public, 'library_visibility' => Visibility::Public]);
    $media = Media::factory()->create();
    foreach ([$closed, $off, $boss, $stranger] as $reader) {
        libraryEntry($reader, $media, 'completed');
    }
    $off->forceFill(['deactivated_at' => now()])->save();
    $boss->forceFill(['is_admin' => true])->save();

    expect(app(FollowedReadersSource::class)->candidatesFor($viewer, 10))->toBeEmpty();
});

test('the viewer library, dismissed titles, deleted and gated media are excluded', function () {
    $viewer = User::factory()->create();
    $ann = followedReader($viewer, 'ann');
    [$owned, $dismissed, $deleted, $ok] = Media::factory()->count(4)->create();
    $mature = matureStory();
    foreach ([$owned, $dismissed, $deleted, $ok, $mature] as $media) {
        libraryEntry($ann, $media, 'completed');
    }
    libraryEntry($viewer, $owned, 'want_to_read');
    dismissed($viewer, $dismissed);
    $deleted->delete();

    $open = User::factory()->showsMature()->create();
    Follow::query()->insert(['follower_id' => $open->id, 'followed_id' => $ann->id, 'created_at' => now()]);

    expect(app(FollowedReadersSource::class)->candidatesFor($viewer, 10)->pluck('mediaId')->all())->toBe([$ok->id])
        ->and(app(FollowedReadersSource::class)->candidatesFor($open, 10)->pluck('mediaId')->sort()->values()->all())
        ->toBe(collect([$ok->id, $mature->id, $owned->id, $dismissed->id])->sort()->values()->all());
});

test('an opted-out reader still gets it, and the pool carries it', function () {
    $viewer = User::factory()->create();
    $viewer->profile->update(['personalization_enabled' => false]);
    $ann = followedReader($viewer, 'ann');
    $media = Media::factory()->create();
    libraryEntry($ann, $media, 'completed');

    $lists = app(CandidatePool::class)->bySource($viewer->fresh());

    expect($lists)->toHaveKey('followed_readers')->and($lists['followed_readers']->pluck('mediaId')->all())->toBe([$media->id])
        ->and(CandidatePool::PERSONALIZED)->not->toContain('followed_readers');
});

test('the hybrid ranker accepts the new reason and keeps the label free of names', function () {
    $viewer = User::factory()->create();
    $ann = followedReader($viewer, 'ann');
    $media = Media::factory()->create();
    libraryEntry($ann, $media, 'completed');

    $candidates = app(FollowedReadersSource::class)->candidatesFor($viewer, 10);
    $ranked = app(HybridRanker::class)->rank($viewer, $candidates, RankingWeights::active());

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->explanation->label())->toBe('Popular with readers you follow')
        ->and($ranked[0]->explanation->label())->not->toContain($ann->name);
});
