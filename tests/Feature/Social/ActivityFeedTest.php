<?php

use App\Enums\ReviewState;
use App\Enums\Visibility;
use App\Models\Collection;
use App\Models\Follow;
use App\Models\Media;
use App\Models\Rating;
use App\Models\Review;
use App\Models\User;
use App\Social\ActivityFeed;
use App\Social\ActivityType;
use Illuminate\Support\Facades\DB;

function feedReader(string $handle, bool $library = true): User
{
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['handle' => $handle, 'profile_visibility' => Visibility::Public, 'library_visibility' => $library ? Visibility::Public : Visibility::Private]);

    return $user->fresh();
}

function feedFollow(User $viewer, User $target): void
{
    Follow::query()->insert(['follower_id' => $viewer->id, 'followed_id' => $target->id, 'created_at' => now()]);
}

function feedTypes(User $viewer): array
{
    return ActivityFeed::for($viewer)->events->map(fn ($e) => $e->type->name)->all();
}

function finishedAt(User $user, Media $media, $at)
{
    return libraryEntry($user, $media, 'completed')->forceFill(['completed_at' => $at])->save();
}

test('the feed has the four kinds of event from followed listed readers only', function () {
    $viewer = User::factory()->create();
    $ann = feedReader('ann');
    $stranger = feedReader('stranger');
    feedFollow($viewer, $ann);
    [$a, $b, $c] = Media::factory()->count(3)->create();

    finishedAt($ann, $a, now()->subMinutes(4));
    Rating::factory()->create(['user_id' => $ann->id, 'media_id' => $b->id, 'score' => 9, 'updated_at' => now()->subMinutes(3)]);
    Review::factory()->create(['user_id' => $ann->id, 'media_id' => $c->id, 'body' => 'A **fine** book.', 'created_at' => now()->subMinutes(2)]);
    Collection::factory()->public()->create(['user_id' => $ann->id, 'name' => 'Cosy', 'created_at' => now()->subMinute()]);
    finishedAt($stranger, $a, now());

    $events = ActivityFeed::for($viewer)->events;

    expect($events->map(fn ($e) => $e->type)->all())->toBe([ActivityType::MadeCollection, ActivityType::PublishedReview, ActivityType::RatedStory, ActivityType::FinishedStory])
        ->and($events[0]->collection)->toMatchArray(['name' => 'Cosy'])
        ->and($events[1]->excerpt)->toBe('A fine book.')
        ->and($events[1]->media['slug'])->toBe($c->slug)
        ->and($events[2]->media['id'])->toBe($b->id)
        ->and($events[3]->actor)->toMatchArray(['id' => $ann->id, 'name' => $ann->name, 'handle' => 'ann']);
});

test('no follows gives an empty feed', function () {
    $viewer = User::factory()->create();
    finishedAt(feedReader('ann'), Media::factory()->create(), now());

    $page = ActivityFeed::for($viewer);

    expect($page->events)->toBeEmpty()->and($page->nextCursor)->toBeNull();
});

test('a library going private removes completed and rated items at once, reviews and collections stay', function () {
    $viewer = User::factory()->create();
    $ann = feedReader('ann');
    feedFollow($viewer, $ann);
    [$a, $b, $c] = Media::factory()->count(3)->create();
    finishedAt($ann, $a, now());
    Rating::factory()->create(['user_id' => $ann->id, 'media_id' => $b->id, 'score' => 7]);
    Review::factory()->create(['user_id' => $ann->id, 'media_id' => $c->id]);
    expect(feedTypes($viewer))->toHaveCount(3);

    $ann->profile->update(['library_visibility' => Visibility::Private]);

    expect(feedTypes($viewer))->toBe(['PublishedReview']);
});

test('a spoiler review carries the flag and no body', function () {
    $viewer = User::factory()->create();
    $ann = feedReader('ann');
    feedFollow($viewer, $ann);
    Review::factory()->spoiler()->create(['user_id' => $ann->id, 'body' => 'The butler did it.']);

    $event = ActivityFeed::for($viewer)->events->first();

    expect($event->hasSpoilers)->toBeTrue()->and($event->excerpt)->toBeNull()
        ->and(json_encode($event))->not->toContain('butler');
});

test('a long review is cut to a plain excerpt of at most 200 characters', function () {
    $viewer = User::factory()->create();
    $ann = feedReader('ann');
    feedFollow($viewer, $ann);
    Review::factory()->create(['user_id' => $ann->id, 'body' => "# Title\n\n<b>".str_repeat('word ', 100).'</b>']);

    $excerpt = ActivityFeed::for($viewer)->events->first()->excerpt;

    expect(mb_strlen($excerpt))->toBeLessThanOrEqual(200)->and($excerpt)->not->toContain('<')->and($excerpt)->toStartWith('Title word');
});

test('hidden and deleted reviews are excluded', function () {
    $viewer = User::factory()->create();
    $ann = feedReader('ann');
    feedFollow($viewer, $ann);
    Review::factory()->hidden()->create(['user_id' => $ann->id]);
    $gone = Review::factory()->create(['user_id' => $ann->id]);
    $gone->delete();
    $live = Review::factory()->create(['user_id' => $ann->id]);

    $page = ActivityFeed::for($viewer);

    expect($page->events)->toHaveCount(1)
        ->and(Review::query()->where('status', ReviewState::Published)->count())->toBe(1);
});

