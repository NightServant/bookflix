<?php

use App\Catalog\Ingestion\Deduplicator;
use App\Enums\InteractionEvent;
use App\Enums\ReadingStatus;
use App\Jobs\RecalculateMediaRating;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\Rating;
use App\Models\ReadingProgress;
use App\Models\ReadingSession;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

test('merging repoints user rows without unique key crashes', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    $solo = User::factory()->create();
    $both = User::factory()->create();
    LibraryEntry::factory()->create(['user_id' => $solo->id, 'media_id' => $loser->id]);
    LibraryEntry::factory()->create(['user_id' => $both->id, 'media_id' => $loser->id, 'status' => ReadingStatus::Reading]);
    LibraryEntry::factory()->create(['user_id' => $both->id, 'media_id' => $winner->id, 'status' => ReadingStatus::Completed]);
    Rating::factory()->create(['user_id' => $solo->id, 'media_id' => $loser->id, 'score' => 6]);
    UserInteraction::factory()->create(['user_id' => $solo->id, 'media_id' => $loser->id]);

    app(Deduplicator::class)->merge($winner, $loser);

    expect(LibraryEntry::where('media_id', $winner->id)->count())->toBe(2)
        ->and(LibraryEntry::where('media_id', $loser->id)->count())->toBe(0)
        ->and(LibraryEntry::where(['user_id' => $both->id, 'media_id' => $winner->id])->sole()->status)->toBe(ReadingStatus::Completed)
        ->and(LibraryEntry::where(['user_id' => $solo->id, 'media_id' => $winner->id])->count())->toBe(1)
        ->and(Rating::where('media_id', $winner->id)->count())->toBe(1)
        ->and(UserInteraction::where('media_id', $winner->id)->count())->toBe(1);
});

test('a reader with a row for both stories keeps the winner\'s rating and progress, the others move over', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    $both = User::factory()->create();
    $solo = User::factory()->create();
    Rating::factory()->create(['user_id' => $both->id, 'media_id' => $winner->id, 'score' => 9]);
    Rating::factory()->create(['user_id' => $both->id, 'media_id' => $loser->id, 'score' => 2]);
    Rating::factory()->create(['user_id' => $solo->id, 'media_id' => $loser->id, 'score' => 4]);
    ReadingProgress::factory()->create(['user_id' => $both->id, 'media_id' => $winner->id, 'percent' => 30]);
    ReadingProgress::factory()->create(['user_id' => $both->id, 'media_id' => $loser->id, 'percent' => 80]);
    ReadingProgress::factory()->create(['user_id' => $solo->id, 'media_id' => $loser->id, 'percent' => 55]);

    app(Deduplicator::class)->merge($winner, $loser);

    expect(Rating::where('media_id', $loser->id)->count())->toBe(0)
        ->and(Rating::where(['user_id' => $both->id, 'media_id' => $winner->id])->sole()->score)->toBe(9)
        ->and(Rating::where(['user_id' => $solo->id, 'media_id' => $winner->id])->sole()->score)->toBe(4)
        ->and(ReadingProgress::where('media_id', $loser->id)->count())->toBe(0)
        ->and(ReadingProgress::where(['user_id' => $both->id, 'media_id' => $winner->id])->sole()->percent)->toBe('30.00')
        ->and(ReadingProgress::where(['user_id' => $solo->id, 'media_id' => $winner->id])->sole()->percent)->toBe('55.00');
});

test('reading history and interactions always repoint, even when the reader has both stories', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    $user = User::factory()->create();
    ReadingSession::factory()->count(2)->create(['user_id' => $user->id, 'media_id' => $winner->id]);
    ReadingSession::factory()->count(3)->create(['user_id' => $user->id, 'media_id' => $loser->id]);
    UserInteraction::factory()->create(['user_id' => $user->id, 'media_id' => $winner->id, 'event' => InteractionEvent::View]);
    UserInteraction::factory()->count(2)->create(['user_id' => $user->id, 'media_id' => $loser->id, 'event' => InteractionEvent::Rating]);

    app(Deduplicator::class)->merge($winner, $loser);

    expect(ReadingSession::where('media_id', $winner->id)->count())->toBe(5)
        ->and(ReadingSession::where('media_id', $loser->id)->count())->toBe(0)
        ->and(UserInteraction::where('media_id', $winner->id)->count())->toBe(3)
        ->and(UserInteraction::where('media_id', $loser->id)->count())->toBe(0);
});

test('collection items for both stories in one collection collapse to one', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    $collection = Collection::factory()->create();
    CollectionItem::factory()->for($collection)->create(['media_id' => $winner->id, 'position' => 0]);
    CollectionItem::factory()->for($collection)->create(['media_id' => $loser->id, 'position' => 1]);

    app(Deduplicator::class)->merge($winner, $loser);

    expect($collection->items()->count())->toBe(1)
        ->and($collection->items()->sole()->media_id)->toBe($winner->id);
});

test('a collapsed collection closes the gap and keeps its other positions contiguous', function () {
    [$winner, $loser, $a, $b, $c] = Media::factory()->count(5)->create();
    $reader = User::factory()->create();
    $collapsed = collectionOf($reader, [$a, $loser, $b, $winner, $c]);
    $untouched = collectionOf($reader, [$a, $winner, $b]);
    $moved = collectionOf($reader, [$a, $loser, $c]);

    app(Deduplicator::class)->merge($winner, $loser);

    $order = fn (Collection $collection): array => $collection->items()->orderBy('position')->pluck('media_id', 'position')->all();

    expect($order($collapsed))->toBe([0 => $a->id, 1 => $b->id, 2 => $winner->id, 3 => $c->id])
        ->and($order($untouched))->toBe([0 => $a->id, 1 => $winner->id, 2 => $b->id])
        ->and($order($moved))->toBe([0 => $a->id, 1 => $winner->id, 2 => $c->id])
        ->and(CollectionItem::where('media_id', $loser->id)->count())->toBe(0);
});

