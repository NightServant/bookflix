<?php

namespace App\Actions\Social;

use App\Enums\ReviewState;
use App\Models\Collection;
use App\Models\Profile;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use App\Social\ReviewSummaries;
use Illuminate\Support\Facades\DB;

/**
 * A moderator undoes a hide (P10-R10, beyond the plan so a mistake can be put right): a review is published again; a collection or profile loses
 * `moderation_hidden_at` but stays private, for its owner to choose to share again. Admin only.
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
            } elseif ($target instanceof Collection || $target instanceof Profile) {
                $target->forceFill(['moderation_hidden_at' => null])->save();
            }

            $this->markHandled($report, $moderator, $report->status);
        });

        return $report;
    }
}
