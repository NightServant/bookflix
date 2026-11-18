<?php

namespace App\Actions\Social;

use App\Models\Follow;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Follow a reader (P10-R6). Idempotent: following twice leaves one row and costs nothing. Refused, as a ValidationException on `follow`: yourself,
 * and anyone whose profile the follower may not see (an admin, a deactivated reader, a private or moderator-hidden profile), so nothing says which.
 * An admin has no reader side and follows no one. A reader follows at most 60 an hour (the `follows` limiter, counted here so Livewire calls count too).
 */
class FollowUser
{
    public function handle(User $follower, User $target): void
    {
        if ($follower->is_admin || $follower->isDeactivated() || $follower->is($target)) {
            throw ValidationException::withMessages(['follow' => __('You can’t follow this reader.')]);
        }

        if (Gate::forUser($follower)->denies('view-profile', $target)) {
            throw ValidationException::withMessages(['follow' => __('You can’t follow this reader.')]);
        }

        if (Follow::query()->where('follower_id', $follower->id)->where('followed_id', $target->id)->exists()) {
            return;
        }

        $limit = RateLimiter::limiter('follows')($follower);
        $key = 'follows:'.$limit->key;

        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            throw ValidationException::withMessages(['follow' => __('You are following people too fast. Try again later.')]);
        }

        RateLimiter::hit($key, $limit->decaySeconds);

        // Two quick taps race to the same row; the key keeps it to one.
        Follow::query()->insertOrIgnore(['follower_id' => $follower->id, 'followed_id' => $target->id, 'created_at' => now()]);
    }
}
