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

test('a reader with a row for both stories keeps the winner\'s rating and the further progress, the others move over', function () {
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
        ->and(ReadingProgress::where(['user_id' => $both->id, 'media_id' => $winner->id])->sole()->percent)->toBe('80.00')
        ->and(ReadingProgress::where(['user_id' => $solo->id, 'media_id' => $winner->id])->sole()->percent)->toBe('55.00');
});

test('a reader with both stories in the library keeps the winner\'s row and gains the loser\'s favorite', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    [$gains, $keeps, $neither] = User::factory()->count(3)->create();
    $liked = now()->subDays(3)->startOfSecond();
    $earlier = now()->subDays(1)->startOfSecond();

    LibraryEntry::factory()->create(['user_id' => $gains->id, 'media_id' => $winner->id, 'status' => ReadingStatus::Completed, 'favorited_at' => null]);
    LibraryEntry::factory()->create(['user_id' => $gains->id, 'media_id' => $loser->id, 'status' => ReadingStatus::WantToRead, 'favorited_at' => $liked]);
    LibraryEntry::factory()->create(['user_id' => $keeps->id, 'media_id' => $winner->id, 'favorited_at' => $earlier]);
    LibraryEntry::factory()->create(['user_id' => $keeps->id, 'media_id' => $loser->id, 'favorited_at' => $liked]);
    LibraryEntry::factory()->create(['user_id' => $neither->id, 'media_id' => $winner->id, 'favorited_at' => null]);
    LibraryEntry::factory()->create(['user_id' => $neither->id, 'media_id' => $loser->id, 'favorited_at' => null]);

    app(Deduplicator::class)->merge($winner, $loser);

    $row = fn (User $user): LibraryEntry => LibraryEntry::where(['user_id' => $user->id, 'media_id' => $winner->id])->sole();

    expect($row($gains)->favorited_at->equalTo($liked))->toBeTrue()
        ->and($row($gains)->status)->toBe(ReadingStatus::Completed) // the winner's row is the one that stays
        ->and($row($keeps)->favorited_at->equalTo($earlier))->toBeTrue() // a favorite the winner has is not replaced
        ->and($row($neither)->favorited_at)->toBeNull()
        ->and(LibraryEntry::where('media_id', $loser->id)->count())->toBe(0);
});

test('the winner\'s library row takes the earlier added and started dates, and the loser\'s completion only when it has none', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    [$first, $second, $third] = User::factory()->count(3)->create();
    $this->freezeSecond();
    $day = fn (int $ago) => now()->subDays($ago)->startOfSecond();

    // The loser's row is the older one, started earlier and finished; the winner's has no completion.
    LibraryEntry::factory()->create(['user_id' => $first->id, 'media_id' => $winner->id, 'status' => ReadingStatus::Reading, 'created_at' => $day(5), 'started_at' => $day(4), 'completed_at' => null]);
    LibraryEntry::factory()->create(['user_id' => $first->id, 'media_id' => $loser->id, 'status' => ReadingStatus::Completed, 'created_at' => $day(20), 'started_at' => $day(19), 'completed_at' => $day(10)]);
    // The winner's row is the older one and has its own completion, which stays.
    LibraryEntry::factory()->create(['user_id' => $second->id, 'media_id' => $winner->id, 'status' => ReadingStatus::Completed, 'created_at' => $day(30), 'started_at' => $day(29), 'completed_at' => $day(25)]);
    LibraryEntry::factory()->create(['user_id' => $second->id, 'media_id' => $loser->id, 'status' => ReadingStatus::Completed, 'created_at' => $day(8), 'started_at' => $day(7), 'completed_at' => $day(2)]);
    // Only one of them was ever started: the date that exists is kept, and no date is made up.
    LibraryEntry::factory()->create(['user_id' => $third->id, 'media_id' => $winner->id, 'created_at' => $day(6), 'started_at' => null, 'completed_at' => null]);
    LibraryEntry::factory()->create(['user_id' => $third->id, 'media_id' => $loser->id, 'created_at' => $day(9), 'started_at' => $day(8), 'completed_at' => null]);

    app(Deduplicator::class)->merge($winner, $loser);

    $row = fn (User $user): LibraryEntry => LibraryEntry::where(['user_id' => $user->id, 'media_id' => $winner->id])->sole();

    expect($row($first)->created_at->equalTo($day(20)))->toBeTrue()
        ->and($row($first)->started_at->equalTo($day(19)))->toBeTrue()
        ->and($row($first)->completed_at->equalTo($day(10)))->toBeTrue()
        ->and($row($first)->status)->toBe(ReadingStatus::Reading)
        ->and($row($second)->created_at->equalTo($day(30)))->toBeTrue()
        ->and($row($second)->started_at->equalTo($day(29)))->toBeTrue()
        ->and($row($second)->completed_at->equalTo($day(25)))->toBeTrue()
        ->and($row($third)->created_at->equalTo($day(9)))->toBeTrue()
        ->and($row($third)->started_at->equalTo($day(8)))->toBeTrue()
        ->and($row($third)->completed_at)->toBeNull();
});

