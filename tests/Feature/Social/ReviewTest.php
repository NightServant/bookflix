<?php

use App\Actions\Social\DeleteReview;
use App\Actions\Social\SaveReview;
use App\Enums\InteractionEvent;
use App\Enums\ReviewState;
use App\Jobs\RecordInteraction;
use App\Models\Media;
use App\Models\Review;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function reviewError(User $user, Media $media, string $body = 'Good.', bool $spoilers = false): ?array
{
    try {
        app(SaveReview::class)->handle($user, $media, $body, $spoilers);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

test('one review per user per story, and saving again edits it', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    $first = app(SaveReview::class)->handle($user, $media, 'First take', false);
    $second = app(SaveReview::class)->handle($user, $media, 'Second take', true);

    expect(Review::query()->count())->toBe(1)
        ->and($second->is($first))->toBeTrue()
        ->and($first->fresh()->body)->toBe('Second take')
        ->and($first->fresh()->has_spoilers)->toBeTrue()
        ->and($first->fresh()->status)->toBe(ReviewState::Published);
});

test('the database itself allows one live review per user and story', function () {
    $review = Review::factory()->create();

    expect(fn () => Review::factory()->create(['user_id' => $review->user_id, 'media_id' => $review->media_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the body is required and at most 5000 characters after trimming', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    expect(reviewError($user, $media, "  \n "))->toHaveKey('body')
        ->and(reviewError($user, $media, str_repeat('a', 5001)))->toHaveKey('body')
        ->and(reviewError($user, $media, str_repeat('é', 5000)))->toBeNull()
        ->and(reviewError($user, $media, ' '.str_repeat('a', 5000).' '))->toBeNull()
        ->and(Review::query()->first()->body)->toHaveLength(5000);
});

test('editing a hidden review keeps it hidden', function () {
    $review = Review::factory()->hidden()->create();

    app(SaveReview::class)->handle($review->user, $review->media, 'Changed', false);

    expect($review->fresh()->status)->toBe(ReviewState::Hidden)->and($review->fresh()->body)->toBe('Changed');
});

test('a deleted review leaves no live one, and the story can be reviewed again as published', function () {
    $review = Review::factory()->hidden()->create();

    app(DeleteReview::class)->handle($review->user, $review);

    expect(Review::query()->count())->toBe(0)->and(Review::withTrashed()->count())->toBe(1);

    $again = app(SaveReview::class)->handle($review->user, $review->media, 'Back again', false);

    expect($again->is($review))->toBeFalse()
        ->and($again->status)->toBe(ReviewState::Published)
        ->and(Review::query()->count())->toBe(1);
});

test('only the author edits or deletes (policy), and the action enforces it', function () {
    $review = Review::factory()->create();
    $other = User::factory()->create();

    expect(Gate::forUser($review->user)->allows('update', $review))->toBeTrue()
        ->and(Gate::forUser($review->user)->allows('delete', $review))->toBeTrue()
        ->and(Gate::forUser($other)->allows('update', $review))->toBeFalse()
        ->and(Gate::forUser($other)->allows('delete', $review))->toBeFalse()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('delete', $review))->toBeFalse();

    expect(fn () => app(DeleteReview::class)->handle($other, $review))->toThrow(AuthorizationException::class)
        ->and(Review::query()->count())->toBe(1);
});

test('a hidden review is visible only to its author, and the view policy follows', function () {
    $media = Media::factory()->create();
    $hidden = Review::factory()->hidden()->create(['media_id' => $media->id]);
    $shown = Review::factory()->create(['media_id' => $media->id]);
    $viewer = User::factory()->create();

    expect(Review::query()->visibleOnStory($media, $viewer)->pluck('id')->all())->toBe([$shown->id])
        ->and(Review::query()->visibleOnStory($media, null)->pluck('id')->all())->toBe([$shown->id])
        ->and(Review::query()->visibleOnStory($media, $hidden->user)->pluck('id')->sort()->values()->all())->toBe([$hidden->id, $shown->id])
        ->and(Gate::forUser($viewer)->allows('view', $hidden))->toBeFalse()
        ->and(Gate::forUser($hidden->user)->allows('view', $hidden))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('view', $shown))->toBeTrue();
});

test('reviews by deactivated and admin authors are not shown, and a soft-deleted one is gone', function () {
    $media = Media::factory()->create();
    $gone = Review::factory()->create(['media_id' => $media->id, 'user_id' => User::factory()->deactivated()]);
    $admin = Review::factory()->create(['media_id' => $media->id, 'user_id' => User::factory()->admin()]);
    $deleted = Review::factory()->create(['media_id' => $media->id]);
    $deleted->delete();
    $kept = Review::factory()->create(['media_id' => $media->id]);
    $viewer = User::factory()->create();

    expect(Review::query()->visibleOnStory($media, $viewer)->pluck('id')->all())->toBe([$kept->id])
        ->and(Gate::forUser($viewer)->allows('view', $gone))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('view', $admin))->toBeFalse();
});

test('reviews come newest first', function () {
    $media = Media::factory()->create();
    $old = Review::factory()->create(['media_id' => $media->id, 'created_at' => now()->subDay()]);
    $new = Review::factory()->create(['media_id' => $media->id]);

    expect(Review::query()->visibleOnStory($media, null)->pluck('id')->all())->toBe([$new->id, $old->id]);
});

test('an admin or a deactivated reader cannot review', function () {
    $media = Media::factory()->create();

    expect(reviewError(User::factory()->admin()->create(), $media))->toHaveKey('review')
        ->and(reviewError(User::factory()->deactivated()->create(), $media))->toHaveKey('review')
        ->and(Review::query()->count())->toBe(0);
});

test('a story behind the mature gate is refused unless the reader opted in', function () {
    $story = matureStory();
    $reader = User::factory()->create();

    expect(reviewError($reader, $story))->toHaveKey('review')->and(Review::query()->count())->toBe(0);

    expect(reviewError(User::factory()->showsMature()->create(), $story))->toBeNull()->and(Review::query()->count())->toBe(1);
});

test('creating a review records one review interaction, editing records none', function () {
    Queue::fake();
    $user = User::factory()->create();
    $media = Media::factory()->create();

    app(SaveReview::class)->handle($user, $media, 'One', false);
    app(SaveReview::class)->handle($user, $media, 'Two', false);

    Queue::assertPushed(RecordInteraction::class, 1);
    Queue::assertPushed(RecordInteraction::class, fn (RecordInteraction $job): bool => $job->event === InteractionEvent::Review && $job->mediaId === $media->id);
});

test('an opted-out reader gets the review stored and no interaction row', function () {
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);

    app(SaveReview::class)->handle($user->fresh(), Media::factory()->create(), 'Private thoughts', false);

    expect(Review::query()->count())->toBe(1)->and(UserInteraction::query()->count())->toBe(0);
});

test('a reader may save ten reviews an hour', function () {
    $user = User::factory()->create();

    foreach (range(1, 10) as $i) {
        app(SaveReview::class)->handle($user, Media::factory()->create(), "Review {$i}", false);
    }

    expect(reviewError($user, Media::factory()->create()))->toHaveKey('review')
        ->and(Review::query()->count())->toBe(10);

    // Another reader is not held back.
    expect(reviewError(User::factory()->create(), Media::factory()->create()))->toBeNull();
});

test('Unicode, emoji and right-to-left text are saved intact', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    $body = "Très bien 📚🔥 — 日本語のレビュー\nمراجعة رائعة \u{202B}עברית\u{202C} ñ";

    $review = app(SaveReview::class)->handle($user, $media, $body, false);

    expect($review->fresh()->body)->toBe($body);
});
