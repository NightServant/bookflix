<?php

namespace App\Actions\Social;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A moderator finds nothing to act on (P10-R10): this report and every other open report of the same target are dismissed, and the content stays as
 * it is. A report filed later opens a new card. Admin only.
 */
class DismissReport
{
    use ModeratesReports;

    public function handle(User $moderator, Report $report): Report
    {
        $this->authorizeModerator($moderator);

        // Admin, Reports shows one card per reported thing, so dismissing it closes every open report about it, as hiding does.
        DB::transaction(function () use ($moderator, $report): void {
            Report::query()
                ->where('reportable_type', $report->reportable_type)
                ->where('reportable_id', $report->reportable_id)
                ->where('status', ReportStatus::Open->value)
                ->where('id', '!=', $report->id)
                ->update(['status' => ReportStatus::Dismissed->value, 'handled_by' => $moderator->id, 'handled_at' => now()]);

            $this->markHandled($report, $moderator, ReportStatus::Dismissed);
        });

        return $report;
    }
}
