<?php

namespace App\Actions\Social;

use App\Models\Review;
use App\Models\User;
use App\Social\ReviewSummaries;
use Illuminate\Support\Facades\Gate;

/**
 * Delete a reader's own review (soft, so the story can be reviewed again). Only the author may; anyone else gets an AuthorizationException.
 */
class DeleteReview
{
    public function __construct(private readonly ReviewSummaries $summaries) {}

    public function handle(User $user, Review $review): void
    {
        Gate::forUser($user)->authorize('delete', $review);

        $review->delete();

        $this->summaries->invalidate($review->media_id);
    }
}