test('a reader with progress on both stories keeps the further one, with its page and chapter, and the later last read', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    [$further, $behind, $even] = User::factory()->count(3)->create();
    $this->freezeSecond();
    $day = fn (int $ago) => now()->subDays($ago)->startOfSecond();

    ReadingProgress::factory()->create(['user_id' => $further->id, 'media_id' => $winner->id, 'percent' => 30, 'page' => 60, 'chapter' => '3', 'last_read_at' => $day(1)]);
    ReadingProgress::factory()->create(['user_id' => $further->id, 'media_id' => $loser->id, 'percent' => 80, 'page' => 240, 'chapter' => '9', 'last_read_at' => $day(10)]);
    ReadingProgress::factory()->create(['user_id' => $behind->id, 'media_id' => $winner->id, 'percent' => 70, 'page' => 140, 'chapter' => '7', 'last_read_at' => $day(9)]);
    ReadingProgress::factory()->create(['user_id' => $behind->id, 'media_id' => $loser->id, 'percent' => 20, 'page' => 40, 'chapter' => '2', 'last_read_at' => $day(2)]);
    ReadingProgress::factory()->create(['user_id' => $even->id, 'media_id' => $winner->id, 'percent' => 50, 'page' => 100, 'chapter' => 'W', 'last_read_at' => $day(4)]);
    ReadingProgress::factory()->create(['user_id' => $even->id, 'media_id' => $loser->id, 'percent' => 50, 'page' => 101, 'chapter' => 'L', 'last_read_at' => $day(3)]);

    app(Deduplicator::class)->merge($winner, $loser);

    $row = fn (User $user): ReadingProgress => ReadingProgress::where(['user_id' => $user->id, 'media_id' => $winner->id])->sole();

    // The loser's row was further along: its place moves to the winner's row as a whole, and the later time stays (the winner's, day 1).
    expect($row($further)->percent)->toBe('80.00')->and($row($further)->page)->toBe(240)->and($row($further)->chapter)->toBe('9')
        ->and($row($further)->last_read_at->equalTo($day(1)))->toBeTrue()
        // The winner's row was further along: its place stays, and the later time (the loser's, day 2) is the one kept.
        ->and($row($behind)->percent)->toBe('70.00')->and($row($behind)->page)->toBe(140)->and($row($behind)->chapter)->toBe('7')
        ->and($row($behind)->last_read_at->equalTo($day(2)))->toBeTrue()
        // A tie keeps the winner's place, and the later time (the loser's, day 3).
        ->and($row($even)->percent)->toBe('50.00')->and($row($even)->page)->toBe(100)->and($row($even)->chapter)->toBe('W')
        ->and($row($even)->last_read_at->equalTo($day(3)))->toBeTrue()
        ->and(ReadingProgress::where('media_id', $loser->id)->count())->toBe(0);
});

test('carrying a favorite or progress over touches only the readers who have both stories', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    [$both, $winnerOnly, $loserOnly] = User::factory()->count(3)->create();
    $liked = now()->subDay()->startOfSecond();

    LibraryEntry::factory()->create(['user_id' => $both->id, 'media_id' => $winner->id]);
    LibraryEntry::factory()->create(['user_id' => $both->id, 'media_id' => $loser->id, 'favorited_at' => $liked]);
    LibraryEntry::factory()->create(['user_id' => $winnerOnly->id, 'media_id' => $winner->id]);
    LibraryEntry::factory()->create(['user_id' => $loserOnly->id, 'media_id' => $loser->id, 'favorited_at' => $liked]);
    ReadingProgress::factory()->create(['user_id' => $winnerOnly->id, 'media_id' => $winner->id, 'percent' => 12]);
    ReadingProgress::factory()->create(['user_id' => $loserOnly->id, 'media_id' => $loser->id, 'percent' => 66]);

    app(Deduplicator::class)->merge($winner, $loser);

    expect(LibraryEntry::where(['user_id' => $winnerOnly->id, 'media_id' => $winner->id])->sole()->favorited_at)->toBeNull()
        ->and(LibraryEntry::where(['user_id' => $loserOnly->id, 'media_id' => $winner->id])->sole()->favorited_at->equalTo($liked))->toBeTrue()
        ->and(ReadingProgress::where(['user_id' => $winnerOnly->id, 'media_id' => $winner->id])->sole()->percent)->toBe('12.00')
        ->and(ReadingProgress::where(['user_id' => $loserOnly->id, 'media_id' => $winner->id])->sole()->percent)->toBe('66.00')
        ->and(LibraryEntry::where('media_id', $winner->id)->count())->toBe(3);
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
