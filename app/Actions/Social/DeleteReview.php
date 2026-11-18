<?php

namespace App\Actions\Social;

use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Delete a reader's own review (soft, so the story can be reviewed again). Only the author may; anyone else gets an AuthorizationException.
 */
class DeleteReview
{
    public function handle(User $user, Review $review): void
    {
        Gate::forUser($user)->authorize('delete', $review);

        $review->delete();

        // 10.5 seam: the story's review summary is invalidated here (a ReviewSummaryInvalidated call), as it is where a review is hidden.
    }
}
