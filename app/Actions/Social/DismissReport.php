<?php

namespace App\Actions\Social;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;

/**
 * A moderator finds nothing to act on (P10-R10): this report is dismissed and the content stays as it is. Other reports of the same target are left to
 * be judged on their own. Admin only.
 */
class DismissReport
{
    use ModeratesReports;

    public function handle(User $moderator, Report $report): Report
    {
        $this->authorizeModerator($moderator);

        $this->markHandled($report, $moderator, ReportStatus::Dismissed);

        return $report;
    }
}
