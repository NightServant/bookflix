<?php

namespace App\Actions\Social;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Collection;
use App\Models\Profile;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Report a review, a public collection or a viewable profile (P10-R9). The reporter must be an active reader who can see the target, and it must not be theirs.
 * A staff pick (an admin made it) can't be reported, and a review is reportable only while published. Every refusal is the same ValidationException on `report`,
 * so nothing says why. A second open report of the same target returns the first. Reports never hide anything by themselves; only a moderator acts on one.
 * A reader files at most 10 an hour (the `reports` limiter, counted here so Livewire calls count too).
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
        $gate = Gate::forUser($reporter);

        if ($target instanceof Review) {
            return $target->exists && ! $target->trashed()
                && $target->user_id !== $reporter->id
                && $gate->allows('view', $target)
                && $target->status->value === 'published'
                && ! $target->loadMissing('media')->media->isGatedFor($reporter);
        }

        if ($target instanceof Collection) {
            return $target->exists
                && $target->user_id !== $reporter->id
                && ! $target->is_editorial
                && $target->visibility->value === 'public'
                && $target->moderation_hidden_at === null
                && $gate->allows('view', $target);
        }

        if ($target instanceof Profile) {
            $owner = $target->loadMissing('user')->user;
            $owner->setRelation('profile', $target);

            return $target->exists
                && $target->user_id !== $reporter->id
                && $gate->allows('view-profile', $owner);
        }

        return false;
    }
}
