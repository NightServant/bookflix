<?php

namespace App\Actions\Social;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * What the moderation actions share (P10-R10): only an admin acts (the check is inside each action, so a Livewire call is covered too), and every
 * action records who handled the report and when.
 */
trait ModeratesReports
{
    private function authorizeModerator(User $user): void
    {
        Gate::forUser($user)->authorize('access-admin');
    }

    /**
     * The report's target, or a refusal when it has been deleted since (the report can still be dismissed).
     */
    private function targetOf(Report $report): mixed
    {
        return $report->reportable ?? throw ValidationException::withMessages(['report' => __('The reported content has been removed.')]);
    }

    private function markHandled(Report $report, User $moderator, ReportStatus $status): void
    {
        $report->forceFill(['status' => $status, 'handled_by' => $moderator->id, 'handled_at' => now()])->save();
    }
}
