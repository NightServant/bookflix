<?php

namespace App\Actions\Ratings;

use App\Discovery\Recommendations\InteractionRecorder;
use App\Enums\InteractionEvent;
use App\Events\LibraryChanged;
use App\Jobs\RecalculateMediaRating;
use App\Models\Media;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Rate a story, or take the rating back. A score is a whole number from 1 to 10 (D-14), one per reader and story, saved over the last one. Rating
 * does not put the story in the library (that is the reader's call), and the story's own rating is not touched here: the aggregate job folds the
 * active readers' scores into it. The caller decides who the reader is and whether they may see the story.
 *
 * Only a score that changed does anything beyond the save: saving the same one again (a double click) records nothing and queues no new aggregate
 * run, since the aggregate already holds it. An opted-out reader keeps the rating and records no interaction (see InteractionRecorder).
 */
class RateMedia
{
    public function __construct(private readonly InteractionRecorder $interactions) {}

    /**
     * @throws ValidationException when the score is not a whole number from 1 to 10
     */
    public function handle(User $user, Media $media, int $score): Rating
    {
        if ($score < 1 || $score > 10) {
            throw ValidationException::withMessages(['score' => __('Choose a rating from 1 to 10.')]);
        }

        $previous = Rating::where('user_id', $user->id)->where('media_id', $media->id)->value('score');

        // One statement, so two clicks racing for the same new row cannot collide on the key.
        Rating::upsert(
            [['user_id' => $user->id, 'media_id' => $media->id, 'score' => $score, 'created_at' => now(), 'updated_at' => now()]],
            ['user_id', 'media_id'],
            ['score', 'updated_at'],
        );

        if ($previous !== $score) {
            RecalculateMediaRating::dispatch($media->id)->afterCommit();
            $this->interactions->record($user, InteractionEvent::Rating, $media, value: (float) $score);
            LibraryChanged::dispatch($user, $media);
        }

        return Rating::where('user_id', $user->id)->where('media_id', $media->id)->firstOrFail();
    }

    /**
     * Take the reader's rating back. Taking back one that is not there changes nothing.
     */
    public function forget(User $user, Media $media): void
    {
        $removed = Rating::where('user_id', $user->id)->where('media_id', $media->id)->delete();

        if ($removed === 0) {
            return;
        }

        RecalculateMediaRating::dispatch($media->id)->afterCommit();
        LibraryChanged::dispatch($user, $media);
    }
}
