<?php

use App\Discovery\Recommendations\InteractionRecorder;
use App\Enums\InteractionEvent;
use App\Jobs\RecordInteraction;
use App\Models\Genre;
use App\Models\Media;
use App\Models\SearchQuery;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/** The id of the session `visitStory` makes its requests in (a session id is 40 letters and digits). */
const KNOWN_SESSION = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

/**
 * The keyed hash of the session a request is made in, as SearchLogger stores it.
 */
function sessionHash(string $sessionId): string
{
    return hash_hmac('sha256', $sessionId, config('app.key'));
}

/**
 * Visit a story as this reader in the known session, so a search made in that session can be credited.
 */
function visitStory(Media $media, User $user, string $query = ''): void
{
    test()->withCookie(config('session.cookie'), KNOWN_SESSION)
        ->actingAs($user)
        ->get(route('stories.show', $media->slug).$query)
        ->assertOk();
}

test('an opted in user gets one queued insert per event with the allowed context only', function () {
    Queue::fake();
    $user = User::factory()->create();
    $media = Media::factory()->create();

    app(InteractionRecorder::class)->record($user, InteractionEvent::Click, $media, ['surface' => 'search', 'search_query_id' => 7, 'ip' => '1.2.3.4', 'user_agent' => 'x'], 1.0);

    Queue::assertPushed(RecordInteraction::class, 1);
    Queue::assertPushed(RecordInteraction::class, fn (RecordInteraction $job): bool => $job->context === ['surface' => 'search', 'search_query_id' => 7]
        && $job->userId === $user->id && $job->mediaId === $media->id && $job->value === 1.0);
});

test('every context key the recommender reads survives, and the rest is dropped', function () {
    Queue::fake();

    app(InteractionRecorder::class)->record(User::factory()->create(), InteractionEvent::View, null, [
        'surface' => 'home', 'shelf' => 'trending-now', 'position' => 3, 'recommendation_id' => 9, 'search_query_id' => 4, 'referrer' => 'x',
    ]);

    Queue::assertPushed(RecordInteraction::class, fn (RecordInteraction $job): bool => array_keys($job->context) === ['surface', 'shelf', 'position', 'recommendation_id', 'search_query_id']);
});

test('an opted out user queues nothing', function () {
    Queue::fake();
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);

    app(InteractionRecorder::class)->record($user, InteractionEvent::View, Media::factory()->create());

    Queue::assertNothingPushed();
    expect(UserInteraction::count())->toBe(0);
});

