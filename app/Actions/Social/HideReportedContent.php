<?php

namespace App\Actions\Social;

use App\Enums\ReportStatus;
use App\Enums\ReviewState;
use App\Models\ExternalReview;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use App\Social\ReviewSummaries;
use Illuminate\Support\Facades\DB;

/**
 * A moderator hides a reported review (P10-R10). The report and every other open report of the same target become actioned. A target deleted since
 * can't be hidden (the report can be dismissed). Admin only. Outside reviews are one more branch here when they become reportable.
 */
class HideReportedContent
{
    use ModeratesReports;

    public function __construct(private readonly ReviewSummaries $summaries) {}

    public function handle(User $moderator, Report $report): Report
    {
        $this->authorizeModerator($moderator);

        DB::transaction(function () use ($moderator, $report): void {
            $target = $this->targetOf($report);

            if ($target instanceof Review) {
                $target->forceFill(['status' => ReviewState::Hidden])->save();
                $this->summaries->invalidate($target->media_id);
            }

            if ($target instanceof ExternalReview) {
                $target->forceFill(['hidden_at' => now(), 'hidden_by' => $moderator->id])->save();
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
