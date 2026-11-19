<?php

namespace App\Actions\Social;

use App\Enums\ReviewState;
use App\Models\ExternalReview;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use App\Social\ReviewSummaries;
use Illuminate\Support\Facades\DB;

/**
 * A moderator undoes a hide (P10-R10, beyond the plan so a mistake can be put right): a review is published again. Admin only.
 */
class RestoreContent
{
    use ModeratesReports;

    public function __construct(private readonly ReviewSummaries $summaries) {}

    public function handle(User $moderator, Report $report): Report
    {
        $this->authorizeModerator($moderator);

        DB::transaction(function () use ($moderator, $report): void {
            $target = $this->targetOf($report);

            if ($target instanceof Review) {
                $target->forceFill(['status' => ReviewState::Published])->save();
                $this->summaries->queue($target->media_id);
            }

            if ($target instanceof ExternalReview) {
                $target->forceFill(['hidden_at' => null, 'hidden_by' => null])->save();
            }

            $this->markHandled($report, $moderator, $report->status);
        });

        return $report;
    }
}
