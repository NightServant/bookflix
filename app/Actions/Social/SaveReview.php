<?php

namespace App\Actions\Social;

use App\Discovery\Recommendations\InteractionRecorder;
use App\Enums\InteractionEvent;
use App\Models\Media;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Write or edit a reader's review of a story (P10-R7). A reader has one live review per story: saving again edits it, and a deleted one is written anew
 * (as published). Editing a hidden review keeps it hidden. Refused as a ValidationException: an admin or deactivated account, a story behind the mature gate
 * the reader hasn't opened, an empty or over-long body (5,000 characters after trimming), and more than 10 saves an hour (the `reviews` limiter, counted here
 * so Livewire calls count too). The review interaction is recorded once, on creation, and only for a reader who allows personalization.
 */
class SaveReview
{
    public const MAX_BODY = 5000;

    public function __construct(private readonly InteractionRecorder $interactions) {}

    public function handle(User $user, Media $media, string $body, bool $hasSpoilers): Review
    {
        if ($user->is_admin || $user->isDeactivated() || $media->isGatedFor($user)) {
            throw ValidationException::withMessages(['review' => __('You can’t review this story.')]);
        }

        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => __('Write something before saving your review.')]);
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            throw ValidationException::withMessages(['body' => __('A review can be at most :max characters.', ['max' => self::MAX_BODY])]);
        }

        $limit = RateLimiter::limiter('reviews')($user);
        $key = 'reviews:'.$limit->key;

        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            throw ValidationException::withMessages(['review' => __('You are posting reviews too fast. Try again later.')]);
        }

        RateLimiter::hit($key, $limit->decaySeconds);

        $review = $this->live($user, $media);

        if ($review === null) {
            try {
                $review = Review::query()->create(['user_id' => $user->id, 'media_id' => $media->id, 'body' => $body, 'has_spoilers' => $hasSpoilers]);
                $this->interactions->record($user, InteractionEvent::Review, $media);

                return $review;
            } catch (UniqueConstraintViolationException) {
                // Two quick saves raced to create it; the other one won, so this one edits.
                $review = $this->live($user, $media) ?? throw ValidationException::withMessages(['review' => __('Your review could not be saved.')]);
            }
        }

        $review->fill(['body' => $body, 'has_spoilers' => $hasSpoilers])->save();

        return $review;
    }

    private function live(User $user, Media $media): ?Review
    {
        return Review::query()->where('user_id', $user->id)->where('media_id', $media->id)->first();
    }
}
