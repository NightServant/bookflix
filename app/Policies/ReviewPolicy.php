<?php

namespace App\Policies;

use App\Enums\ReviewState;
use App\Models\Review;
use App\Models\User;

/**
 * Anyone may view a published review while its author is an active reader; the author always sees their own (a hidden one comes with a notice).
 * Only the author edits or deletes it: a moderator hides it through the moderation actions instead.
 */
class ReviewPolicy
{
    public function view(?User $viewer, Review $review): bool
    {
        if ($viewer?->id === $review->user_id) {
            return true;
        }

        $author = $review->loadMissing('user')->user;

        return $review->status === ReviewState::Published && ! $author->isDeactivated() && ! $author->is_admin;
    }

    public function update(User $user, Review $review): bool
    {
        return $user->id === $review->user_id;
    }

    public function delete(User $user, Review $review): bool
    {
        return $this->update($user, $review);
    }
}
