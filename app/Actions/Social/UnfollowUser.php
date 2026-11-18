<?php

namespace App\Actions\Social;

use App\Models\Follow;
use App\Models\User;

/**
 * Stop following a reader. Idempotent and always allowed, whatever has happened to the other profile since.
 */
class UnfollowUser
{
    public function handle(User $follower, User $target): void
    {
        Follow::query()->where('follower_id', $follower->id)->where('followed_id', $target->id)->delete();
    }
}
