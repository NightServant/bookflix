<?php

use App\Discovery\Recommendations\CandidatePool;
use App\Discovery\Recommendations\RecommendationLogger;
use App\Discovery\Recommendations\ServedItem;
use App\Discovery\Recommendations\ShelfBuilder;
use App\Enums\ReasonType;
use App\Enums\RecommendationEventType;
use App\Events\RecommendationDismissed;
use App\Models\ModelVersion;
use App\Models\Recommendation;
use App\Models\RecommendationEvent;
use App\Models\User;
use Illuminate\Support\Facades\Event;

const FEATURES = ['content_similarity', 'collaborative_score', 'dewey_similarity', 'popularity', 'freshness', 'personal_preference', 'context', 'novelty'];

test('what was shown is stored once per fill with reason, features and model version', function () {
    [$user, $catalog] = richReader();
    app(ShelfBuilder::class)->forHome($user);
    $rows = Recommendation::count();
    app(ShelfBuilder::class)->forHome($user);

    $row = Recommendation::where('shelf', 'because-you-read')->first();

    expect($rows)->toBeGreaterThan(0)->and(Recommendation::count())->toBe($rows)
        ->and(collect(array_keys($row->reason_payload['features']))->sort()->values()->all())->toBe(collect(FEATURES)->sort()->values()->all())
        ->and($row->reason_type)->toBe(ReasonType::BecauseYouRead)
        ->and($row->reason_media_id)->toBe($catalog[0]->id)
        ->and($row->model_version_id)->toBe(ModelVersion::activeFor('hybrid-weights')->id)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->score)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0)
        ->and(Recommendation::where('shelf', 'trending')->first()->reason_type)->toBe(ReasonType::Trending);
});

test('a reader who opted out is never logged', function () {
    [$user] = richReader();
    $user->profile->update(['personalization_enabled' => false]);

    app(ShelfBuilder::class)->forHome($user->fresh());

    expect(Recommendation::count())->toBe(0);
    expect(app(RecommendationLogger::class)->log($user->fresh(), [new ServedItem('trending', 1, 0.5, ReasonType::Trending)], activeVersion('hybrid-weights')))->toBe([]);
    expect(Recommendation::count())->toBe(0);
});

test('without an active hybrid weights version the shelves still build and nothing is logged', function () {
    [$user] = richReader();
    ModelVersion::where('name', 'hybrid-weights')->delete();

    expect(app(ShelfBuilder::class)->forHome($user)->count())->toBeGreaterThan(4)->and(Recommendation::count())->toBe(0);
});

test('events are recorded for the owner only', function () {
    [$user] = richReader();
    app(ShelfBuilder::class)->forHome($user);
    $recommendation = Recommendation::where('user_id', $user->id)->first();
    $other = User::factory()->create();
    $logger = app(RecommendationLogger::class);

    $logger->event($other, $recommendation->id, RecommendationEventType::Click);
    $logger->event($other, $recommendation->id, RecommendationEventType::Dismiss);
    $logger->event($user, 999999, RecommendationEventType::Click);

    expect(RecommendationEvent::count())->toBe(0);

    $logger->event($user, $recommendation->id, RecommendationEventType::Click);
    $logger->event($user, $recommendation->id, RecommendationEventType::Impression);

    expect(RecommendationEvent::where('recommendation_id', $recommendation->id)->pluck('event')->all())->toBe([RecommendationEventType::Click, RecommendationEventType::Impression]);
});

test('a dismissal is an event and tells the cache to forget', function () {
    [$user] = richReader();
    app(ShelfBuilder::class)->forHome($user);
    $recommendation = Recommendation::where('user_id', $user->id)->first();
    Event::fake([RecommendationDismissed::class]);

    app(RecommendationLogger::class)->event($user, $recommendation->id, RecommendationEventType::Dismiss);

    Event::assertDispatched(RecommendationDismissed::class, fn ($event) => $event->userId === $user->id);
    expect(RecommendationEvent::where('event', 'dismiss')->count())->toBe(1);
});

test('a dismissed story is not a candidate any more', function () {
    [$user] = richReader();
    app(ShelfBuilder::class)->forHome($user);
    $recommendation = Recommendation::where('user_id', $user->id)->where('shelf', 'recommended')->first();
    app(RecommendationLogger::class)->event($user, $recommendation->id, RecommendationEventType::Dismiss);

    expect(app(CandidatePool::class)->gather($user)->pluck('mediaId'))->not->toContain($recommendation->media_id);
});

test('an opted out reader\'s events are ignored', function () {
    [$user] = richReader();
    $recommendation = Recommendation::factory()->create(['user_id' => $user->id]);
    $user->profile->update(['personalization_enabled' => false]);

    app(RecommendationLogger::class)->event($user->fresh(), $recommendation->id, RecommendationEventType::Click);

    expect(RecommendationEvent::count())->toBe(0);
});