test('an interaction waits for the open transaction to commit, and a rollback drops it', function () {
    $queue = recordingQueue();
    $user = User::factory()->create();
    $media = Media::factory()->create();

    try {
        DB::transaction(function () use ($queue, $user, $media): void {
            app(InteractionRecorder::class)->record($user, InteractionEvent::LibraryAdd, $media);

            expect($queue->pushed)->toBe([]);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // The transaction is gone, and so is what the reader did in it.
    }

    expect($queue->pushed)->toBe([]);

    DB::transaction(fn () => app(InteractionRecorder::class)->record($user, InteractionEvent::LibraryAdd, $media));

    expect($queue->pushed)->toBe([RecordInteraction::class]);
});

test('outside a transaction an interaction is queued at once', function () {
    $queue = recordingQueue();

    app(InteractionRecorder::class)->record(User::factory()->create(), InteractionEvent::View, Media::factory()->create());

    expect($queue->pushed)->toBe([RecordInteraction::class]);
});

test('the queued job inserts the row with the dispatch time', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    $this->travelTo(now()->setTime(10, 0));
    $job = new RecordInteraction($user->id, InteractionEvent::View, $media->id, [], null, now());

    $this->travelTo(now()->setTime(10, 5));
    $job->handle();

    $row = UserInteraction::sole();
    expect($row->event)->toBe(InteractionEvent::View)->and($row->occurred_at->format('H:i'))->toBe('10:00')
        ->and($row->user_id)->toBe($user->id)->and($row->media_id)->toBe($media->id)->and($row->context)->toBe([])->and($row->value)->toBeNull();
});

test('the job keeps the value and the context it was given', function () {
    $user = User::factory()->create();

    (new RecordInteraction($user->id, InteractionEvent::Click, null, ['surface' => 'search', 'search_query_id' => 3], 2.5, now()))->handle();

    $row = UserInteraction::sole();
    expect($row->value)->toBe(2.5)->and($row->context)->toBe(['surface' => 'search', 'search_query_id' => 3])->and($row->media_id)->toBeNull();
});

test('a reader who opted out after the event was queued leaves no row, and neither does one who was deleted', function () {
    [$optedOut, $deleted] = User::factory()->count(2)->create();
    $media = Media::factory()->create();
    $jobs = collect([$optedOut, $deleted])->map(fn (User $user) => new RecordInteraction($user->id, InteractionEvent::View, $media->id, [], null, now()));
    $optedOut->profile()->updateOrCreate([], ['personalization_enabled' => false]);
    $deleted->delete();

    $jobs->each->handle();

    expect(UserInteraction::count())->toBe(0);
});

test('interactions carry no ip or user agent column', function () {
    expect(Schema::getColumnListing('user_interactions'))->toBe(['id', 'user_id', 'media_id', 'event', 'value', 'context', 'occurred_at']);
});

test('the event names are exactly the specification', function () {
    expect(collect(InteractionEvent::cases())->map->value->all())->toBe([
        'search', 'view', 'click', 'library_add', 'reading_start', 'reading_progress', 'reading_complete', 'favorite', 'rating', 'review', 'abandon', 'recommendation_click',
    ]);
});

test('opening a story records a view for an opted in reader', function () {
    $media = Media::factory()->create();

    visitStory($media, User::factory()->create());

    $row = UserInteraction::sole();
    expect($row->event)->toBe(InteractionEvent::View)->and($row->media_id)->toBe($media->id);
});

test('opening a story records nothing for an opted out reader', function () {
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);

    visitStory(Media::factory()->create(), $user);

    expect(UserInteraction::count())->toBe(0);
});

test('a story behind the mature gate records nothing, for it shows nothing', function () {
    $media = Media::factory()->create();
    $media->genres()->attach(Genre::factory()->mature()->create());

    $this->actingAs(User::factory()->create())->get(route('stories.show', $media->slug))->assertForbidden();

    expect(UserInteraction::count())->toBe(0);
});

test('a result from the readers own search is credited as a click with the search id', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    $search = SearchQuery::factory()->create(['session_hash' => sessionHash(KNOWN_SESSION)]);

    visitStory($media, $user, '?sq='.$search->id);

    $click = UserInteraction::where('event', 'click')->sole();
    expect(interactionEvents())->toBe(['view', 'click'])
        ->and($click->context)->toBe(['surface' => 'search', 'search_query_id' => $search->id])
        ->and($click->media_id)->toBe($media->id);
});

test('a search id from another session, a missing one or a malformed one is never credited', function (string $sq) {
    $search = SearchQuery::factory()->create(['session_hash' => sessionHash(str_repeat('o', 40))]);
    $sq = str_replace('{id}', (string) $search->id, $sq);

    visitStory(Media::factory()->create(), User::factory()->create(), '?sq='.$sq);

    expect(interactionEvents())->toBe(['view']);
})->with([
    'another session' => '{id}',
    'no such search' => '999999999',
    'not a number' => 'abc',
    'a list' => '1,2',
    'negative' => '-1',
]);

test('an opted out reader opening a result is not credited either', function () {
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);
    $search = SearchQuery::factory()->create(['session_hash' => sessionHash(KNOWN_SESSION)]);

    visitStory(Media::factory()->create(), $user, '?sq='.$search->id);

    expect(UserInteraction::count())->toBe(0);
});