test('two merges in a row can chain without a duplicate key', function () {
    [$final, $middle, $first] = Media::factory()->count(3)->create();
    $user = User::factory()->create();
    LibraryEntry::factory()->create(['user_id' => $user->id, 'media_id' => $first->id]);
    Rating::factory()->create(['user_id' => $user->id, 'media_id' => $middle->id, 'score' => 7]);

    app(Deduplicator::class)->merge($middle, $first);
    app(Deduplicator::class)->merge($final, $middle);

    expect(LibraryEntry::where(['user_id' => $user->id, 'media_id' => $final->id])->count())->toBe(1)
        ->and(Rating::where(['user_id' => $user->id, 'media_id' => $final->id])->sole()->score)->toBe(7);
});

test('the winner\'s reader rating is recalculated after the merge, from the scores it now holds', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    Rating::factory()->create(['media_id' => $winner->id, 'score' => 10]);
    Rating::factory()->create(['media_id' => $loser->id, 'score' => 6]);
    Rating::factory()->create(['media_id' => $loser->id, 'score' => 4]);
    (new RecalculateMediaRating($winner->id))->handle();
    (new RecalculateMediaRating($loser->id))->handle();
    expect(bookflixMetric($winner, 'ratings_count'))->toBe(1.0)->and(bookflixMetric($loser, 'ratings_count'))->toBe(2.0);

    app(Deduplicator::class)->merge($winner, $loser);

    expect(bookflixMetric($winner, 'ratings_count'))->toBe(3.0)
        ->and(bookflixMetric($winner, 'average_rating'))->toBe(3.33)
        ->and($winner->fresh()->ratings_count)->toBe(3)
        ->and((float) $winner->fresh()->average_rating)->toBe(3.33);
});

test('the loser\'s reader rating rows are removed with the merge', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    Rating::factory()->create(['media_id' => $loser->id, 'score' => 8]);
    (new RecalculateMediaRating($loser->id))->handle();
    $other = providerMetrics($loser, 'anilist', ['average_rating' => 4.1]);

    app(Deduplicator::class)->merge($winner, $loser);

    expect(bookflixMetric($loser, 'ratings_count'))->toBeNull()
        ->and(bookflixMetric($loser, 'average_rating'))->toBeNull()
        ->and(DB::table('media_metrics')->where('media_id', $loser->id)->where('external_source_id', $other->id)->count())->toBe(1);
});

test('the recalculation is queued for the winner, and only once the merge has committed', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    $ran = [];
    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$ran): void {
        $ran[] = [$event->job->resolveName(), DB::transactionLevel()];
    });

    app(Deduplicator::class)->merge($winner, $loser);

    // The test itself runs inside one transaction, so level 1 is "no merge transaction open any more".
    expect($ran)->toBe([[RecalculateMediaRating::class, 1]]);
});

test('the job is aimed at the winner', function () {
    Queue::fake();
    [$winner, $loser] = Media::factory()->count(2)->create();

    app(Deduplicator::class)->merge($winner, $loser);

    Queue::assertPushed(RecalculateMediaRating::class, 1);
    Queue::assertPushed(RecalculateMediaRating::class, fn (RecalculateMediaRating $job): bool => $job->mediaId === $winner->id);
});

test('a winner nobody rated keeps the rating columns it had when the merge brings no scores', function () {
    $winner = Media::factory()->create(['average_rating' => '4.20', 'ratings_count' => 30]);
    $loser = Media::factory()->create();

    app(Deduplicator::class)->merge($winner, $loser);

    expect((float) $winner->fresh()->average_rating)->toBe(4.2)->and($winner->fresh()->ratings_count)->toBe(30);
});

test('a merge that did nothing queues nothing and moves nothing', function () {
    Queue::fake();
    [$winner, $loser] = Media::factory()->count(2)->create();
    $user = User::factory()->create();
    $loser->update(['merged_into_id' => $winner->id]);
    $loser->delete();
    LibraryEntry::factory()->create(['user_id' => $user->id, 'media_id' => $loser->id]);

    app(Deduplicator::class)->merge($winner, $loser);
    app(Deduplicator::class)->merge($winner, $winner);

    Queue::assertNothingPushed();
    expect(LibraryEntry::where('media_id', $loser->id)->count())->toBe(1);
});

test('a merge inside a transaction that rolls back moves nothing and runs no job', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    $user = User::factory()->create();
    LibraryEntry::factory()->create(['user_id' => $user->id, 'media_id' => $loser->id]);
    Rating::factory()->create(['user_id' => $user->id, 'media_id' => $loser->id]);
    $ran = 0;
    Event::listen(JobProcessing::class, function () use (&$ran): void {
        $ran++;
    });

    expect(fn () => DB::transaction(function () use ($winner, $loser): void {
        app(Deduplicator::class)->merge($winner, $loser);

        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect($ran)->toBe(0)
        ->and(LibraryEntry::where(['user_id' => $user->id, 'media_id' => $loser->id])->count())->toBe(1)
        ->and(Rating::where(['user_id' => $user->id, 'media_id' => $loser->id])->count())->toBe(1)
        ->and($loser->fresh()->trashed())->toBeFalse();
});