test('deactivated, admin and private profile readers are excluded', function () {
    $viewer = User::factory()->create();
    $off = feedReader('off');
    $admin = feedReader('boss');
    $private = feedReader('shy');
    $ok = feedReader('ok');
    $hidden = feedReader('hidden');
    foreach ([$off, $admin, $private, $ok, $hidden] as $reader) {
        feedFollow($viewer, $reader);
        Review::factory()->create(['user_id' => $reader->id]);
    }
    $off->forceFill(['deactivated_at' => now()])->save();
    $admin->forceFill(['is_admin' => true])->save();
    $private->profile->update(['profile_visibility' => Visibility::Private]);
    $hidden->profile->forceFill(['moderation_hidden_at' => now()])->save();

    expect(ActivityFeed::for($viewer)->events->pluck('actor.handle')->all())->toBe(['ok']);
});

test('private, editorial and moderator-hidden collections are excluded', function () {
    $viewer = User::factory()->create();
    $ann = feedReader('ann');
    feedFollow($viewer, $ann);
    Collection::factory()->create(['user_id' => $ann->id]);
    Collection::factory()->public()->editorial()->create(['user_id' => $ann->id]);
    Collection::factory()->public()->create(['user_id' => $ann->id])->forceFill(['moderation_hidden_at' => now()])->save();
    $shown = Collection::factory()->public()->create(['user_id' => $ann->id]);
    $cover = Media::factory()->create(['cover_url' => 'https://example.test/c.jpg']);
    $shown->items()->create(['media_id' => $cover->id, 'position' => 1]);

    $events = ActivityFeed::for($viewer)->events;

    expect($events)->toHaveCount(1)->and($events[0]->collection['id'])->toBe($shown->id)->and($events[0]->collection['cover'])->toBe('https://example.test/c.jpg');
});

test('gated, deleted and merged media are excluded until the viewer opts in', function () {
    $gated = User::factory()->create();
    $open = User::factory()->showsMature()->create();
    $ann = feedReader('ann');
    feedFollow($gated, $ann);
    feedFollow($open, $ann);
    $mature = matureStory();
    $deleted = Media::factory()->create();
    finishedAt($ann, $mature, now());
    Review::factory()->create(['user_id' => $ann->id, 'media_id' => $mature->id]);
    finishedAt($ann, $deleted, now());
    $deleted->delete();

    expect(ActivityFeed::for($gated)->events)->toBeEmpty()
        ->and(ActivityFeed::for($open)->events)->toHaveCount(2);
});

test('pages are newest first, ties are stable and new events between pages do not shift them', function () {
    $viewer = User::factory()->create();
    $ann = feedReader('ann');
    $bob = feedReader('bob');
    feedFollow($viewer, $ann);
    feedFollow($viewer, $bob);
    $same = now()->subHour();
    $ids = [];
    foreach (range(1, 7) as $i) {
        $ids[] = Review::factory()->create(['user_id' => $i % 2 ? $ann->id : $bob->id, 'created_at' => $i <= 4 ? $same : now()->subHours(2)])->id;
    }

    $first = ActivityFeed::for($viewer, 3);
    Review::factory()->create(['user_id' => $ann->id, 'created_at' => now()]);
    $second = ActivityFeed::for($viewer, 3, $first->nextCursor);
    $third = ActivityFeed::for($viewer, 3, $second->nextCursor);
    $all = ActivityFeed::for($viewer, 50);

    expect($first->events)->toHaveCount(3)->and($first->hasMore())->toBeTrue()
        ->and($third->events)->toHaveCount(1)->and($third->nextCursor)->toBeNull()
        ->and($all->events)->toHaveCount(8);
    $seen = [...$first->events, ...$second->events, ...$third->events];
    expect($seen)->toHaveCount(7)
        ->and(collect($seen)->map(fn ($e) => $e->at->timestamp)->all())->toBe(collect($seen)->map(fn ($e) => $e->at->timestamp)->sortDesc()->values()->all());
});

test('a malformed cursor starts from the newest', function () {
    $viewer = User::factory()->create();
    $ann = feedReader('ann');
    feedFollow($viewer, $ann);
    Review::factory()->create(['user_id' => $ann->id]);

    expect(ActivityFeed::for($viewer, 20, 'garbage')->events)->toHaveCount(1);
});

test('a page takes at most 8 queries', function () {
    $viewer = User::factory()->create();
    $ann = feedReader('ann');
    feedFollow($viewer, $ann);
    $collection = Collection::factory()->public()->create(['user_id' => $ann->id]);
    $collection->items()->create(['media_id' => Media::factory()->create()->id, 'position' => 1]);
    finishedAt($ann, Media::factory()->create(), now());
    Rating::factory()->create(['user_id' => $ann->id, 'media_id' => Media::factory()->create()->id, 'score' => 9]);
    Review::factory()->count(3)->create(['user_id' => $ann->id]);

    DB::enableQueryLog();
    $page = ActivityFeed::for($viewer, 20);
    $queries = count(DB::getQueryLog());

    expect($page->events)->toHaveCount(6)->and($queries)->toBeLessThanOrEqual(8);
});
