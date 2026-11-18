<?php

namespace App\Actions\Social;

use App\Enums\ReportStatus;
use App\Enums\ReviewState;
use App\Enums\Visibility;
use App\Models\Collection;
use App\Models\Profile;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A moderator hides what a report points at (P10-R10): a review becomes hidden; a collection or profile becomes private and is stamped
 * `moderation_hidden_at`, so its owner can't make it public again until it is restored. The report and every other open report of the same target
 * become actioned. A target deleted since can't be hidden (the report can be dismissed). Admin only.
 */
class HideReportedContent
{
    use ModeratesReports;

    public function handle(User $moderator, Report $report): Report
    {
        $this->authorizeModerator($moderator);

        DB::transaction(function () use ($moderator, $report): void {
            $target = $this->targetOf($report);

            if ($target instanceof Review) {
                $target->forceFill(['status' => ReviewState::Hidden])->save();
                // 10.5 seam: the story's review summary is invalidated here (a ReviewSummaryInvalidated call).
            } elseif ($target instanceof Collection) {
                $target->forceFill(['visibility' => Visibility::Private, 'moderation_hidden_at' => now()])->save();
            } elseif ($target instanceof Profile) {
                $target->forceFill(['profile_visibility' => Visibility::Private, 'moderation_hidden_at' => now()])->save();
            }

            Report::query()
                ->where('reportable_type', $report->reportable_type)
                ->where('reportable_id', $report->reportable_id)
                ->where('status', ReportStatus::Open->value)
                ->where('id', '!=', $report->id)
                ->update(['status' => ReportStatus::Actioned->value, 'handled_by' => $moderator->id, 'handled_at' => now()]);

            $this->markHandled($report, $moderator, ReportStatus::Actioned);
        });

        return $report;
    }
}
