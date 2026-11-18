<?php

namespace App\Social;

use App\Jobs\SummarizeReviews;
use App\Models\Media;
use App\Models\Review;
use App\Models\ReviewSummary;
use App\Models\User;

/**
 * The two ways the review summary of a story is touched (P10-R11): `queue` asks for a rebuild after the configured delay (a burst of changes makes
 * one job, as the job is unique while it waits), and `invalidate` also deletes the stored summary at once, for when text that must no longer be
 * shown (a hidden or deleted review, a deactivated author) may be in it.
 */
class ReviewSummaries
{
    public function queue(Media|int $media): void
    {
        SummarizeReviews::dispatch($this->id($media))->delay(now()->addMinutes((int) config('bookflix.social.review_summary_delay_minutes')));
    }

    public function invalidate(Media|int $media): void
    {
        $id = $this->id($media);

        ReviewSummary::query()->whereKey($id)->delete();
        $this->queue($id);
    }

    /**
     * Invalidate the summary of every story whose summary may hold this reader's words: those they have a published, non-spoiler review of.
     */
    public function invalidateForAuthor(User $user): void
    {
        Review::query()->where('user_id', $user->id)->where('status', 'published')->where('has_spoilers', false)
            ->pluck('media_id')->unique()->each(fn (int $mediaId) => $this->invalidate($mediaId));
    }

    private function id(Media|int $media): int
    {
        return $media instanceof Media ? $media->id : $media;
    }
}
