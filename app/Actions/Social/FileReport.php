<?php

namespace App\Actions\Social;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Report a review (P10-R9). The reporter must be an active reader who can see the target, and it must not be theirs. A review is reportable only while
 * published and while its story is live and visible to the reporter. Every refusal is the same ValidationException on `report`, so nothing says why.
 * A second open report of the same target returns the first. Reports never hide anything by themselves; only a moderator acts on one.
 * A reader files at most 10 an hour (the `reports` limiter, counted here so Livewire calls count too).
 *
 * Seam for outside reviews: a new target kind is one more branch in `reportable()` (and in the moderation actions), plus its morph map name.
 */
class FileReport
{
    public const MAX_DETAILS = 1000;

    public function handle(User $reporter, Model $target, ReportReason $reason, ?string $details): Report
    {
        if ($reporter->is_admin || $reporter->isDeactivated() || ! $this->reportable($reporter, $target)) {
            throw ValidationException::withMessages(['report' => __('You can’t report this.')]);
        }

        $details = trim((string) $details);

        if (mb_strlen($details) > self::MAX_DETAILS) {
            throw ValidationException::withMessages(['details' => __('Details can be at most :max characters.', ['max' => self::MAX_DETAILS])]);
        }

        if (($existing = $this->open($reporter, $target)) !== null) {
            return $existing;
        }

        $limit = RateLimiter::limiter('reports')($reporter);
        $key = 'reports:'.$limit->key;

        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            throw ValidationException::withMessages(['report' => __('You are reporting too fast. Try again later.')]);
        }

        RateLimiter::hit($key, $limit->decaySeconds);

        try {
            $report = new Report(['reporter_id' => $reporter->id, 'reason' => $reason, 'details' => $details === '' ? null : $details]);
            $report->forceFill(['reportable_type' => $target->getMorphClass(), 'reportable_id' => $target->getKey()])->save();

            return $report;
        } catch (UniqueConstraintViolationException) {
            // Two quick taps raced to the same report; the key keeps it to one.
            return $this->open($reporter, $target) ?? throw ValidationException::withMessages(['report' => __('You can’t report this.')]);
        }
    }

    /**
     * @phpstan-impure
     */
    private function open(User $reporter, Model $target): ?Report
    {
        return Report::query()
            ->where('reporter_id', $reporter->id)
            ->where('reportable_type', $target->getMorphClass())
            ->where('reportable_id', $target->getKey())
            ->where('status', ReportStatus::Open->value)
            ->first();
    }

    private function reportable(User $reporter, Model $target): bool
    {
        if ($target instanceof Review) {
            return $this->reportableReview($reporter, $target);
        }

        return false;
    }

    private function reportableReview(User $reporter, Review $review): bool
    {
        if (! $review->exists || $review->trashed() || $review->user_id === $reporter->id) {
            return false;
        }

        // A deleted or merged story has no page to report from (the soft-delete scope makes `media` null for a deleted one).
        $media = $review->loadMissing('media')->media;

        return $media !== null
            && $media->merged_into_id === null
            && $reporter->can('view', $review)
            && $review->status->value === 'published'
            && ! $media->isGatedFor($reporter);
    }
}
