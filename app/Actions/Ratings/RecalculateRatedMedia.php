<?php

namespace App\Actions\Ratings;

use App\Jobs\RecalculateMediaRating;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Refresh the rating of every story one reader has scored. A deactivated reader counts for nothing in a story's rating (see RecalculateMediaRating),
 * so the stories they rated must be recalculated when they are deactivated, when they come back, and when they are purged; none of those rewrites
 * their scores, so nothing else would. One job per story, queued a chunk of the reader's ratings at a time so a reader with thousands of scores
 * never loads them all.
 *
 * Call it once the reader's state has been saved. A purge calls it before the delete: the reader is already deactivated, so the job leaves them
 * out whether their scores are still there or already gone with the account.
 */
class RecalculateRatedMedia
{
    public function handle(User $user, int $chunk = 500): void
    {
        DB::table('ratings')->where('user_id', $user->id)->chunkById($chunk, function ($ratings): void {
            foreach ($ratings as $rating) {
                RecalculateMediaRating::dispatch($rating->media_id)->afterCommit();
            }
        }, 'media_id');
    }
}
