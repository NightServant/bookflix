<?php

use App\Actions\Social\FollowUser;
use App\Actions\Social\UnfollowUser;
use App\Enums\Visibility;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

function followable(string $handle): User
{
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['handle' => $handle, 'profile_visibility' => Visibility::Public]);

    return $user->fresh();
}

function followError(User $follower, User $target): ?string
{
    try {
        app(FollowUser::class)->handle($follower, $target);
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('follow');

        return $e->errors()['follow'][0];
    }

    return null;
}

test('following creates one row, and following again leaves one', function () {
    $reader = User::factory()->create();
    $target = followable('target_one');

    app(FollowUser::class)->handle($reader, $target);
    app(FollowUser::class)->handle($reader, $target);

    expect(Follow::query()->count())->toBe(1)
        ->and($reader->following()->pluck('users.id')->all())->toBe([$target->id])
        ->and($target->followers()->pluck('users.id')->all())->toBe([$reader->id]);
});

test('following yourself is refused', function () {
    $me = followable('me_myself');

    expect(followError($me, $me))->not->toBeNull()->and(Follow::query()->count())->toBe(0);
});

test('a private, deactivated, admin or moderator-hidden target is refused alike', function () {
    $reader = User::factory()->create();

    $private = followable('is_private');
    $private->profile()->update(['profile_visibility' => Visibility::Private]);
    $gone = followable('is_gone');
    $gone->forceFill(['deactivated_at' => now()])->save();
    $admin = followable('an_admin');
    $admin->forceFill(['is_admin' => true])->save();
    $hidden = followable('is_hidden');
    $hidden->profile()->update(['moderation_hidden_at' => now()]);

    $messages = collect([$private, $gone, $admin, $hidden])->map(fn (User $t) => followError($reader, $t->fresh()));

    expect($messages->unique()->all())->toHaveCount(1)->and($messages->first())->not->toBeNull()
        ->and(Follow::query()->count())->toBe(0);
});

test('an admin or deactivated reader follows no one', function () {
    $target = followable('target_one');

    expect(followError(User::factory()->admin()->create(), $target))->not->toBeNull()
        ->and(followError(User::factory()->deactivated()->create(), $target))->not->toBeNull()
        ->and(Follow::query()->count())->toBe(0);
});

test('unfollowing is idempotent and always allowed', function () {
    $reader = User::factory()->create();
    $target = followable('target_one');
    app(FollowUser::class)->handle($reader, $target);

    app(UnfollowUser::class)->handle($reader, $target);
    app(UnfollowUser::class)->handle($reader, $target);
    expect(Follow::query()->count())->toBe(0);

    // Even once the target is gone from view.
    app(FollowUser::class)->handle($reader, $target);
    $target->profile()->update(['profile_visibility' => Visibility::Private]);
    app(UnfollowUser::class)->handle($reader, $target->fresh());
    expect(Follow::query()->count())->toBe(0);
});

test('unfollowing leaves other follows alone', function () {
    $reader = User::factory()->create();
    $a = followable('target_a');
    $b = followable('target_b');
    app(FollowUser::class)->handle($reader, $a);
    app(FollowUser::class)->handle($reader, $b);

    app(UnfollowUser::class)->handle($reader, $a);

    expect(Follow::query()->pluck('followed_id')->all())->toBe([$b->id]);
});

test('lists and counts hold only active, non-admin, public, unhidden readers, for everyone', function () {
    $owner = followable('the_owner');
    $good = followable('good_one');
    $private = followable('is_private');
    $gone = followable('is_gone');
    $admin = followable('an_admin');
    $hidden = followable('is_hidden');

    foreach ([$good, $private, $gone, $admin, $hidden] as $follower) {
        Follow::factory()->create(['follower_id' => $follower->id, 'followed_id' => $owner->id]);
        Follow::factory()->create(['follower_id' => $owner->id, 'followed_id' => $follower->id]);
    }

    $private->profile()->update(['profile_visibility' => Visibility::Private]);
    $gone->forceFill(['deactivated_at' => now()])->save();
    $admin->forceFill(['is_admin' => true])->save();
    $hidden->profile()->update(['moderation_hidden_at' => now()]);

    expect($owner->listedFollowers()->pluck('users.id')->all())->toBe([$good->id])
        ->and($owner->listedFollowing()->pluck('users.id')->all())->toBe([$good->id])
        ->and($owner->listedFollowers()->count())->toBe(1)
        ->and($owner->listedFollowing()->count())->toBe(1)
        ->and($owner->followers()->count())->toBe(5);
});

test('a private reader is not in their own followers count either', function () {
    $private = User::factory()->create();
    $target = followable('target_one');
    Follow::factory()->create(['follower_id' => $private->id, 'followed_id' => $target->id]);

    expect($target->listedFollowers()->count())->toBe(0);
});

test('sixty follows an hour, then refused until the hour is up', function () {
    $reader = User::factory()->create();

    foreach (range(1, 60) as $i) {
        app(FollowUser::class)->handle($reader, followable("target_{$i}"));
    }

    $extra = followable('target_extra');
    expect(followError($reader, $extra))->not->toBeNull()
        ->and(Follow::query()->count())->toBe(60);

    // Following someone already followed costs nothing and still succeeds.
    expect(followError($reader, User::query()->find(Follow::query()->value('followed_id'))))->toBeNull();

    // Another reader has an allowance of their own.
    expect(followError(User::factory()->create(), $extra))->toBeNull();

    $this->travel(61)->minutes();
    expect(followError($reader, $extra))->toBeNull();
});

test('the follows limiter is named and is 60 an hour per reader', function () {
    $reader = User::factory()->create();
    $limit = RateLimiter::limiter('follows')($reader);

    expect($limit->maxAttempts)->toBe(60)->and($limit->decaySeconds)->toBe(3600)->and($limit->key)->toBe($reader->id);
});

test('follows are deleted with either user', function () {
    $a = followable('user_a');
    $b = followable('user_b');
    $c = followable('user_c');
    app(FollowUser::class)->handle($a, $b);
    app(FollowUser::class)->handle($c, $a);

    $a->delete();

    expect(Follow::query()->count())->toBe(0);
});

test('existing follows of a profile that went private grant nothing', function () {
    $follower = User::factory()->create();
    $target = followable('target_one');
    $target->profile()->update(['library_visibility' => Visibility::Public]);
    app(FollowUser::class)->handle($follower, $target);

    $target->profile()->update(['profile_visibility' => Visibility::Private]);
    $target = $target->fresh();

    expect(Follow::query()->count())->toBe(1)
        ->and(Gate::forUser($follower)->allows('view-profile', $target))->toBeFalse()
        ->and(Gate::forUser($follower)->allows('view-library', $target))->toBeFalse()
        ->and($follower->listedFollowing()->count())->toBe(0);
});
